# Storytelling Framework Selection & Settings Card

## Summary

Two changes to the project overview page:

1. **Move project settings from a button to a card** on the overview grid
2. **Add a storytelling framework card** that lets users select one of 6 frameworks per project, injected into cornerstone content generation

## Overview Page Changes

Replace the "Settings" button in the top-right header with a card in the grid. Add a new "Storytelling Framework" card.

**Grid (6 cards):** Client Profile, Settings, Storytelling Framework, Content Pipeline, Chat, Templates.

### Settings Card

- Shows current language as quick summary (e.g., "Language: English")
- Badge: "Configured" if any setting has a non-empty value, "Not set" otherwise
- Links to existing `/projects/{id}/settings` page (no changes to that page)

### Storytelling Framework Card

- Shows currently selected framework name (e.g., "StoryBrand") or "Not set"
- Badge: same configured/not-set pattern
- Links to new `/projects/{id}/storytelling` page

## Storytelling Framework Page

**Route:** `/projects/{id}/storytelling`

**Layout:** Same page chrome as other sub-pages (header with project name, back link).

### Framework Cards

6 framework cards displayed vertically, each showing:

- **Framework name + attribution** (e.g., "StoryBrand — Donald Miller")
- **1-2 sentence description**
- **"Best for" tag** (e.g., "Sales/Marketing")
- **Expandable section** with full beats, business example
- **Radio/select indicator** for the active framework

### Interaction

- Click a card or its radio button to select it
- POST form saves choice as `storytelling_framework` in `project_settings` table
- Redirect back with `?saved=1` success indicator
- "None" option to clear the selection (sets value to empty string in `project_settings`)

### Framework Data

Stored as Go constants/structs (not in the database). The 6 frameworks:

| Key | Name | Attribution | Best For |
|-----|------|-------------|----------|
| `pixar` | Pixar Framework | Pixar Studios | Change management |
| `golden_circle` | Golden Circle | Simon Sinek | Vision/mission |
| `storybrand` | StoryBrand | Donald Miller | Sales/marketing |
| `heros_journey` | Hero's Journey | Joseph Campbell | Personal branding |
| `three_act` | Three-Act Structure | Classic | Formal presentations |
| `abt` | ABT (And/But/Therefore) | Randy Olson | Daily communication |

Each framework struct contains:
- `Key` — identifier stored in project_settings
- `Name` — display name
- `Attribution` — creator/origin
- `ShortDescription` — 1-2 sentences
- `BestFor` — use-case tag
- `Beats` — the structural beats (e.g., "Once upon a time… / Every day… / …")
- `Example` — business example
- `PromptInstruction` — text injected into the pipeline prompt

## Pipeline Integration

### Scope

Only cornerstone pieces (`piece.ParentID == nil`) — blog posts, YouTube scripts. Waterfall pieces are not affected.

### Injection Point

In `buildPiecePrompt`, after the client profile section, before the topic brief:

```
Today's date: ...

{content type prompt}

## Client profile
{profile}

## Storytelling framework
Framework: StoryBrand (Donald Miller)
Structure this content following these beats:
- Character (your customer) has a Problem
- Meets a Guide (the brand) with Empathy + Authority
- Gets a Plan (process + success path)
- Call to Action (direct + transitional)
- Stakes (avoid failure)
- Success (after state)

## Topic brief
{brief}
```

### Lookup

`buildPiecePrompt` currently takes `(piece, run, profile)`. Add `projectID` as a fourth parameter (available from the caller in `streamPiece`). Call `GetProjectSetting(projectID, "storytelling_framework")`. If the value is non-empty and matches a known framework key, inject the framework prompt section. If empty, absent, or unrecognized — skip silently. Pipeline works exactly as today.

The storytelling section is inserted between the `## Client profile` block and the conditional `## Topic brief` / `## Cornerstone content` block, before `antiAIRules` (which stays at the end).

## Storage

Reuses existing `project_settings` table. No new migrations needed.

- Key: `storytelling_framework`
- Value: framework key string (e.g., `"storybrand"`, `"pixar"`) or empty string for none

## Struct & Handler Changes

- `ProjectDetail` struct in `web/templates/project.templ` needs new fields: `Language string`, `FrameworkName string` (display name of selected framework, or empty)
- `ShowProject` handler in `web/handlers/project.go` needs to query `AllProjectSettings` and look up framework to populate these fields
- Route: add `case strings.HasPrefix(rest, "storytelling"):` in the project sub-router switch in `cmd/server/main.go`, wiring a new `StorytellingHandler` with `*store.Queries`
- Grid CSS: verify 6-card layout works with existing `.grid` class; adjust if needed (likely 2-col or 3-col)

## Files to Create/Modify

- `internal/content/frameworks.go` — new file, framework struct definitions and registry
- `web/templates/project.templ` — add fields to `ProjectDetail`, replace settings button with settings card, add storytelling card
- `web/templates/storytelling.templ` — new template for framework selection page
- `web/handlers/storytelling.go` — new handler for GET/POST on `/projects/{id}/storytelling`
- `web/handlers/project.go` — populate new `ProjectDetail` fields from settings
- `web/handlers/pipeline.go` — add `projectID` param to `buildPiecePrompt`, inject framework for cornerstone pieces
- `cmd/server/main.go` — register storytelling route in project sub-router switch
