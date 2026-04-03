# Audience Persona Cards

## Overview

Replace the single-text-blob audience section with structured persona cards. An AI agent generates 3-5 personas from product & positioning context, customer location, and web research. Users review per-card (accept/reject), then edit individual personas manually. The pipeline consumes personas as formatted markdown.

## Data Model

### New `audience_personas` table

| Column | Type | Required | Notes |
|--------|------|----------|-------|
| id | INTEGER PK | yes | autoincrement |
| project_id | INTEGER FK | yes | references projects(id) ON DELETE CASCADE |
| label | TEXT | yes | Short name: "Startup CTO", "Health-conscious parent" |
| description | TEXT | yes | 1-2 paragraph prose about who they are |
| pain_points | TEXT | yes | Their problems in their own language |
| push | TEXT | yes | Frustrations driving them to seek a solution |
| pull | TEXT | yes | What attracts them to this specific solution |
| anxiety | TEXT | yes | Concerns that might stop them from acting |
| habit | TEXT | yes | What keeps them stuck with the status quo |
| role | TEXT | no | Job title — empty for B2C |
| demographics | TEXT | no | Age range, location, income if relevant |
| company_info | TEXT | no | Company type/size — empty for B2C |
| content_habits | TEXT | no | Where they consume content |
| buying_triggers | TEXT | no | Decision process, triggers |
| sort_order | INTEGER | yes | Display ordering |
| created_at | DATETIME | yes | DEFAULT CURRENT_TIMESTAMP |

No version history on individual personas — per-card approve/reject handles this.

### Context storage

Uses existing project settings pattern:
- `profile_location_audience` — customer location text (e.g. "US, Western Europe")
- `profile_context_audience` — additional notes textarea

### Profile sections cleanup

The existing `profile_sections` row for `audience` becomes unused. `BuildProfileString` skips it and reads from `audience_personas` instead.

## UI: Profile Page — Audience Card

The audience card on the profile page renders persona subcards instead of a text blob.

### Card layout

```
+-- Audience ------------------------------------------------+
|  [Approved/Empty badge]                    [Build]          |
|                                                             |
|  +-- Context --------------------------[Edit context]-----+ |
|  | Location: US, Western Europe                            | |
|  | Notes: Focus on SMB segment...                          | |
|  +---------------------------------------------------------+ |
|                                                             |
|  +-- Startup CTO ---------------------------[Edit] [x]---+ |
|  | Description preview...                                  | |
|  | Pain points | Push/Pull/Anxiety/Habit                   | |
|  | [Show more]                                             | |
|  +---------------------------------------------------------+ |
|  +-- Agency Owner --------------------------[Edit] [x]---+ |
|  | ...                                                     | |
|  +---------------------------------------------------------+ |
+-------------------------------------------------------------+
```

- Each persona is a subcard (bg-base-200) inside the audience card
- Per-persona Edit button opens modal to edit fields, x button deletes (with confirm)
- No parent "Edit" button — only Build and per-persona edit
- Context subcard shows location + notes with "Edit context" button
- Persona content rendered as markdown with expand/collapse (same pattern as other sections)

### Persona subcard display

Mandatory fields always shown (collapsed preview):
- **Label** as card title
- **Description** as body text (markdown, truncated)

On expand, show all filled fields with labels.

## Build Modal

When the user clicks Build on the audience card:

### Initial state

Shows context summary:
- Customer location
- Additional notes
- Note that Product & Positioning will be included
- Count of existing personas (if any)
- [Generate] button

### AI process

1. Reads Product & Positioning section content
2. Reads existing personas (if any)
3. Gets context (location + notes)
4. Uses web search to research the market/demographics for the given location and product
5. Generates a JSON array of personas via `submit_personas` tool
6. Each persona includes a `status` field: `new`, `updated`, `unchanged`, `removed`

### After generation

Each persona renders as a card with status badge and actions:

- **new** — "Accept" / "Reject" buttons
- **updated** — "Accept" / "Reject" buttons
- **unchanged** — "Keep" / "Remove" buttons
- **removed** — "Confirm removal" / "Keep" buttons

Footer: [Save accepted] [Discard all]

"Save accepted" commits all accepted changes atomically:
- Insert new accepted personas
- Update accepted modifications
- Delete confirmed removals
- Leave rejected/kept ones untouched

### AI configuration

- Tools: `web_search`, `submit_personas`
- Temperature: 0.3
- Max iterations: 10 (web search may need several calls)
- Target: 3-5 personas (soft target in prompt, not enforced)
- Model: content model (same as other profile generation)

### submit_personas tool schema

```json
{
  "type": "object",
  "properties": {
    "personas": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "id": { "type": "integer", "description": "Existing persona ID if updating/removing, null if new" },
          "status": { "type": "string", "enum": ["new", "updated", "unchanged", "removed"] },
          "label": { "type": "string" },
          "description": { "type": "string" },
          "pain_points": { "type": "string" },
          "push": { "type": "string" },
          "pull": { "type": "string" },
          "anxiety": { "type": "string" },
          "habit": { "type": "string" },
          "role": { "type": "string" },
          "demographics": { "type": "string" },
          "company_info": { "type": "string" },
          "content_habits": { "type": "string" },
          "buying_triggers": { "type": "string" }
        },
        "required": ["status", "label", "description", "pain_points", "push", "pull", "anxiety", "habit"]
      }
    },
    "reasoning": { "type": "string", "description": "Brief explanation of why these personas were chosen and what changed" }
  },
  "required": ["personas", "reasoning"]
}
```

## Edit Persona Modal

Manual editing of a single persona. Opens when clicking Edit on a persona subcard.

- All mandatory fields always shown as labeled textareas/inputs
- Label: text input
- Description, pain_points, push, pull, anxiety, habit: textareas
- Optional fields: shown if they have content
- "+ Add field" dropdown/button to add empty optional fields (role, demographics, company_info, content_habits, buying_triggers)
- [Save] [Cancel] buttons
- Save updates the single row via AJAX, reloads the card

## Context Modal — Audience

Same modal pattern as Product & Positioning context, but with different fields:

- **Customer location** — text input (e.g. "US, Western Europe", "Global")
- **Additional notes** — textarea

Stored as project settings. No source URLs for audience.

## Pipeline Integration

`BuildProfileString` changes:
- When building the profile string, skip the `audience` row from `profile_sections`
- Query `audience_personas` for the project
- Format each persona as structured markdown:

```markdown
## Audience

### Persona 1: Startup CTO
**Description:** Building dev tools for...
**Pain points:** Can't find engineers who...
**Push:** Frustrated with current hiring...
**Pull:** Wants a platform that...
**Anxiety:** Worried about vendor lock-in...
**Habit:** Currently using spreadsheets...
**Role:** CTO / VP Engineering
**Company:** Series A-B SaaS, 20-100 people
```

Only include optional fields that have content. This is consumed by all pipeline steps that use the profile.

## Routes

New/modified endpoints:

```
GET  /projects/{id}/profile/{section}/context    — existing, add location field
POST /projects/{id}/profile/{section}/save-context — existing, add location field
GET  /projects/{id}/profile/audience/personas     — list personas as JSON
POST /projects/{id}/profile/audience/personas      — save persona (create/update)
DELETE /projects/{id}/profile/audience/personas/{pid} — delete persona
GET  /projects/{id}/profile/audience/generate      — SSE stream for persona generation
POST /projects/{id}/profile/audience/save-generated — save accepted personas from build
```

## Future Work (not in this spec)

- Content targeting: pipeline agent picks which persona(s) a piece of content is written for
- Per-persona content strategy
