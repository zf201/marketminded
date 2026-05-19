# Social Planner — Design Spec

**Date:** 2026-05-18
**Status:** Draft, awaiting user review before writing-plans handoff.

## Summary

A new chat orchestrator — **`planner`** — that helps the user prepare a month of social posts in a single shared calendar per team. The agent is a hybrid researcher + planner: it pulls from existing Topics, SocialPosts, and ContentPieces (only those not yet marked "used"), brainstorms fresh ideas (with `web_search` + `fetch_url`) for empty slots driven by the team's posting-day cadence, and writes entries via tool calls. A separate read-only **Calendar** page renders the month in a CSV-shaped table with ◀ / ▶ month navigation and a CSV export that matches the layout the team already shares with customers.

## Goals

- Replicate the team's existing CSV calendar format (one idea per day, image headline + LinkedIn/Instagram/Facebook copy + notes) as first-class data.
- Make planning conversational: agent proposes a month, user iterates by chat.
- Reuse the existing content backlog: every Topic / SocialPost / ContentPiece has a `used` flag the agent respects.
- Export the visible month to CSV identically to the current spreadsheet format.

## Non-goals (YAGNI)

- Multiple calendars per team.
- Year view, drag-and-drop, inline cell editing.
- Auto-publishing or social-platform integrations.
- `short_video` / Reels / TikTok platform.
- Image generation (we persist `image_prompt` + `image_headline`; generation comes later).
- ICS / Google Calendar export.
- Approval workflows beyond a light `status` enum.

## Architecture

A new chat-orchestrator type **`planner`** joins the existing four (`brand`, `topics`, `writer`, `funnel`). It owns the conversation surface; the Calendar page is a thin read-only renderer over the data the agent writes.

- **New sidebar entry "Planner"** — chat surface using `ChatPromptBuilder::plannerPrompt()` + a fresh tool handler (`CalendarEntryToolHandler`). Mirrors the pattern of `brand` / `topics` / `writer` / `funnel`.
- **New sidebar entry "Calendar"** — read-only month table + ◀ / ▶ + Export CSV.
- **New tables** `content_calendars` (singleton per team, cadence override) and `calendar_entries` (one row per scheduled day).
- **New `used` flag** on `topics`, `social_posts`, `content_pieces`.
- **New team setting** `posting_days` (default cadence).

## Data model

### New table: `content_calendars`
One row per team.

| Column | Type | Notes |
|--|--|--|
| `id` | bigint pk | |
| `team_id` | fk teams, unique | |
| `posting_days` | json nullable | Per-calendar override of team default, e.g. `["mon","wed","fri"]`. |
| `timestamps` | | |

### New table: `calendar_entries`
One row per scheduled day.

| Column | Type | Notes |
|--|--|--|
| `id` | bigint pk | |
| `calendar_id` | fk content_calendars, cascade | |
| `team_id` | fk teams | Denormalised for fast scoping. |
| `scheduled_for` | date | Unique per calendar. |
| `title` | string | "Ideja" column. |
| `image_headline` | text nullable | "Grafika Copy" — short headline that goes on the image. |
| `image_prompt` | text nullable | Prompt to generate the image, separate from the headline. |
| `linkedin_copy` | text nullable | |
| `instagram_copy` | text nullable | |
| `facebook_copy` | text nullable | |
| `notes` | text nullable | Sources / author info (CSV's last column). |
| `source_topic_id` | fk topics nullable, nullOnDelete | Provenance — nulls = fresh brainstorm. |
| `source_social_post_id` | fk social_posts nullable, nullOnDelete | |
| `source_content_piece_id` | fk content_pieces nullable, nullOnDelete | |
| `status` | string default `draft` | `draft` \| `ready` \| `published`. |
| `timestamps` | | |

Indexes: `(calendar_id, scheduled_for)` unique, `(team_id, scheduled_for)`.

### `teams` — add column
`posting_days` json nullable — team-level default cadence.

### `used` flag
Add `used` boolean (default `false`, indexed) to:
- `topics` — new column, does not collide with the existing `status` enum. (Topics-agent logic reads `status in ['available','used']` for backlog rendering; `used` is the planner-specific consumed marker.)
- `social_posts` — broader than `posted_at`: covers "scheduled into a calendar" without claiming it was actually published.
- `content_pieces` — no existing equivalent.

### Marking semantics
- Setting an entry's `source_*_id` flips the linked entity's `used=true` in the same transaction.
- Removing the entry or clearing the link does **not** auto-unmark — user unmarks manually.
- All "find available pool" queries filter `used=false`.

## Planner agent

### Prompt structure
`ChatPromptBuilder::plannerPrompt()` injects, every turn:
- Date header (existing helper).
- Brand profile block (existing `buildProfileText`).
- Currently-focused month (year-month from conversation state or "this month" default).
- `posting_days` cadence for the calendar.
- Existing entries for that month: `date · title · which platform copies are filled`.
- Available pool: top N unused topics, social posts, content pieces (truncated, with ids).
- Tool-call discipline section (mirroring the funnel agent's pattern: nothing is "saved" unless the corresponding tool was called this turn).

### Tools
| Tool | Purpose |
|--|--|
| `propose_entries(entries[])` | Required for filling empty days. Each entry: `{scheduled_for, title, image_headline?, image_prompt?, linkedin_copy?, instagram_copy?, facebook_copy?, notes?, source_topic_id?, source_social_post_id?, source_content_piece_id?}`. Creates rows; flips linked entities to `used=true`. |
| `update_entry(id, fields)` | Patch one entry. |
| `delete_entry(id)` | Drop one entry. Does NOT auto-unmark its source. |
| `mark_used(type, id, used)` | Flip `used` on a `topic` \| `social_post` \| `content_piece`. |
| `list_available_pool()` | Read-only refresh of the unused pool mid-conversation. |
| `fetch_url(url)` | Shared. |
| `web_search(query)` | Shared with Topics agent. |

### Conversation flow (happy path)
1. User opens Planner. Agent sees the month's empty Mon/Wed/Fri slots and the unused pool.
2. Agent: "I see 12 posting days this month, 3 unused topics that fit, plus 1 published blog. Want me to fill these and brainstorm 8 fresh ones?"
3. On confirmation, agent calls `propose_entries` with the full month in one call.
4. User iterates: "rewrite May 8 LinkedIn", "swap the May 15 idea for something on EU regulation" → `update_entry` per fix.

## UI

### Planner page (`/planner`)
- Standard chat layout matching the other orchestrators (Flux, single `flux:main`).
- Above the input: month being planned with ◀ / ▶ to switch, plus a "Posting days: Mon · Wed · Fri" chip that opens a small editor for the calendar's `posting_days`.
- No table here — the conversation is the workspace.

### Calendar page (`/calendar`)
- Read-only table for one month. Columns mirror the CSV exactly: `Day` (date number) · `Weekday` · `Idea` · `Image Headline` · `LinkedIn` · `Instagram` · `Facebook` · `Notes`.
- One row per `calendar_entry`, sorted by `scheduled_for`.
- Empty scheduled days (per `posting_days`) render as faint "—" rows so gaps are visible. Non-posting days don't appear.
- Per-row badge when sourced from a Topic / SocialPost / ContentPiece — links back to that entity.
- Header controls: ◀ Month ▶ switcher, **Export CSV** button.

### Existing pages — minimal additions
- "Mark as used" / "Mark as unused" toggle on each card on Topics, Funnel posts list, Content pieces list.
- Items with `used=true` get a muted style + small "used" badge.

### CSV export
- Route: `GET /calendar/export?month=YYYY-MM`.
- Header row matches the sample format: `<day-month-marker>,,Ideja,Grafika Copy,LinkedIn Copy,Instagram Copy,Facebook Copy,Opombe / Avtor slike` — localised by team's `content_language` (Slovenian when language=sl, English equivalents otherwise).
- One row per entry, columns in the same order. Empty scheduled days export as blank rows.
- Filename: `{team-slug}-calendar-{YYYY-MM}.csv`.

## Testing

### Unit tests
- `tests/Unit/Services/ChatPromptBuilderPlannerTest.php` — verify the `planner` prompt includes brand profile, month context, posting-days block, unused-pool block, and the required tool-discipline language. Pin sections present, not exact wording.
- `tests/Unit/Services/Planner/CalendarEntryToolHandlerTest.php` — propose, update, delete; verify `used` flips on linked entities; verify date uniqueness per calendar; verify team scoping.
- `tests/Unit/Services/Planner/MarkUsedToolHandlerTest.php` — toggle used on each of the three entity types; verify team scoping.

### Feature tests
- `tests/Feature/PlannerChatTest.php` — full turn: user message → tool call → entries persisted → response rendered.
- `tests/Feature/CalendarPageTest.php` — month nav, read-only render, empty-day rendering, source badges.
- `tests/Feature/CalendarCsvExportTest.php` — export matches sample column order, blank rows where days have no entry, locale-correct headers.

## Migrations and rollout

- Three new migrations: `create_content_calendars_table`, `create_calendar_entries_table`, `add_used_to_topics_social_posts_content_pieces_and_posting_days_to_teams`. All additive, all reversible. No destructive ops.
- No feature flag required. New sidebar entries are simply absent for teams that haven't opened the page yet; calendar is lazily created on first Planner open.

## Open / deferred questions

- CSV header language: spec says localise by `content_language`; if the team prefers Slovenian-only for the customer-facing export, flip the header-resolution logic accordingly. Easy to swap later.
