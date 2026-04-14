# Page Layout Alignment — Design

## Problem

Pages inside `flux:main` use inconsistent content-width wrappers. The recently
improved Topics page uses the full inner container width, while Chat, Create,
and AI Log pages use a narrower `max-w-3xl` (~768px) column or no wrapper at
all. This makes navigation between pages feel visually unstable: headers jump,
gutters change, and content areas resize unpredictably.

## Goal

Every page rendered inside `flux:main` should share the same content frame so
moving between pages feels consistent.

## Decision

Adopt a single standard for all content pages:

- **Header**: `mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3`
- **Content**: `mx-auto max-w-5xl px-6 py-4`

Both rows are capped at the same `max-w-5xl` so the header's heading/button
sit flush with the left/right edges of the content block directly below. A
header that spans the full sidebar-adjacent width while content caps at 5xl
creates a visible misalignment; matching the caps fixes that.

`max-w-5xl` (1024px) is the chosen width. Rationale: it is wide enough for
cards, tables, and two-column grids to breathe, but narrow enough to keep
chat message lines readable on wide monitors. On a typical laptop with the
sidebar open the container is smaller than 5xl, so the constraint only kicks
in on large displays — where uncapped pages currently feel sprawling.

This narrows Topics slightly from its current fully-uncapped behavior. That
trade is accepted in exchange for uniform page chrome.

## Pages in scope

Livewire pages that render directly inside `flux:main` (via
`resources/views/layouts/app.blade.php`):

1. `pages/teams/⚡topics.blade.php`
2. `pages/teams/⚡create.blade.php`
3. `pages/teams/⚡create-chat.blade.php`
4. `pages/teams/⚡ai-log.blade.php`
5. `pages/teams/⚡brand-intelligence.blade.php`

### Out of scope

- **`pages/teams/⚡edit.blade.php`** — despite living under `teams/`, this
  page uses `<x-pages::settings.layout>` (the settings two-column layout
  shared with profile, appearance, and security). Applying our frame here
  would break consistency with the rest of the settings area.
- Auth pages (`layouts/auth.blade.php`).
- Other settings pages (already use `settings.layout`).
- Modal content.

## Per-page changes

### Topics (`⚡topics.blade.php`)

Wrap the grid (currently `grid gap-2 px-6 py-4 sm:grid-cols-2`) and the empty
state in a `mx-auto max-w-5xl px-6 py-4` container. Remove the `px-6 py-4`
from the grid itself since the wrapper now provides it.

### Create (`⚡create.blade.php`)

Change the content wrapper on line 62 from
`mx-auto max-w-3xl px-6 py-4` → `mx-auto max-w-5xl px-6 py-4`.

### Chat (`⚡create-chat.blade.php`)

This page is a flex column filling viewport height, which is different from
other pages. Two wrappers change:

- Messages container (line 379): `mx-auto flex max-w-3xl flex-col-reverse px-6 py-4`
  → `mx-auto flex max-w-5xl flex-col-reverse px-6 py-4`
- Composer container (line 501): `mx-auto w-full max-w-3xl px-6 pb-4 pt-2`
  → `mx-auto w-full max-w-5xl px-6 pb-4 pt-2`

The full-height flex layout stays — it is needed for the sticky composer at
the bottom. Only the horizontal width changes.

### AI Log (`⚡ai-log.blade.php`)

Currently the page is a bare `<div>` with no header wrapper or content
wrapper — it relies on whatever padding `flux:main` provides, which does not
match Topics.

Restructure to match the standard frame:

```blade
<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div>
            <flux:heading size="xl">{{ __('AI Log') }}</flux:heading>
            <flux:subheading>{{ __('AI usage and spend across all conversations.') }}</flux:subheading>
        </div>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        {{-- summary cards and table --}}
    </div>
</div>
```

Remove the `mt-8` margin from the summary cards block (the wrapper's `py-4`
replaces it) but keep `mt-8` between summary cards and the table for vertical
rhythm.

### Brand Intelligence (`⚡brand-intelligence.blade.php`)

Current root (line 369) is a bare `<div>` with a heading, subheading, and a
series of two-column `flex flex-col lg:flex-row` sections. No `px-6` anywhere
— content sits flush against whatever padding `flux:main` provides.

Apply the same standard frame: wrap the heading block in `px-6 py-3`, and
wrap the rest of the content (separator + all sections) in
`mx-auto max-w-5xl px-6 py-4`. Do not touch the inner two-column sections or
modal widths (e.g. `max-w-lg` on edit modals stays).

## Non-goals

- No changes to modals, forms inside modals, or the sidebar.
- No changes to visual hierarchy, typography, colors, or spacing beyond what
  is required to apply the new wrapper.
- No component extraction (e.g. a shared `<x-page-frame>` component). That
  may be worth doing later, but doing it now widens the blast radius of this
  change. Repeating six `mx-auto max-w-5xl px-6 py-4` lines is fine.

## Testing

Manual browser check for each page:

1. Sidebar-collapsed view on wide monitor — content is capped at 5xl, gutters
   match across pages.
2. Default desktop width — all pages use roughly the same visible width.
3. Mobile — `px-6` still applies; `max-w-5xl` is effectively unbounded at
   smaller widths.
4. Chat specifically — messages, composer, and scroll behavior unchanged.

No automated test coverage needed; this is pure markup.
