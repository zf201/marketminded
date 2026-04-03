# Voice & Tone Redesign

## Overview

Replace the single-text-blob voice & tone section with structured output (5 sections), absorb the tone analyzer pipeline step into the profile, and add rich context inputs (blog URLs, liked articles, inspiration URLs). The tone analyzer pipeline step is removed entirely.

## Data Model

### New `voice_tone_profiles` table

| Column | Type | Required | Notes |
|--------|------|----------|-------|
| id | INTEGER PK | yes | autoincrement |
| project_id | INTEGER FK | yes | UNIQUE — one row per project |
| voice_analysis | TEXT | yes | Personality, formality, warmth, reader relationship |
| content_types | TEXT | yes | Educational, promotional, storytelling, etc. |
| should_avoid | TEXT | yes | Words, phrases, anti-patterns |
| should_use | TEXT | yes | Characteristic vocabulary, patterns |
| style_inspiration | TEXT | yes | Writing style patterns from inspiration sources |
| created_at | DATETIME | yes | DEFAULT CURRENT_TIMESTAMP |

### Context storage (project settings)

- `voice_tone_blog_urls` — JSON array of `[{url, notes}]` (company blog listing URLs)
- `voice_tone_liked_articles` — JSON array of `[{url, notes}]` (specific posts the user likes)
- `voice_tone_inspiration` — JSON array of `[{url, notes}]` (external inspiration blogs/articles)
- `profile_context_voice_and_tone` — additional notes textarea (free text)

### Profile string integration

`BuildProfileString` skips the `voice_and_tone` row from `profile_sections` and instead queries `voice_tone_profiles`, formatting as:

```markdown
## Voice & Tone

### Voice Analysis
[content]

### Content Types
[content]

### Should Avoid
[content]

### Should Use
[content]

### Style Inspiration
[content]
```

Only includes sections that have content.

## UI: Profile Page — Voice & Tone Card

### Card layout

Same modal-based pattern as the other sections:

- **Header:** Title + badge (Approved if profile exists, Empty otherwise) + Build button + Edit button
- **Context subcard:** Shows blog URLs, liked article count/URLs, inspiration URLs, notes. "Edit context" button on the subcard.
- **Content:** All 5 sections rendered as markdown with expand/collapse, section headers visible

### Context modal

Three URL sections, each with dynamic add/remove rows (same pattern as Product & Positioning source URLs):

1. **Blog URLs** — company blog listing pages to crawl for recent posts
2. **Liked articles** — specific blog posts the user considers good examples of their voice
3. **Inspiration** — external blogs/articles whose writing style they admire

Plus an **Additional notes** textarea at the bottom.

### Build modal

Same flow as Product & Positioning:
- Context summary (lists URLs, notes, mentions existing profile if rebuilding)
- Generate button
- Streams result into a textarea (formatted with section headers)
- User reviews/edits, then Save or Discard

On save, the concatenated text is parsed back into 5 fields by splitting on section headers.

### Edit modal

5 labeled textareas:
- Voice analysis
- Content types
- Should avoid
- Should use
- Style inspiration

Save/Cancel buttons. Save creates or updates the single `voice_tone_profiles` row.

## AI Generation

### Tools

- `fetch_url` — fetch blog pages and articles
- `web_search` — research if needed
- `submit_voice_tone` — structured JSON output

### Process

1. Fetch all blog URLs — find and read 3-5 recent posts from each
2. Fetch liked articles — analyze the specific posts flagged as good
3. Fetch inspiration URLs — analyze external writing style
4. Web search if needed for additional context
5. Synthesize all analysis into 5 structured sections
6. Call `submit_voice_tone`

### Prompt context

- Product & Positioning content (business context)
- Audience personas (who the writing is for)
- All fetched blog/article/inspiration content
- Context notes from the user
- Existing voice & tone profile (if rebuilding — improve upon it)
- Memory setting

### `submit_voice_tone` tool schema

```json
{
  "type": "object",
  "properties": {
    "voice_analysis": {
      "type": "string",
      "description": "Brand personality, formality level, warmth, how they relate to the reader (peer/mentor/authority), characteristic communication style"
    },
    "content_types": {
      "type": "string",
      "description": "What content approaches the brand uses — educational, promotional, storytelling, opinion, how-to, case study, etc. Note the balance and when each is appropriate"
    },
    "should_avoid": {
      "type": "string",
      "description": "Words, phrases, patterns, and tones to never use. Anti-patterns. What the brand should NOT sound like"
    },
    "should_use": {
      "type": "string",
      "description": "Characteristic vocabulary, phrases, sentence patterns, formatting conventions. Words and expressions that define the brand voice"
    },
    "style_inspiration": {
      "type": "string",
      "description": "Writing style patterns observed from the inspiration sources. What vibe and approach the brand gravitates toward based on the references provided"
    }
  },
  "required": ["voice_analysis", "content_types", "should_avoid", "should_use", "style_inspiration"]
}
```

### AI rules

- Always write in English
- Analyze STYLE only, not content
- Be specific — generic voice guides are useless
- Base everything on actual content from the fetched URLs
- When rebuilding, improve upon existing profile, don't start from scratch

## Pipeline Cleanup

### Remove tone analyzer step

- Delete `internal/pipeline/steps/tone_analyzer.go`
- Remove `"tone_analyzer": {}` from orchestrator step registration (`internal/pipeline/orchestrator.go`)
- Remove `ToneAnalyzerStep` initialization from `cmd/server/main.go`
- Remove `tone_analyzer` tool set from `internal/tools/registry.go`
- Remove `ForToneAnalyzer` method from `internal/prompt/builder.go`

### Update editor and writer steps

Both `editor.go` and `writer.go` currently parse `PriorOutputs["tone_analyzer"]` to extract a tone guide. Remove this parsing — voice & tone data now comes from the profile string that all steps already receive via `input.Profile`.

In `editor.go`: remove the `toneGuide` variable and `PriorOutputs["tone_analyzer"]` parsing. Remove the `toneGuide` parameter from `ForEditor` call (or pass empty string if the prompt builder still expects it).

In `writer.go`: same removal. Remove the `toneGuide` parameter from `ForWriter` call.

Update `ForEditor` and `ForWriter` in the prompt builder to no longer accept a separate `toneGuide` parameter — the voice & tone is already in the profile string.

### Remove blog settings from project settings page

- Remove `company_blog` and `blog_notes` fields from `web/templates/project_settings.templ`
- Remove from `ProjectSettingsData` struct
- Remove from handler `show()` and `save()` methods
- Blog URLs are now managed in the Voice & Tone context modal

## Routes

New/modified endpoints:

```
GET  /projects/{id}/profile/voice_and_tone/context       — get context (3 URL lists + notes)
POST /projects/{id}/profile/voice_and_tone/save-context   — save context
GET  /projects/{id}/profile/voice_and_tone/profile        — get voice tone profile as JSON
POST /projects/{id}/profile/voice_and_tone/profile        — save/update profile
GET  /projects/{id}/profile/voice_and_tone/generate       — SSE stream for generation
```

These are handled by a dedicated `VoiceToneHandler` (same pattern as `AudienceHandler`), delegated from the profile handler via prefix matching on `profile/voice_and_tone/`.
