# Client Profile Redesign

## Overview

Consolidate company URLs/notes out of Project Settings and into Client Profile sections. Replace the chat-based profile editing flow with direct textarea editing plus a one-shot AI "magic" generation button. Remove unused sections (Guidelines, Content Strategy). Add version history for profile section content.

## Sections

Profile reduces from 5 sections to 3:

1. **Product & Positioning** — with source URLs subcard
2. **Audience** — plain content (future: gets its own enrichment treatment)
3. **Voice & Tone** — plain content (future: blog links move here)

Removed: Guidelines, Content Strategy.

No sequential locking — all sections are independently editable.

## Data Model

### `profile_sections` table changes

Add two columns:

- `source_urls TEXT` — JSON array of URL + notes objects:
  ```json
  [
    {"url": "https://example.com", "notes": "Main homepage, use for value prop"},
    {"url": "https://example.com/pricing", "notes": "Reference pricing tiers"}
  ]
  ```
- `source_notes TEXT` — removed, notes are per-URL in the JSON above

Existing `content` column stays as-is.

### New `profile_section_versions` table

```sql
CREATE TABLE profile_section_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    section TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

- A new version is created on every save (if content changed)
- Capped at 5 per project+section — oldest deleted when a 6th is saved
- Stores content snapshots only, not source URLs/notes

### Settings cleanup

Remove from project settings:
- `company_website`
- `website_notes`
- `company_pricing`
- `pricing_notes`

Keep in project settings (for now):
- `company_blog`, `blog_notes` (until Voice & Tone gets the same treatment)
- `language`, `storytelling_framework`, `memory`

Migration script:
1. Add `source_urls` column to `profile_sections`
2. Create `profile_section_versions` table
3. Read existing `company_website`, `website_notes`, `company_pricing`, `pricing_notes` from `project_settings` for each project
4. Convert to JSON array format and write to `source_urls` on the `product_and_positioning` row (create row if needed)
5. Delete the migrated settings keys

## UI: Profile Page

### Section cards

Each section renders as a card on the profile page:
- Shows truncated content preview
- "Edit" button (or "Start" if empty)
- No locking, no sequential dependencies

### Section edit view

Clicking a card navigates to a dedicated edit page at `/projects/{id}/profile/{section}/edit` (not a chat). For Product & Positioning:

```
+-- Product & Positioning ----------------------------+
|                                                      |
|  +-- Source URLs ----------------------------------+ |
|  | [https://example.com       ] [Homepage notes  ] | |
|  | [https://example.com/price ] [Pricing notes   ] | |
|  | [https://example.com/about ] [About notes     ] | |
|  | [+ Add URL]                                     | |
|  +-------------------------------------------------+ |
|                                                      |
|  [Build Profile]  [History]                          |
|                                                      |
|  +-- Content -------------------------------------+ |
|  | [textarea with current/generated content      ] | |
|  |                                                 | |
|  +-------------------------------------------------+ |
|                                                      |
|  [Save]  [Cancel]                                    |
|                                                      |
+------------------------------------------------------+
```

- **Build Profile** label when section is empty, **Rebuild** when content exists
- Source URLs: dynamic list of URL + notes rows, each with a remove button, "Add URL" appends a new empty row
- Audience and Voice & Tone: same layout but without the Source URLs subcard

### Version history modal

Triggered by "History" button. Modal with:
- Tabs or list showing last 5 versions, most recent first
- Each tab shows the full content as read-only text
- "Restore" button on each version — loads content into the textarea (unsaved until user clicks Save)
- Timestamp on each version

## Magic Generation

### Trigger

User clicks "Build Profile" or "Rebuild" on a section.

### Process

1. Frontend sends POST request with section name
2. Backend reads source URLs from the section's `source_urls` JSON
3. Backend pre-fetches all URLs server-side using existing fetch logic (HTML cleaning, 12k char limit per URL)
4. Backend sends a single LLM call with a predefined system prompt containing:
   - Section description (what to cover: value proposition, differentiators, problems solved, products/services, pricing model, CTA, competitors)
   - Fetched URL content (labeled with URL and notes)
   - Existing section content (if rebuilding — instruct AI to improve/expand, not rewrite from zero)
   - Memory setting from project settings (important rules/facts)
5. Response is streamed via SSE directly into the textarea
6. Content appears in textarea but is **not saved** — user reviews, edits, then clicks Save

### LLM configuration

- No tools — URLs are pre-fetched server-side
- Single completion call, streamed
- Temperature: 0.3

## Pipeline Integration

### New store method: `BuildSourceURLList(projectID)`

Reads `source_urls` JSON from the Product & Positioning section and returns a formatted string:

```
## Must-Use URLs (fetch these for latest data)
- https://example.com — Homepage notes: use for value prop and CTA
- https://example.com/pricing — Pricing notes: reference pricing tiers
- https://example.com/about — About notes: team and mission context
```

### Brand Enricher changes

- Stop reading `company_website`, `website_notes`, `company_pricing`, `pricing_notes` from project settings
- Instead call `BuildSourceURLList(projectID)` to get the URL list
- Brand Enricher still fetches URLs at runtime for fresh data
- Enricher prompt simplifies: it already has a rich profile for context, its job is to enrich research with current data from the URLs

## Chat Removal

### Profile section chats — remove entirely

- Remove routes: `GET /projects/{id}/profile/{section}`, `POST /{section}/message`, `GET /{section}/stream`
- Remove `GetOrCreateSectionChat()` usage for profile sections
- Remove profile chat templates
- Remove `update_section` tool (only used by profile chats)
- Remove section locking logic from profile page

### Chats that stay

- **Floating brainstorm** (`/brainstorm`) — independent brainstorming conversations
- **Context item chats** (`/context/{itemId}`) — for managing contextual information
- **Piece improvement** (`/pipeline/.../improve`) — for refining content pieces

### Database cleanup

- Existing `brainstorm_chats` rows where `section` is one of the 5 profile section names can be deleted (or left to rot — they won't be queried anymore)
- Remove `guidelines` and `content_strategy` rows from `profile_sections`

## Future Work (not in this spec)

- **Voice & Tone:** Move `company_blog` + `blog_notes` from settings into Voice & Tone section with the same source URLs subcard pattern
- **Audience:** Separate enrichment treatment (user has a "better idea" for this)
