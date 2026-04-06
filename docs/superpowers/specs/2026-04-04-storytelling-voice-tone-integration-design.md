# Storytelling Frameworks + Voice & Tone Integration

## Overview

Move storytelling framework selection from a manual user picker into the Voice & Tone agent. The agent picks 1-3 frameworks from the predefined set, writes brand-specific adaptation notes, and infers a preferred content length. The manual storytelling page is removed entirely.

## Database Changes

### Migration

Add two columns to `voice_tone_profiles`:

```sql
ALTER TABLE voice_tone_profiles ADD COLUMN storytelling_frameworks TEXT NOT NULL DEFAULT '[]';
ALTER TABLE voice_tone_profiles ADD COLUMN preferred_length INTEGER NOT NULL DEFAULT 1500;
```

Clean up the old project setting:

```sql
DELETE FROM project_settings WHERE key = 'storytelling_framework';
```

### Go Types

Add to `store.VoiceToneProfile`:

```go
StorytellingFrameworks string // JSON: [{"key":"storybrand","note":"..."},...]
PreferredLength       int
```

New helper type:

```go
type FrameworkSelection struct {
    Key  string `json:"key"`
    Note string `json:"note"`
}
```

Update all queries (`UpsertVoiceToneProfile`, `GetVoiceToneProfile`) to include the new columns.

## Voice & Tone Agent Changes

### Tool Schema

Add two fields to the `submit_voice_tone` tool:

```json
{
  "storytelling_frameworks": {
    "type": "array",
    "items": {
      "type": "object",
      "properties": {
        "key": {
          "type": "string",
          "enum": ["pixar", "golden_circle", "storybrand", "heros_journey", "three_act", "abt"]
        },
        "note": {
          "type": "string",
          "description": "Brand-specific adaptation: why this framework fits and how to apply it"
        }
      },
      "required": ["key", "note"]
    },
    "description": "1-3 storytelling frameworks best suited for this brand"
  },
  "preferred_length": {
    "type": "integer",
    "description": "Target word count. Infer from analyzed blog posts if possible, default 1500"
  }
}
```

### Prompt Changes

Add a new step to the system prompt between the current Step 2 (Analyze writing patterns) and Step 3 (Produce structured output):

> **Step 3: Select storytelling frameworks**
> Review the available frameworks below. Pick 1-3 that best fit this brand's voice, audience, and content style. For each, write a short adaptation note explaining why it fits and how the editor/writer should apply it to this brand specifically.

Inject the full list of 6 predefined frameworks (name, attribution, short description, best-for tag) into the prompt so the agent can make an informed selection.

Update the structured output step to include:

> 6. **Storytelling Frameworks** - 1-3 framework keys with brand-specific adaptation notes
> 7. **Preferred Length** - Target word count. Infer from analyzed blog posts if possible (average their word count). If you cannot infer, default to 1500.

When rebuilding an existing profile, include the current `storytelling_frameworks` and `preferred_length` in the existing profile section so the agent can review and improve them.

### Save Handler

Update `saveProfile` to accept and persist `storytelling_frameworks` (JSON string) and `preferred_length` (integer) from the tool result.

Update `getProfile` to return the new fields in the JSON response.

## Pipeline Integration

### Editor Step

Replace the `ProjectSettings store.ProjectSettingsStore` dependency on `EditorStep` with a `VoiceTone store.VoiceToneStore` (or use `*store.Queries` directly, matching whatever pattern the step already uses). Then replace the framework lookup (lines 43-48) with:

1. Load `VoiceToneProfile` for the project
2. Parse `StorytellingFrameworks` JSON into `[]FrameworkSelection`
3. For each selected framework, build a block containing:
   - The full `PromptInstruction` from the predefined `content.FrameworkByKey()`
   - The brand-specific adaptation note from the V&T profile

Format:

```
## Storytelling frameworks

### StoryBrand (Donald Miller)
[full PromptInstruction from predefined framework]
Brand adaptation: [the note from V&T agent]

### ABT (Randy Olson)
[full PromptInstruction from predefined framework]
Brand adaptation: [the note from V&T agent]
```

Pass this block to `ForEditor()` as the `frameworkBlock` parameter (same interface, richer content).

### Writer Step

No changes needed. The writer receives framework guidance through the editorial outline the editor produces.

### Preferred Length in Profile String

Add preferred length to `BuildVoiceToneString` output:

```
### Preferred Length
Target: ~1500 words
```

This flows into the profile string that both editor and writer see via their system prompts.

### Storytelling Frameworks in Profile String

Add selected frameworks to `BuildVoiceToneString` as well, so all pipeline steps have visibility:

```
### Storytelling Frameworks
- StoryBrand: [adaptation note]
- ABT: [adaptation note]
```

The full prompt instructions are only injected in the editor step's framework block, not in the general profile string.

## UI Changes

### Voice & Tone Card (profile.templ)

Replace the single-blob display (current lines 169-178) with 7 individual subcards using the same styling as persona cards (`bg-zinc-800/50 border border-zinc-800 rounded-lg`):

1. **Voice Analysis** - title, content preview with max-h-20, fade gradient, "Show more" button
2. **Content Types** - same pattern
3. **Should Avoid** - same pattern
4. **Should Use** - same pattern
5. **Style Inspiration** - same pattern
6. **Storytelling Frameworks** - one subcard per selected framework showing: framework name as title, "Best for" badge, adaptation note as preview, expandable full instruction from the predefined set
7. **Preferred Length** - compact subcard showing formatted target (e.g. "~1,500 words"), editable via inline input or small modal

Each text section subcard gets: section title as heading, content preview with `max-h-20 overflow-hidden`, fade gradient overlay, "Show more" expand button.

### Edit Flow

Update the V&T edit modal/flow to include:

- The existing 5 textarea fields (unchanged)
- Storytelling frameworks: checkboxes for the 6 predefined frameworks, with a textarea for the adaptation note on each selected one
- Preferred length: number input field

### Save Endpoint

Update the `saveProfile` handler to accept `storytelling_frameworks` and `preferred_length` in the JSON body and persist them.

### Removals

- Delete `web/handlers/storytelling.go`
- Delete `web/templates/storytelling.templ` (and generated `storytelling_templ.go`)
- Remove the `/projects/{id}/storytelling` route from the router
- Remove the storytelling nav entry from the sidebar/navigation

## File Summary

### Modified

- `migrations/` - new migration for columns + cleanup
- `internal/store/voice_tone.go` - new fields, updated queries, FrameworkSelection type
- `internal/store/profile.go` - updated `BuildVoiceToneString` with frameworks + length
- `web/handlers/voice_tone.go` - updated prompt, tool schema, save/get handlers
- `internal/pipeline/steps/editor.go` - read frameworks from V&T profile instead of project settings
- `web/templates/profile.templ` - V&T card with 7 subcards
- Router file - remove storytelling route

### Deleted

- `web/handlers/storytelling.go`
- `web/templates/storytelling.templ` (+ generated file)
