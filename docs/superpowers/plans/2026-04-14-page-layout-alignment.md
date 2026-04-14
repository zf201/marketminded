# Page Layout Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify the content frame across all Livewire pages rendered directly inside `flux:main` so headers, gutters, and content widths are visually consistent across the app.

**Architecture:** Each in-scope page uses two wrappers:
- Header row: `flex items-center justify-between px-6 py-3`
- Content: `mx-auto max-w-5xl px-6 py-4`

No new components are introduced. The repetition of five identical wrapper divs across pages is explicitly accepted — extracting a shared component is a separate, non-goal refactor.

**Tech Stack:** Laravel 13, Livewire (Volt-style single-file components), Flux UI, Tailwind CSS.

**Spec:** [2026-04-14-page-layout-alignment-design.md](../specs/2026-04-14-page-layout-alignment-design.md)

**Pages in scope:** Topics, Create, Chat (Create-Chat), AI Log, Brand Intelligence. Edit Team is explicitly **out of scope** — it uses `<x-pages::settings.layout>`.

**Testing approach:** No automated tests; this is pure markup. Each task ends with a visual check via the dev server (`./vendor/bin/sail up -d` already assumed running; otherwise boot it). A single "validate everything" task at the end sweeps all pages side-by-side. If you are working on a branch without a running sail stack, call that out before starting.

---

### Task 1: Align Topics page

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡topics.blade.php`

Topics currently has the right *look* (no `max-w-*` cap, `px-6` gutters), but to standardise with the rest of the app it needs to adopt the `max-w-5xl` cap. The empty-state block and the grid block both need to live inside the new content wrapper.

- [ ] **Step 1: Open the file and locate the markup region**

Markup starts at line 46 with `<div>`. The header row (lines 47–57) is already correct. The two branches we must wrap are:
- Empty state: lines 59–69 (the `@if ($this->topics->isEmpty())` block).
- Grid: lines 70–130 (the `@else` branch).

- [ ] **Step 2: Replace the content branches with a wrapped version**

Change the `@if`/`@else`/`@endif` block (starting line 59, ending line 131) from the current form to:

```blade
    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->topics->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="light-bulb" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No topics yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Start a Brainstorm topics conversation to discover content ideas.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create')" wire:navigate>
                        {{ __('New brainstorm') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->topics as $topic)
                    <div class="flex flex-col">
                        <flux:card class="flex flex-1 flex-col p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <flux:heading>{{ $topic->title }}</flux:heading>
                                    <flux:text class="mt-1 text-sm">{{ $topic->angle }}</flux:text>
                                </div>

                                <flux:modal.trigger :name="'delete-topic-'.$topic->id">
                                    <flux:button variant="ghost" size="xs" icon="trash" />
                                </flux:modal.trigger>
                            </div>

                            <div class="mt-auto flex items-center gap-2 pt-3">
                                <flux:text class="shrink-0 text-xs text-zinc-500">{{ __('Score') }}</flux:text>
                                <input
                                    type="range"
                                    min="1"
                                    max="10"
                                    value="{{ $topic->score ?? 5 }}"
                                    wire:change="updateScore({{ $topic->id }}, $event.target.value)"
                                    class="h-1.5 flex-1 cursor-pointer accent-indigo-500"
                                />
                                <flux:text class="w-5 shrink-0 text-xs font-medium text-zinc-400">{{ $topic->score ?? '-' }}</flux:text>

                                @if ($topic->conversation_id)
                                    <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $topic->conversation_id]) }}" wire:navigate class="ml-2 inline-flex shrink-0 items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300">
                                        <flux:icon name="chat-bubble-left" variant="mini" class="size-3.5" />
                                        {{ __('Chat') }}
                                    </a>
                                @endif

                                @if ($topic->status === 'used')
                                    <flux:badge variant="pill" size="sm" color="green" class="ml-2">{{ __('Used') }}</flux:badge>
                                @endif
                            </div>
                        </flux:card>

                        <flux:modal :name="'delete-topic-'.$topic->id" class="min-w-[22rem]">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Delete topic?') }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('This topic will be removed from your backlog.') }}</flux:text>
                                </div>
                                <div class="flex gap-2">
                                    <flux:spacer />
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" wire:click="deleteTopic({{ $topic->id }})">
                                        {{ __('Delete') }}
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
```

**Key diffs** in that block:
- Outer `<div class="mx-auto max-w-5xl px-6 py-4">` now wraps both branches.
- The previous grid's `px-6 py-4` is removed (the wrapper now provides it).
- All other markup inside the grid is unchanged.

- [ ] **Step 3: Visually verify**

Open `/{team}/topics` in the browser at desktop width. Expected:
- Content width matches the other pages once they are updated.
- Header row unchanged.
- Two-column grid fits cleanly inside the capped container.

- [ ] **Step 4: Commit**

```bash
cd marketminded-laravel
git add resources/views/pages/teams/⚡topics.blade.php
git commit -m "refactor(topics): adopt max-w-5xl content frame"
```

---

### Task 2: Align Create page

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create.blade.php:62`

- [ ] **Step 1: Change the content wrapper width**

On line 62, change:

```blade
    <div class="mx-auto max-w-3xl px-6 py-4">
```

to:

```blade
    <div class="mx-auto max-w-5xl px-6 py-4">
```

No other changes.

- [ ] **Step 2: Visually verify**

Open `/{team}/create` in the browser. Expected:
- Conversation list is noticeably wider than before but still capped on large monitors.
- Header row unchanged.

- [ ] **Step 3: Commit**

```bash
cd marketminded-laravel
git add resources/views/pages/teams/⚡create.blade.php
git commit -m "refactor(create): widen content frame to max-w-5xl"
```

---

### Task 3: Align Chat page

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php:379,501`

Chat has a flex-column layout that fills the viewport height (line 342). We leave that alone and only touch the two horizontal-width wrappers: the messages column and the composer column.

- [ ] **Step 1: Widen the messages column**

On line 379, change:

```blade
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
```

to:

```blade
        <div class="mx-auto flex max-w-5xl flex-col-reverse px-6 py-4">
```

- [ ] **Step 2: Widen the composer column**

On line 501, change:

```blade
        <div class="mx-auto w-full max-w-3xl px-6 pb-4 pt-2">
```

to:

```blade
        <div class="mx-auto w-full max-w-5xl px-6 pb-4 pt-2">
```

No other changes. Do **not** touch the `max-w-2xl` on line 392 (that is the user-message bubble cap — a separate concern). Do **not** touch the `max-w-xl`/`max-w-2xl` mode-selection cards (lines 453, 481) — those are intentional.

- [ ] **Step 3: Visually verify**

Open a chat at `/{team}/create/<conversation>` in the browser. Expected:
- Messages area wider than before, composer aligned with it.
- Streaming still works; scroll-to-bottom still works.
- User message bubbles still have their own narrower cap (unchanged).

- [ ] **Step 4: Commit**

```bash
cd marketminded-laravel
git add resources/views/pages/teams/⚡create-chat.blade.php
git commit -m "refactor(chat): widen messages and composer to max-w-5xl"
```

---

### Task 4: Align AI Log page

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡ai-log.blade.php:68-121`

AI Log is the outlier with no `px-6` or header wrapper at all. Restructure to match the standard frame.

- [ ] **Step 1: Replace the markup region**

Replace the block from line 68 (`<div>`) to the closing `</div>` at line 121 with:

```blade
<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div>
            <flux:heading size="xl">{{ __('AI Log') }}</flux:heading>
            <flux:subheading>{{ __('AI usage and spend across all conversations.') }}</flux:subheading>
        </div>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        {{-- Summary cards --}}
        <div class="flex gap-4">
            <flux:card class="flex-1 text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Cost (30d)') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">${{ number_format($summary['total_cost'], 4) }}</div>
            </flux:card>
            <flux:card class="flex-1 text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('AI Messages') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ $summary['total_messages'] }}</div>
            </flux:card>
            <flux:card class="flex-1 text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Tokens') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($summary['total_tokens']) }}</div>
            </flux:card>
        </div>

        {{-- Log table --}}
        <div class="mt-8">
            @if (count($entries) > 0)
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Conversation') }}</flux:table.column>
                        <flux:table.column>{{ __('Model') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In Tokens') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Out Tokens') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cost') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($entries as $entry)
                            <flux:table.row>
                                <flux:table.cell variant="strong">{{ Str::limit($entry['conversation_title'], 40) }}</flux:table.cell>
                                <flux:table.cell>{{ $entry['model'] ? Str::afterLast($entry['model'], '/') : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($entry['input_tokens']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($entry['output_tokens']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $entry['cost'] > 0 ? '$' . number_format($entry['cost'], 4) : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $entry['created_at'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @else
                <flux:card class="py-8 text-center">
                    <flux:icon name="chart-bar" class="mx-auto text-zinc-500" />
                    <flux:text class="mt-2">{{ __('No AI usage recorded yet.') }}</flux:text>
                </flux:card>
            @endif
        </div>
    </div>
</div>
```

**Key diffs:**
- New `px-6 py-3` header row wrapping heading + subheading (moved inside a nested div so heading and subheading stay stacked).
- New `mx-auto max-w-5xl px-6 py-4` content wrapper around summary cards and table.
- Removed the `mt-8` from the summary cards row (the wrapper's `py-4` now provides that spacing from the header).
- Kept `mt-8` on the table block (rhythm between summary and table).

- [ ] **Step 2: Visually verify**

Open `/{team}/log` in the browser. Expected:
- Header matches Topics / Create visually (same padding, same size).
- Summary cards and table sit inside the standard content frame.
- Table still renders entries correctly.

- [ ] **Step 3: Commit**

```bash
cd marketminded-laravel
git add resources/views/pages/teams/⚡ai-log.blade.php
git commit -m "refactor(ai-log): adopt standard page frame"
```

---

### Task 5: Align Brand Intelligence page

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡brand-intelligence.blade.php` (root `<div>` region around line 369 and the matching closing tag)

This file is large (640+ lines). Only the outermost wrapper and the heading region need to change. Inner section rows (`flex flex-col lg:flex-row gap-4 lg:gap-6`) and modal widths are unchanged.

- [ ] **Step 1: Locate the root markup**

The root opens at `<div>` on line 369 with:

```blade
<div>
    <flux:heading size="xl">{{ __('Brand Intelligence') }}</flux:heading>
    <flux:subheading>{{ __('Your brand profile — company info, positioning, audience, and voice. Edit directly or build via AI chat.') }}</flux:subheading>

    {{-- Section 1: Company --}}
    <flux:separator variant="subtle" class="my-8" />

    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
```

Find the matching closing `</div>` for the line-369 root. In this file the root's closing `</div>` is the final closing tag — the file ends with it (no siblings after).

- [ ] **Step 2: Replace the opening region**

Change the opening region (starting line 369) from:

```blade
<div>
    <flux:heading size="xl">{{ __('Brand Intelligence') }}</flux:heading>
    <flux:subheading>{{ __('Your brand profile — company info, positioning, audience, and voice. Edit directly or build via AI chat.') }}</flux:subheading>

    {{-- Section 1: Company --}}
    <flux:separator variant="subtle" class="my-8" />
```

to:

```blade
<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div>
            <flux:heading size="xl">{{ __('Brand Intelligence') }}</flux:heading>
            <flux:subheading>{{ __('Your brand profile — company info, positioning, audience, and voice. Edit directly or build via AI chat.') }}</flux:subheading>
        </div>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        {{-- Section 1: Company --}}
        <flux:separator variant="subtle" class="my-8" />
```

- [ ] **Step 3: Close the new content wrapper before the root's closing tag**

At the very end of the file, the current last two lines are:

```blade
    </div>{{-- or similar closing markup --}}
</div>
```

The precise trailing markup may include modal triggers or @if blocks before the final closing `</div>` of the root. Identify the **final** closing `</div>` (the one that matches the line-369 root) and insert a new `</div>` immediately **before** it to close the `mx-auto max-w-5xl px-6 py-4` wrapper. Concretely, if the file ends with:

```blade
        </something>
    </div>
</div>
```

change it to:

```blade
        </something>
    </div>
    </div>
</div>
```

Use your editor's bracket-matching feature to confirm you are matching the correct pair. Re-read the tail of the file after the edit and confirm the tag balance visually.

- [ ] **Step 4: Visually verify**

Open `/{team}/brand-intelligence` in the browser. Expected:
- Heading and subheading sit inside a `px-6 py-3` header row, matching the other pages.
- Separator and all two-column sections are inside a `max-w-5xl px-6 py-4` container.
- Modals (edit persona, etc.) still open and close normally.
- Inline editing of positioning/voice still works.
- No raw `</div>` appearing as text (sign of tag mismatch).

- [ ] **Step 5: Commit**

```bash
cd marketminded-laravel
git add resources/views/pages/teams/⚡brand-intelligence.blade.php
git commit -m "refactor(brand-intelligence): adopt standard page frame"
```

---

### Task 6: Cross-page visual sweep

**Files:** none (validation only)

- [ ] **Step 1: Walk the five pages side-by-side**

With the dev server running, navigate through all five pages at the same viewport width:

1. `/{team}/topics`
2. `/{team}/create`
3. `/{team}/create/<conversation>` (pick any existing chat)
4. `/{team}/log`
5. `/{team}/brand-intelligence`

On each page confirm:
- Header padding and alignment match.
- Gutter widths match.
- Content max-width is the same (`max-w-5xl`).
- No horizontal scrollbars, no content touching the sidebar edge.

- [ ] **Step 2: Repeat at a narrower viewport**

Resize the browser to roughly 900px wide (below `5xl` = 1024px). Confirm every page goes edge-to-edge with only the `px-6` gutter, and the grids/tables/cards still lay out correctly.

- [ ] **Step 3: Repeat at mobile width (~420px)**

Confirm all pages still read correctly, nothing is cut off, composer + input still usable on chat.

- [ ] **Step 4: Report completion**

If everything renders cleanly, this task is done — no commit needed (no files changed). If regressions are found, fix them and create a follow-up commit with message `fix(layout): <what broke>`.

---

## Self-review checklist

- **Spec coverage:** Each of the five in-scope pages in the spec has a dedicated task. The excluded page (edit) is explicitly noted as out of scope. ✓
- **Placeholders:** No TBD, TODO, or "add appropriate…" language. ✓
- **Type consistency:** Every task uses the same two Tailwind class strings (`flex items-center justify-between px-6 py-3` and `mx-auto max-w-5xl px-6 py-4`). ✓
- **Code completeness:** Each task shows the exact before/after markup for the regions being touched. ✓
