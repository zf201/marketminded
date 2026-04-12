# Brand Intelligence Consolidation + Chat Tool-Calling — Design Spec

## Overview

Merge Brand Setup and Brand Intelligence into one "Brand Intelligence" page. Make the chat the primary way to populate brand data by adding tool-calling to the streaming chat. Three distinct chat types with separate system prompts replace the current quick actions.

## Part 1: Merged Brand Intelligence Page

### What changes

- **Brand Setup page removed** — route, sidebar item, and Volt component deleted
- **Brand Intelligence page** absorbs all brand setup fields
- **Sidebar** keeps "Brand Intelligence" item, removes "Brand Setup"
- **"Generate Brand Intelligence" button removed** — replaced by the chat workflow

### Page layout

Single page at `/{team}/intelligence` showing all brand data in read/edit sections using the existing two-column pattern (`w-80` label + `flex-1 space-y-6` content). All sections visible whether populated or not.

**Sections (in order):**

1. **Company** — homepage_url, blog_url, brand_description, product_urls, competitor_urls, style_reference_urls, target_audience, tone_keywords, content_language. Inline edit form.

2. **Positioning** — value_proposition, target_market, differentiators, core_problems, products_services, primary_cta. Inline edit or empty state.

3. **Audience Personas** — card list with add/edit/delete modals. Empty state when none.

4. **Voice & Tone** — voice_analysis, content_types, should_avoid, should_use, style_inspiration, preferred_length. Inline edit or empty state.

Empty sections show a subtle empty state: "No positioning data yet — use the Create chat to build your brand profile" with a link to the Create page.

### No changes to data model

All existing tables and models stay the same. The page just merges two views into one.

## Part 2: Chat Types

The three quick action cards become distinct chat types. Each type has its own system prompt, available tools, and behavior. The composer input does NOT appear until a type is selected.

### Flow

1. User clicks "New conversation" → lands on `/create/{conversation}`
2. Sees 3 type cards, no input field
3. Clicks a type → type is saved to the conversation record, system prompt and tools activate, input appears
4. Conversation proceeds with type-specific behavior

### Database change

Add `type` column to `conversations` table:

```
$table->string('type', 30)->nullable();
```

Nullable because existing conversations have no type. Values: `brand`, `topics`, `write`.

### Chat type definitions

**1. Build brand knowledge** (`brand`)
- **System prompt:** Focused on brand discovery. Instructs the AI to ask probing questions about the business, analyze URLs, and save structured data back to the profile. Includes the current brand profile as JSON context.
- **Tools:** `update_brand_intelligence`, `fetch_url`, OpenRouter web search (native)
- **Behavior:** AI asks questions, fetches URLs, writes findings to the brand profile

**2. Brainstorm topics** (`topics`)
- **System prompt:** Injected with full brand profile. Focused on generating content ideas that align with positioning and target personas. If the profile is thin (no positioning or personas), the AI should say: "I don't have enough brand context yet to give you strong topic ideas. I'd recommend starting with 'Build brand knowledge' first to establish your positioning and audience."
- **Tools:** OpenRouter web search (native) — for trend research
- **No brand update tool** — this is read-only relative to the profile

**3. Write content** (`write`)
- **System prompt:** Injected with full brand profile + voice profile. Focused on drafting content that matches the brand voice. Same thin-profile nudge as topics.
- **Tools:** OpenRouter web search (native) — for fact-checking/research
- **No brand update tool** — read-only

## Part 3: Tool-Calling in Streaming Chat

### OpenRouterClient changes

Add a `streamChatWithTools()` method (or extend `streamChat()`). When streaming with tools:

1. Stream chunks arrive as normal
2. If the model emits `tool_calls` in deltas, accumulate them
3. When tool call is complete, pause streaming:
   - Yield a structured event (not a text chunk) indicating which tool is running
   - Execute the tool server-side
   - For `fetch_url`: yield the URL being fetched, then the result
   - For `update_brand_intelligence`: execute the update, yield confirmation
   - Send tool result back to the API
4. Resume streaming with the next response

The method yields a union of types:
- `string` — text content chunk
- `ToolEvent` — tool execution status (name, arguments, result)
- `StreamResult` — final usage stats (last yield)

### ToolEvent DTO

```php
class ToolEvent
{
    public function __construct(
        public readonly string $name,      // 'fetch_url', 'update_brand_intelligence'
        public readonly array $arguments,  // tool arguments
        public readonly ?string $result,   // tool result (null while executing)
        public readonly string $status,    // 'started', 'completed'
    ) {}
}
```

### update_brand_intelligence tool

**Schema passed to OpenRouter:**

```json
{
  "type": "function",
  "function": {
    "name": "update_brand_intelligence",
    "description": "Update the brand intelligence profile. All sections and fields are optional — only include what you want to change. When updating personas, provide the full list (replaces existing).",
    "parameters": {
      "type": "object",
      "properties": {
        "setup": {
          "type": "object",
          "properties": {
            "homepage_url": { "type": "string" },
            "blog_url": { "type": "string" },
            "brand_description": { "type": "string" },
            "product_urls": { "type": "array", "items": { "type": "string" } },
            "competitor_urls": { "type": "array", "items": { "type": "string" } },
            "style_reference_urls": { "type": "array", "items": { "type": "string" } },
            "target_audience": { "type": "string" },
            "tone_keywords": { "type": "string" },
            "content_language": { "type": "string" }
          }
        },
        "positioning": {
          "type": "object",
          "properties": {
            "value_proposition": { "type": "string" },
            "target_market": { "type": "string" },
            "differentiators": { "type": "string" },
            "core_problems": { "type": "string" },
            "products_services": { "type": "string" },
            "primary_cta": { "type": "string" }
          }
        },
        "personas": {
          "type": "array",
          "description": "Full list of audience personas. Replaces all existing personas.",
          "items": {
            "type": "object",
            "required": ["label"],
            "properties": {
              "label": { "type": "string" },
              "role": { "type": "string" },
              "description": { "type": "string" },
              "pain_points": { "type": "string" },
              "push": { "type": "string" },
              "pull": { "type": "string" },
              "anxiety": { "type": "string" }
            }
          }
        },
        "voice": {
          "type": "object",
          "properties": {
            "voice_analysis": { "type": "string" },
            "content_types": { "type": "string" },
            "should_avoid": { "type": "string" },
            "should_use": { "type": "string" },
            "style_inspiration": { "type": "string" },
            "preferred_length": { "type": "integer" }
          }
        }
      }
    }
  }
}
```

**Server-side execution:**

```php
// In a new BrandIntelligenceToolHandler service
public function execute(Team $team, array $data): string
{
    if (isset($data['setup'])) {
        $team->update(Arr::only($data['setup'], [...team fields...]));
    }
    if (isset($data['positioning'])) {
        $team->brandPositioning()->updateOrCreate(
            ['team_id' => $team->id],
            $data['positioning'],
        );
    }
    if (isset($data['personas'])) {
        $team->audiencePersonas()->delete();
        foreach ($data['personas'] as $i => $persona) {
            $team->audiencePersonas()->create([...$persona, 'sort_order' => $i]);
        }
    }
    if (isset($data['voice'])) {
        $team->voiceProfile()->updateOrCreate(
            ['team_id' => $team->id],
            $data['voice'],
        );
    }
    return json_encode(['status' => 'saved', 'sections' => array_keys($data)]);
}
```

### fetch_url tool

Already exists in `UrlFetcher`. Reused as-is. The tool schema is added to the chat tools array. During streaming, a `ToolEvent` is yielded so the UI can show "Reading https://..." with the fetched content in a collapsible card.

### Web search (OpenRouter native)

Kept as a server tool (`openrouter:web_search`). Opaque during streaming — we can't show what was searched. After the response completes, the `usage.server_tool_use.web_search_requests` count is displayed as a metadata line below the message.

## Part 4: Chat UI for Tool Activity

### During streaming

Tool events are rendered as collapsible cards above/within the streaming text:

**fetch_url:**
```
┌─ Reading https://example.com ──────────────────┐
│ [chevron] Page title or URL                     │
│ (collapsed: fetched content preview)            │
└─────────────────────────────────────────────────┘
```

**update_brand_intelligence:**
```
┌─ Updated brand profile ────────────────────────┐
│ ✓ Saved: positioning, voice                    │
└─────────────────────────────────────────────────┘
```

Both use `flux:card` with small text, subtle styling.

### After response completes

Metadata line below the message:

```
2 web searches · 1,234 tokens · $0.0012
```

Using `flux:text` with `text-xs text-zinc-500`.

## Part 5: System Prompt Construction

### Brand profile JSON injection

```php
private function buildSystemPrompt(): string
{
    $profile = [
        'setup' => [...team fields...],
        'positioning' => $team->brandPositioning?->toArray(),
        'personas' => $team->audiencePersonas->toArray(),
        'voice' => $team->voiceProfile?->toArray(),
    ];

    $profileJson = json_encode($profile, JSON_PRETTY_PRINT);

    return match ($this->conversation->type) {
        'brand' => $this->brandSystemPrompt($profileJson),
        'topics' => $this->topicsSystemPrompt($profileJson),
        'write' => $this->writeSystemPrompt($profileJson),
    };
}
```

Each type gets a tailored system prompt with the JSON appended. The `brand` type also gets instructions about using tools. The `topics` and `write` types get instructions about checking for thin profiles.

## Files to create

| File | Purpose |
|------|---------|
| `database/migrations/..._add_type_to_conversations_table.php` | Add type column |
| `app/Services/ToolEvent.php` | DTO for tool execution events |
| `app/Services/BrandIntelligenceToolHandler.php` | Executes brand update tool |

## Files to modify

| File | Change |
|------|--------|
| `app/Services/OpenRouterClient.php` | Add `streamChatWithTools()` method |
| `app/Models/Conversation.php` | Add type to fillable |
| `resources/views/pages/teams/⚡create-chat.blade.php` | Chat types, tool UI, metadata |
| `resources/views/pages/teams/⚡create.blade.php` | Show conversation type in list |
| `resources/views/pages/teams/⚡brand-intelligence.blade.php` | Merge brand setup fields in |
| `resources/views/layouts/app/sidebar.blade.php` | Remove Brand Setup item |
| `routes/web.php` | Remove brand.setup route |

## Files to delete

| File | Reason |
|------|--------|
| `resources/views/pages/teams/⚡brand-setup.blade.php` | Merged into brand intelligence |

## What's explicitly out of scope

- Client-side web search (transparent search results) — future enhancement
- Markdown rendering of AI responses — future
- Conversation type switching mid-conversation
- Sharing or exporting conversations
- Rate limiting on tool calls
