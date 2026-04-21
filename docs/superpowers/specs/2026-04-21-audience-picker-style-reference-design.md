# Audience Picker & Style Reference — Writer Chat Port

## Goal

Port the two remaining Go pipeline steps (`audience_picker`, `style_reference`) to the Laravel writer chat, and remove checkpoint mode entirely. After this work the writer auto-runs all steps without pausing.

**Out of scope:** removing the Go version (separate task after this lands).

---

## Pipeline Shape

```
research_topic
  → pick_audience          [skipped if team has no personas]
  → create_outline
  → fetch_style_reference  [skipped if blog_url empty AND style_reference_urls empty]
  → write_blog_post
```

`proofread_blog_post` remains a user-triggered follow-up, not part of the auto pipeline.

Both new tools are always registered with the orchestrator. When conditions aren't met the handler returns `{"status": "skipped", "reason": "..."}`. The orchestrator system prompt treats `skipped` as a clean no-op and proceeds.

---

## Checkpoint Mode Removal

Checkpoint mode is removed entirely. Changes:

- `⚡create-chat.blade.php`: remove mode tile UI (autopilot/checkpoint sub-cards), remove `!autopilot`/`!checkpoint` command interception, remove all pause/approval language from the orchestrator system prompt
- Migration: drop `mode` column from `conversations` table
- Orchestrator prompt: always autorun, no pauses

---

## Brief Schema Additions

Two new slots on `conversations.brief` (JSONB):

### `audience`

Written by `AudiencePickerAgent`, read by `EditorAgent` and `WriterAgent`.

```jsonc
{
  "mode": "persona",           // persona | educational | commentary
  "persona_id": 3,             // only present when mode=persona
  "persona_label": "Pro Chef",
  "persona_summary": "Experienced professional. Pain points: cheap tools that break. Push: faster prep.",
  "reasoning": "The topic is about high-end knife maintenance — clearly targets professionals.",
  "guidance_for_writer": "Address a reader who owns and uses knives daily for income. Assume deep knife knowledge. Skip beginner explanations."
}
```

### `style_reference`

Written by `StyleReferenceAgent`, read by `WriterAgent` only.

```jsonc
{
  "examples": [
    {
      "url": "https://brand.com/blog/post-1",
      "title": "How We Source Our Steel",
      "body": "...(full fetched body)...",
      "why_chosen": "Strong opening hook, short paragraphs, benefit-forward structure"
    }
  ],
  "reasoning": "These two posts best represent the brand's direct, expert tone."
}
```

### Brief additions

`Brief.php` gains:

- `audience(): ?array`
- `hasAudience(): bool`
- `withAudience(array $audience): self`
- `styleReference(): ?array`
- `hasStyleReference(): bool`
- `withStyleReference(array $ref): self`

`statusSummary()` gets two new lines for audience and style_reference.

---

## New Agents

### `AudiencePickerAgent`

**File:** `app/Services/Writer/Agents/AudiencePickerAgent.php`

- Extends `BaseAgent`
- `useServerTools()` → `false`
- `additionalTools()` → `[]` (single LLM turn, no fetching)
- `model()` → `$team->fast_model`
- `temperature()` → `0.2`

**System prompt** injects: topic title + angle, research `topic_summary`, all team personas as a numbered list (id, label, description, pain_points, push, pull, anxiety, role).

**Submit tool:** `submit_audience_selection`

```json
{
  "type": "object",
  "required": ["mode", "reasoning", "guidance_for_writer"],
  "properties": {
    "mode": { "type": "string", "enum": ["persona", "educational", "commentary"] },
    "persona_id": { "type": "integer" },
    "reasoning": { "type": "string" },
    "guidance_for_writer": { "type": "string" }
  }
}
```

**`validate()`:**
- `mode` must be `persona|educational|commentary`
- `persona_id` required when `mode=persona`, forbidden otherwise
- `guidance_for_writer` must be non-empty

**`applyToBrief()`:** hydrates `persona_label` and `persona_summary` from the matching `AudiencePersona` record (looked up by `persona_id`). Calls `$brief->withAudience(...)`.

`persona_summary` is a compact one-liner built from: description, pain_points, push, pull, anxiety (same logic as Go's `personaSummary()`).

**`buildCard()`:** small pill — mode + persona label when applicable.

---

### `StyleReferenceAgent`

**File:** `app/Services/Writer/Agents/StyleReferenceAgent.php`

- Extends `BaseAgent`
- `useServerTools()` → `false`
- `additionalTools()` → `[fetch_url schema]`
- `model()` → `$team->fast_model`
- `temperature()` → `0.2`
- `timeout()` → `180` (multiple fetch round-trips)

**System prompt** tells the LLM:
- Pre-curated URLs from `$team->style_reference_urls` if present (prefer these)
- `$team->blog_url` for discovery if curated list is empty
- Instruction: fetch 2–3 posts that best represent the brand's voice, submit titles + URLs + why_chosen; do NOT include body (the handler fetches bodies after submission)

**Submit tool:** `submit_style_reference`

```json
{
  "type": "object",
  "required": ["examples", "reasoning"],
  "properties": {
    "examples": {
      "type": "array",
      "minItems": 2,
      "maxItems": 3,
      "items": {
        "type": "object",
        "required": ["url", "title", "why_chosen"],
        "properties": {
          "url":        { "type": "string" },
          "title":      { "type": "string" },
          "why_chosen": { "type": "string" }
        }
      }
    },
    "reasoning": { "type": "string" }
  }
}
```

**`validate()`:** 2–3 examples, all fields non-empty.

**Post-submit body fetch (in handler, not agent):** after the agent submits, `FetchStyleReferenceToolHandler` fetches each URL via `UrlFetcher`, populates `body`, drops examples with < 400 chars, errors if fewer than 2 survive.

**`applyToBrief()`:** stores body-less examples in brief (`body` field omitted). The agent result brief intentionally has no bodies at this point.

**Body population in handler:** `FetchStyleReferenceToolHandler` calls `$agent->execute()`, gets back the `AgentResult`, fetches each URL body via `UrlFetcher`, rebuilds the examples array with bodies, then calls `$result->brief->withStyleReference([...with bodies...])` to produce the final brief — which it persists to `$conversation->brief`. The body-less intermediate brief from the agent result is never persisted.

**`buildCard()`:** list of example titles with "why chosen" snippets.

---

## New Tool Handlers

### `PickAudienceToolHandler`

**File:** `app/Services/PickAudienceToolHandler.php`

**Conditionality:** if `$team->audiencePersonas()->exists()` is false → return `{"status": "skipped", "reason": "No personas configured for this team."}` (no brief update, no error).

**Retry guard:** if `pick_audience` already has `status=ok` in `priorTurnTools` → idempotent success (return existing audience from brief).

**Happy path:** same pattern as `ResearchTopicToolHandler` — load brief, run `AudiencePickerAgent`, persist brief, return `{"status": "ok", "summary": "...", "card": {...}}`.

**Tool schema:**
```json
{
  "name": "pick_audience",
  "description": "Run the AudiencePicker sub-agent. Reads brief.research + team personas; writes brief.audience with mode, persona selection, and writer guidance.",
  "parameters": {
    "type": "object",
    "properties": {
      "extra_context": { "type": "string", "description": "Optional guidance for the sub-agent on retry." }
    }
  }
}
```

---

### `FetchStyleReferenceToolHandler`

**File:** `app/Services/FetchStyleReferenceToolHandler.php`

**Conditionality:** if `$team->blog_url` is empty AND `collect($team->style_reference_urls)->isEmpty()` → return `{"status": "skipped", "reason": "No blog URL or style reference URLs configured."}`.

**Retry guard:** same pattern — idempotent success if already ok this turn.

**Happy path:** run `StyleReferenceAgent`, then fetch each submitted URL body via `UrlFetcher`, drop short bodies, error if fewer than 2 survive, persist brief with bodies, return result.

**Tool schema:**
```json
{
  "name": "fetch_style_reference",
  "description": "Run the StyleReference sub-agent. Reads team blog_url / style_reference_urls; writes brief.style_reference with 2–3 exemplar posts and their full bodies.",
  "parameters": {
    "type": "object",
    "properties": {
      "extra_context": { "type": "string", "description": "Optional guidance for the sub-agent on retry." }
    }
  }
}
```

---

## Downstream Prompt Updates

### `EditorAgent`

When `$brief->hasAudience()`, inject an audience block into the system prompt:

```
## Audience target
Mode: {mode}
[Persona: {persona_label} — {persona_summary}]   (only when mode=persona)
Writer guidance: {guidance_for_writer}
```

The outline sections should reflect the audience framing.

### `WriterAgent`

Inject audience block (same format as above) and style reference block when present:

```
## Style reference — match this voice
The following are real posts from this brand's blog. Match their rhythm, sentence
length, opener patterns, register, and feel. Do NOT copy sentences or facts.

### Example 1: {title}
{body}

### Example 2: {title}
{body}
```

---

## UI Cards

### `pick_audience` card

```
✓ Audience: Pro Chef (persona)
  "Address daily professional users — skip beginner content."
```

Or for educational/commentary:

```
✓ Audience: Educational (no persona)
  "Write for a curious learner of the topic."
```

### `fetch_style_reference` card

```
✓ Style reference: 2 examples
  · How We Source Our Steel
  · Why Hand-Forged Beats Stamped
```

Both cards follow the existing rounded-border zinc style.

---

## Testing

All tests run via `./vendor/bin/sail test`.

### `AudiencePickerAgentTest`

- Happy path mode=persona: correct persona hydrated, audience written to brief
- Happy path mode=educational: no persona_id, guidance present
- Happy path mode=commentary: same as educational
- Validation: rejects persona_id on educational/commentary mode
- Validation: rejects empty guidance_for_writer
- Missing research on brief: returns error

### `StyleReferenceAgentTest`

- Happy path: 2 examples survive fetch, brief written
- Short body dropped: still passes with 2 remaining
- All bodies too short: error
- Fewer than 2 survive after fetch: error

### `PickAudienceToolHandlerTest`

- No personas: returns skipped, brief unchanged
- Happy path: brief persisted, ok result
- Retry guard: idempotent ok on second in-turn call

### `FetchStyleReferenceToolHandlerTest`

- No blog_url + empty style_reference_urls: returns skipped
- Happy path: brief persisted with bodies
- Retry guard: idempotent ok

### Regression

- Existing writer test suite passes after checkpoint removal
- `WriterAgent` and `EditorAgent` tests pass with audience/style_reference slots absent from brief (both blocks are optional injections)

---

## Migration

```php
// Drop mode column from conversations
$table->dropColumn('mode');
```

No data migration needed — mode values are not used after this change.

---

## File Summary

**Create:**
- `app/Services/Writer/Agents/AudiencePickerAgent.php`
- `app/Services/Writer/Agents/StyleReferenceAgent.php`
- `app/Services/PickAudienceToolHandler.php`
- `app/Services/FetchStyleReferenceToolHandler.php`
- `database/migrations/YYYY_MM_DD_drop_mode_from_conversations.php`
- `tests/Unit/Services/Writer/Agents/AudiencePickerAgentTest.php`
- `tests/Unit/Services/Writer/Agents/StyleReferenceAgentTest.php`
- `tests/Unit/Services/PickAudienceToolHandlerTest.php`
- `tests/Unit/Services/FetchStyleReferenceToolHandlerTest.php`

**Modify:**
- `app/Services/Writer/Brief.php` — add audience + style_reference slots
- `app/Services/Writer/Agents/EditorAgent.php` — inject audience block
- `app/Services/Writer/Agents/WriterAgent.php` — inject audience + style_reference blocks
- `resources/views/pages/teams/⚡create-chat.blade.php` — remove checkpoint UI/commands, add new tool registrations and cards, update orchestrator system prompt
