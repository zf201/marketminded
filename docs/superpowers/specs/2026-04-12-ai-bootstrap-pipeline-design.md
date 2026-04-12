# AI Bootstrap Pipeline — Design Spec

## Context

This is **sub-project 2 of 2** for Brand Intelligence. Sub-project 1 built the page, data model, and CRUD UI. This sub-project wires the "Generate Brand Intelligence" button to three sequential AI agents that call OpenRouter to populate the brand_positionings, audience_personas, and voice_profiles tables.

## What This Builds

A background job that runs three AI agents sequentially via OpenRouter to generate brand intelligence. The user sees per-agent progress via Livewire polling. Pre-fetches key URLs before the AI runs, and gives the AI a `fetch_url` tool for additional fetches. Retries up to 3 times with exponential backoff on failures.

## Architecture

```
User clicks "Generate Brand Intelligence"
  → Livewire dispatches GenerateBrandIntelligenceJob
  → Job updates team.intelligence_status as it progresses
  → Livewire polls every 5s, renders per-agent progress
  → Job runs:
    1. Pre-fetch homepage, product, blog, style reference URLs
    2. PositioningAgent → saves to brand_positionings
    3. PersonaAgent (uses positioning as context) → saves to audience_personas
    4. VoiceProfileAgent (uses positioning as context) → saves to voice_profiles
    5. Sets status to 'completed'
```

## Team Table Additions

Two new columns on `teams`:

| Column | Type | Default | Purpose |
|---|---|---|---|
| `intelligence_status` | string(50) | null | null / fetching / positioning / personas / voice_profile / completed / failed |
| `intelligence_error` | text | null | Error message if failed |

## Service Classes

### `App\Services\OpenRouterClient`

HTTP client for OpenRouter's chat completions API.

**Constructor:** `__construct(string $apiKey, string $model)`

**Method:** `chat(array $messages, array $tools = [], ?string $toolChoice = null, float $temperature = 0.3): array`

- Endpoint: `POST https://openrouter.ai/api/v1/chat/completions`
- Auth: `Authorization: Bearer {apiKey}`
- Request body: `model`, `messages`, `tools`, `tool_choice`, `temperature`, `stream: false`
- Handles multi-turn tool loop: if the model returns `tool_calls`, executes client-side tools (fetch_url), feeds results back, continues until the model returns content or a submit_* tool is called (max 20 iterations)
- Server-side tools (`openrouter:datetime`, `openrouter:web_search`) are handled by OpenRouter automatically — no client-side execution needed
- **Retries:** 3 attempts with exponential backoff (1s, 2s, 4s) on HTTP 429 (rate limit) and 5xx (server errors). No retry on 4xx client errors (bad request, auth failure)
- Returns: the final tool call arguments (parsed JSON) when a submit_* tool is called, or the assistant message content if no submit tool

**Server tools included in every request:**
```php
['type' => 'openrouter:datetime'],
['type' => 'openrouter:web_search', 'parameters' => ['max_results' => 5]],
```

### `App\Services\UrlFetcher`

Crawls URLs and extracts clean text content.

**Method:** `fetch(string $url): string`

- HTTP GET with Chrome User-Agent, 10s timeout
- Parses HTML, removes: `<head>`, `<script>`, `<style>`, `<nav>`, `<footer>`, `<img>`, `<video>`, `<svg>`
- Strips all attributes except `href`
- Returns: `"Title: {page title}\n\n{cleaned text}"` (truncated to 12KB)
- On failure: returns `"Error fetching {url}: {message}"`

**Method:** `fetchMany(array $urls): array`

- Fetches multiple URLs, returns `['url' => 'content']` map
- Skips empty URLs

### `App\Services\Agents\PositioningAgent`

Generates brand positioning from URLs and brand info.

**Method:** `generate(Team $team, array $fetchedContent): BrandPositioning`

**System prompt** (adapted from Go app):
- "You are an expert content marketing strategist. Analyze the brand and produce structured positioning."
- Includes fetched URL content as context
- Includes brand description, target audience, tone keywords from brand setup
- Uses `openrouter:datetime` for date awareness (replaces manual date injection from Go app)
- Uses `openrouter:web_search` for additional research
- Has `fetch_url` tool for additional URL fetches
- Anti-AI writing rules (no em-dashes, banned buzzwords, natural prose)

**Submit tool:** `submit_positioning` with 6 fields:
```json
{
  "value_proposition": "string",
  "target_market": "string", 
  "differentiators": "string",
  "core_problems": "string",
  "products_services": "string",
  "primary_cta": "string"
}
```

**Saves:** Updates or creates `brand_positionings` row for the team.

### `App\Services\Agents\PersonaAgent`

Generates audience personas based on positioning and brand info.

**Method:** `generate(Team $team, BrandPositioning $positioning, array $fetchedContent): Collection`

**System prompt** (adapted from Go app):
- "You are an expert content marketing strategist building audience personas."
- Includes positioning output as context
- Includes brand description, target audience hints from brand setup
- Uses `openrouter:web_search` for market research
- Uses `openrouter:datetime`
- Has `fetch_url` tool

**Submit tool:** `submit_personas` with array of persona objects:
```json
{
  "personas": [
    {
      "label": "string (required)",
      "description": "string",
      "pain_points": "string",
      "push": "string",
      "pull": "string",
      "anxiety": "string",
      "role": "string"
    }
  ]
}
```

Note: simplified from Go's 14 fields to our 7 fields. No status/id tracking — this is a full rebuild, replacing all existing personas.

**Saves:** Deletes existing personas for the team, creates new ones with sort_order.

### `App\Services\Agents\VoiceProfileAgent`

Generates voice and tone profile from URL analysis.

**Method:** `generate(Team $team, BrandPositioning $positioning, array $fetchedContent): VoiceProfile`

**System prompt** (adapted from Go app):
- "You are an expert brand voice analyst building a structured voice & tone profile."
- Includes positioning output as context
- Includes fetched content from blog URLs, style reference URLs
- Analyzes writing patterns: style, sentence structure, vocabulary, formatting
- Uses `openrouter:datetime`
- Has `fetch_url` tool for fetching individual blog posts from listing pages

**Submit tool:** `submit_voice_profile` with 6 fields:
```json
{
  "voice_analysis": "string",
  "content_types": "string",
  "should_avoid": "string",
  "should_use": "string",
  "style_inspiration": "string",
  "preferred_length": "integer (default 1500)"
}
```

Note: no storytelling_frameworks — dropped from our model.

**Saves:** Updates or creates `voice_profiles` row for the team.

## Job

### `App\Jobs\GenerateBrandIntelligenceJob`

- Implements `ShouldQueue`, `ShouldBeUnique`
- Unique key: `team:{team_id}`
- Queue: `default`
- Timeout: 300 seconds (5 minutes)
- Max tries: 1 (retries are handled internally by OpenRouterClient)

**Steps:**
1. Set `intelligence_status = 'fetching'`, clear `intelligence_error`
2. Pre-fetch URLs via UrlFetcher: homepage_url, product_urls (array), blog_url, style_reference_urls (array)
3. Set `intelligence_status = 'positioning'`
4. Run PositioningAgent with fetched content
5. Set `intelligence_status = 'personas'`
6. Run PersonaAgent with positioning result + fetched content
7. Set `intelligence_status = 'voice_profile'`
8. Run VoiceProfileAgent with positioning result + fetched content
9. Set `intelligence_status = 'completed'`, `intelligence_error = null`

**On failure:**
- Set `intelligence_status = 'failed'`
- Set `intelligence_error = $exception->getMessage()`

**Data replacement:** Before saving each agent's output, delete existing data for that section (e.g., delete all personas before creating new ones). This ensures a clean regeneration.

## UI Changes

### Brand Intelligence Livewire Component

**New method:** `startGeneration()`
- Gate::authorize('update', $this->teamModel)
- Dispatches GenerateBrandIntelligenceJob
- Sets initial `intelligence_status = 'pending'`

**Polling:** Add `wire:poll.5s` to the progress section when status is active (not null, not completed, not failed)

**Progress display:** Replace bootstrap CTA with a progress card:

```
Fetching URLs...           ✓ (when status > 'fetching')
Analyzing positioning...   ✓ (when status > 'positioning')  
Building personas...       ✓ (when status > 'personas')
Defining voice profile...  ● (when status = 'voice_profile', spinning)
```

**Error state:** If `intelligence_status = 'failed'`, show error callout with `intelligence_error` and a "Retry" button that calls `startGeneration()` again.

**Completion:** When status = 'completed', reload data and show the three sections (already built in sub-project 1). Reset status to null after loading.

**Regenerate buttons:** All "Regenerate" buttons (per-section and "Regenerate all") call `startGeneration()` — full pipeline regeneration for v1.

## File Structure

### New Files

```
database/migrations/XXXX_add_intelligence_status_to_teams_table.php
app/Services/OpenRouterClient.php
app/Services/UrlFetcher.php
app/Services/Agents/PositioningAgent.php
app/Services/Agents/PersonaAgent.php
app/Services/Agents/VoiceProfileAgent.php
app/Jobs/GenerateBrandIntelligenceJob.php
tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php
tests/Unit/Services/OpenRouterClientTest.php
tests/Unit/Services/UrlFetcherTest.php
```

### Modified Files

```
app/Models/Team.php                                              — Add intelligence_status, intelligence_error to fillable
resources/views/pages/teams/⚡brand-intelligence.blade.php       — Add polling, progress UI, wire up Generate button
```

## Testing Strategy

**Unit tests:**
- `OpenRouterClientTest` — mock HTTP responses, test retry logic, test tool loop
- `UrlFetcherTest` — mock HTTP responses, test HTML cleaning, test truncation

**Feature tests:**
- `GenerateBrandIntelligenceTest`:
  - Job dispatches when Generate is clicked
  - Job updates status through each phase
  - Job saves positioning, personas, voice profile to DB
  - Job handles failure gracefully (sets failed status)
  - Member cannot trigger generation
  - Duplicate job prevention (ShouldBeUnique)

**NOT tested (requires real API):**
- Actual OpenRouter API calls
- Actual URL fetching
- Prompt quality / AI output quality

## What's NOT in Scope

- Individual section regeneration (all Regenerate buttons trigger full pipeline)
- SSE streaming to browser (background job with polling instead)
- Prompt engineering/tuning (port Go prompts as-is)
- Content generation pipeline (research, writing — separate future feature)
- API key validation before running (we check existence, not validity)
