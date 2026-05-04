# Topic cards: sources disclosure + copy-as-markdown

**Date:** 2026-05-04
**Branch:** `feature/topic-sources-and-copy`
**Status:** approved, ready for implementation plan

## Why

Team feedback on the topics backlog page (`⚡topics.blade.php`): users want to (1) see the research sources backing each topic without leaving the page, and (2) copy a topic into other tools (docs, Slack, briefs) as portable markdown. Both are read-only affordances on existing data — no schema changes.

## Scope

Two additions to each topic card on the topics page. Nothing else changes.

### Feature 1 — Sources disclosure

A collapsible "Sources" section on each card, mirroring the existing reasoning disclosure used for chat assistant messages (`⚡create-chat.blade.php` lines 677–691).

**Location:** inside the `flux:card` in `⚡topics.blade.php`, between the title/angle block (ends line 80) and the score/chat row (starts line 87).

**Conditional rendering:** if `$topic->sources` is empty → render nothing. Do not render an empty disclosure.

**Markup pattern:** Alpine `x-data="{ open: false }"` wrapper, a toggle button labeled `Sources ({{ count($topic->sources) }})` with a chevron that rotates on open, and a panel revealed via `x-show="open" x-cloak`.

**Source rendering:** for each string in `$topic->sources`:
- If `filter_var($source, FILTER_VALIDATE_URL)` returns truthy → render as `<a href="{{ $source }}" target="_blank" rel="noopener noreferrer">` whose visible text is the host (e.g. `nytimes.com`, derived via `parse_url($source, PHP_URL_HOST)` with any leading `www.` stripped) and whose `title` attribute is the full URL.
- Otherwise → render the string as-is, escaped.

**Styling:** match the chat reasoning disclosure exactly — outer wrapper uses `text-xs text-zinc-500`; the open panel uses `mt-2 rounded-md border border-zinc-700 bg-zinc-900/50 p-2 text-xs text-zinc-400`. No Livewire round-trip; pure Alpine.

### Feature 2 — Copy-as-markdown button

A button that copies the topic as markdown to the clipboard.

**Location:** in the bottom row of the card, immediately to the left of the existing chat icon link (`⚡topics.blade.php` line 100).

**Markup:** `flux:button variant="ghost" size="xs"` with `icon="document-duplicate"`. On click, calls `navigator.clipboard.writeText($data.md)` and toggles a `copied` flag for ~1.5s, during which the icon swaps to `check` (use a second `flux:button` toggled via `x-show`, since Flux button icons are static per-instance).

**Markdown payload:**

```
# {title}

{angle}

## Sources
- {source1}
- {source2}
```

The `## Sources` block (heading and list) is omitted when `sources` is empty. Sources are written as raw strings (URLs stay as URLs — markdown autolinks them in most renderers). Title and angle are inserted verbatim; no markdown escaping (titles in this product are plain phrases, not arbitrary user input with markdown metachars).

**Build site:** server-side in the `@foreach` loop. The complete markdown string is emitted into the Alpine component via `x-data="{ copied: false, md: @js($markdown) }"`. This avoids any client-side string assembly.

**No Livewire round-trip.** Clipboard is a browser API; the server already has the string.

## Out of scope

- No new columns or migrations — `sources` already exists on `topics`.
- No changes to how sources are populated by the brainstorm agent (`TopicToolHandler`).
- No changes to topic cards rendered elsewhere (e.g. chat saved-topic cards). Only `⚡topics.blade.php` changes.
- No clipboard fallback for insecure contexts. Production runs over HTTPS; `navigator.clipboard` is available.

## Testing

A single Pest feature test against the Livewire `topics` component (model is `App\Models\Topic`; component lives in `resources/views/pages/teams/⚡topics.blade.php`). Three assertions:

1. **Sources disclosure renders when sources present.** Create a topic with two sources, render, assert the page contains `Sources (2)` and the literal source strings.
2. **Sources disclosure omitted when sources empty.** Create a topic with `sources: []`, render, assert the page does not contain `Sources (`.
3. **Copy button embeds expected markdown.** Create a topic with title, angle, two sources; render; assert the page contains the JSON-encoded markdown payload (i.e. that `@js($markdown)` produced what we expect — covers conditional `## Sources`).

No browser-level test for clipboard — `navigator.clipboard` is not worth mocking and the call is one line.

## Files touched

- `resources/views/pages/teams/⚡topics.blade.php` — add disclosure, copy button, build `$markdown` per topic in the loop.
- `tests/Feature/` — new test file for the topics page (path TBD by plan).

## Risks

Low. Both features are presentational additions to one Blade file; no data writes, no new endpoints, no schema. The disclosure pattern is copied from working chat code. Clipboard API is well-supported and the page is HTTPS-only in production.
