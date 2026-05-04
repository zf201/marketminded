# Topic sources disclosure + copy-as-markdown — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a collapsible Sources disclosure and a copy-as-markdown button to each topic card on the topics page (`⚡topics.blade.php`).

**Architecture:** Pure presentational changes in one Blade/Livewire file. The disclosure is an Alpine `x-data="{ open }"` toggle that mirrors the existing chat reasoning disclosure. The copy button uses Alpine + `navigator.clipboard.writeText`, with the markdown payload built server-side per topic in the `@foreach` loop and passed in via `@js`.

**Tech Stack:** Laravel 13, Livewire (volt-style class in Blade), Flux UI, Alpine.js, Tailwind, Pest. All commands run via Sail (`./vendor/bin/sail`).

**Branch:** `feature/topic-sources-and-copy` (already created from `main`, spec already committed).

**Spec:** `docs/superpowers/specs/2026-05-04-topic-sources-and-copy-design.md`

---

## File Structure

- **Modify:** `resources/views/pages/teams/⚡topics.blade.php`
  - Inside the `@foreach ($this->topics as $topic)` loop: build a `$markdown` string per topic.
  - Inside the `flux:card`: insert a sources disclosure block between the title/angle block and the score row.
  - Inside the bottom row of the card: insert a copy-markdown button to the left of the chat icon.

- **Create:** `tests/Feature/Teams/TopicsTest.php`
  - Pest feature test using `Livewire::test('pages::teams.topics', ['current_team' => $team])`.

No new models, factories, migrations, routes, or service classes.

---

## Test Conventions in This Codebase

- Pest, not PHPUnit class style.
- Run via Sail: `./vendor/bin/sail test --filter=<name>`.
- Livewire component name for this page is `pages::teams.topics` (confirmed: `routes/web.php:21`).
- No `Topic` factory exists — create rows with `Topic::create([...])` directly. Required fillable fields: `team_id`, `title`. Optional: `angle`, `sources` (array, cast to JSON), `status`, `score`, `conversation_id`.
- Default `status` is `'available'` (set by handler) — must NOT be `'deleted'` or the page filters it out (`⚡topics.blade.php:35`).
- Auth pattern (copied from `tests/Feature/Teams/BrandIntelligenceTest.php`): create a `User`, create a `Team`, attach user with `TeamRole::Owner`, set `current_team_id`, then `actingAs($user)` before `Livewire::test(...)`.

---

## Task 1: Test scaffolding — page renders for an authenticated team owner

**Files:**
- Create: `tests/Feature/Teams/TopicsTest.php`

- [ ] **Step 1: Create the test file with a single rendering assertion**

Create `tests/Feature/Teams/TopicsTest.php` with:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use Livewire\Livewire;

function makeOwnerWithTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('topics page renders for an authenticated team owner', function () {
    [$user, $team] = makeOwnerWithTeam();

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertOk();
});
```

- [ ] **Step 2: Run the test — expect PASS**

Run: `./vendor/bin/sail test --filter='topics page renders for an authenticated team owner'`

Expected: PASS. (The page already renders; this just validates the test scaffolding before we add feature tests.)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Teams/TopicsTest.php
git commit -m "test: scaffold topics page feature test"
```

---

## Task 2: Sources disclosure — failing test

**Files:**
- Modify: `tests/Feature/Teams/TopicsTest.php`

- [ ] **Step 1: Append two failing tests for the sources disclosure**

Append to `tests/Feature/Teams/TopicsTest.php`:

```php
test('topics page shows a sources disclosure when sources are present', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'A topic with sources',
        'angle' => 'Some angle.',
        'sources' => ['https://example.com/a', 'https://example.com/b'],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('Sources (2)')
        ->assertSee('https://example.com/a')
        ->assertSee('https://example.com/b');
});

test('topics page omits the sources disclosure when sources are empty', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'A topic without sources',
        'angle' => 'Some angle.',
        'sources' => [],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertDontSee('Sources (');
});
```

- [ ] **Step 2: Run the new tests — expect both to FAIL**

Run: `./vendor/bin/sail test --filter='sources disclosure'`

Expected: 2 tests, both FAIL on the `assertSee('Sources (2)')` assertion (the second test passes the `assertDontSee` only by accident before any change, so it may be green — that's fine; it locks in behavior).

---

## Task 3: Sources disclosure — implementation

**Files:**
- Modify: `resources/views/pages/teams/⚡topics.blade.php` (insert after line 80, before line 87 — i.e. between the title/angle block and the score row).

- [ ] **Step 1: Add the disclosure block inside the `flux:card`**

In `resources/views/pages/teams/⚡topics.blade.php`, locate this region (currently lines ~76–87):

```blade
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
```

Insert the following block immediately after the closing `</div>` of the title/angle row and before the score row:

```blade
                        @if (!empty($topic->sources))
                            <div class="mt-3 text-xs text-zinc-500" x-data="{ open: false }">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    class="inline-flex items-center gap-1 hover:text-zinc-300 transition-colors"
                                >
                                    {{ __('Sources') }} ({{ count($topic->sources) }})
                                    <svg x-bind:class="open ? 'rotate-180' : ''" class="size-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <ul x-show="open" x-cloak class="mt-2 space-y-1 rounded-md border border-zinc-700 bg-zinc-900/50 p-2 text-xs text-zinc-400">
                                    @foreach ($topic->sources as $source)
                                        <li class="break-all">
                                            @if (filter_var($source, FILTER_VALIDATE_URL))
                                                @php
                                                    $host = parse_url($source, PHP_URL_HOST) ?: $source;
                                                    $host = preg_replace('/^www\./', '', $host);
                                                @endphp
                                                <a href="{{ $source }}" target="_blank" rel="noopener noreferrer" title="{{ $source }}" class="hover:text-zinc-200 underline decoration-dotted">{{ $host }}</a>
                                            @else
                                                {{ $source }}
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
```

- [ ] **Step 2: Run the sources tests — expect PASS**

Run: `./vendor/bin/sail test --filter='sources disclosure'`

Expected: 2 tests, both PASS. The first sees `Sources (2)` and both URLs in the rendered HTML (URLs are present in `href` and `title` attributes even before the user clicks open). The second sees no `Sources (` substring because the `@if` guards it.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/teams/⚡topics.blade.php tests/Feature/Teams/TopicsTest.php
git commit -m "feat(topics): add sources disclosure to topic cards"
```

---

## Task 4: Copy-as-markdown — failing test

**Files:**
- Modify: `tests/Feature/Teams/TopicsTest.php`

- [ ] **Step 1: Append failing tests for the copy-markdown button**

Append to `tests/Feature/Teams/TopicsTest.php`:

```php
test('topics page embeds markdown payload including sources block when sources are present', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'How to ship faster',
        'angle' => 'Practical takes from teams that ship daily.',
        'sources' => ['https://example.com/a', 'https://example.com/b'],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('aria-label="Copy as markdown"', false)
        // The button presence and the embedded payload are both required.
        // The payload is emitted via `@js($markdown)` inside x-data, which
        // serialises the string with escaped newlines. Asserting structural
        // fragments avoids fragility around exact JS escaping rules.
        ->assertSee('# How to ship faster', false)
        ->assertSee('Practical takes from teams that ship daily.', false)
        ->assertSee('## Sources', false)
        ->assertSee('- https://example.com/a', false)
        ->assertSee('- https://example.com/b', false);
});

test('topics page embeds markdown payload without sources block when sources are empty', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'A bare topic',
        'angle' => 'Just an angle.',
        'sources' => [],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('aria-label="Copy as markdown"', false)
        ->assertSee('# A bare topic', false)
        ->assertDontSee('## Sources');
});
```

Note on the assertions:
- `@js($markdown)` in Blade calls `Js::from(...)`, which produces the same JSON-encoded string `json_encode()` does for plain UTF-8 strings. `assertSeeHtml` looks at the raw response without escaping our pre-escaped JSON.
- `aria-label="Copy as markdown"` is asserted (with `escape=false`) so we lock in that the button is identifiable.

- [ ] **Step 2: Run the new tests — expect both to FAIL**

Run: `./vendor/bin/sail test --filter='markdown payload'`

Expected: 2 tests, both FAIL — markdown is not yet emitted and the aria-label is not yet present.

---

## Task 5: Copy-as-markdown — implementation

**Files:**
- Modify: `resources/views/pages/teams/⚡topics.blade.php`

This task has two changes inside the `@foreach` loop: build the `$markdown` string before the card, and add the button inside the score row.

- [ ] **Step 1: Build the markdown string at the top of the loop body**

In `resources/views/pages/teams/⚡topics.blade.php`, locate the loop start (currently around line 73):

```blade
            @foreach ($this->topics as $topic)
                <div class="flex flex-col">
                    <flux:card class="flex flex-1 flex-col p-4">
```

Insert a `@php` block between `@foreach` and the first `<div>`:

```blade
            @foreach ($this->topics as $topic)
                @php
                    $markdown = '# ' . $topic->title . "\n\n" . ($topic->angle ?? '') . "\n";
                    if (!empty($topic->sources)) {
                        $markdown .= "\n## Sources\n";
                        foreach ($topic->sources as $source) {
                            $markdown .= '- ' . $source . "\n";
                        }
                    }
                @endphp
                <div class="flex flex-col">
                    <flux:card class="flex flex-1 flex-col p-4">
```

- [ ] **Step 2: Add the copy button to the bottom row**

Locate the bottom row of the card (currently around lines 87–109). The chat link starts with `<a href="{{ route('create.chat'`. Insert the copy button block immediately before that `@if ($topic->conversation_id)` block. The full edited region should read:

```blade
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

                            <div
                                x-data="{ copied: false, md: @js($markdown) }"
                                class="ml-2 inline-flex"
                            >
                                <button
                                    type="button"
                                    aria-label="Copy as markdown"
                                    @click="navigator.clipboard.writeText(md).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                                    class="inline-flex items-center text-xs text-zinc-500 hover:text-zinc-300"
                                >
                                    <svg x-show="!copied" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m9 5h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.5c0-.621.504-1.125 1.125-1.125h7.5c.621 0 1.125.504 1.125 1.125v8.625"/>
                                    </svg>
                                    <svg x-show="copied" x-cloak class="size-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </button>
                            </div>

                            @if ($topic->conversation_id)
                                <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $topic->conversation_id]) }}" wire:navigate class="ml-2 inline-flex shrink-0 items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300">
                                    <flux:icon name="chat-bubble-left" variant="mini" class="size-3.5" />
                                    {{ __('Chat') }}
                                </a>
                            @endif
```

Notes:
- The icon is the standard Heroicons "document-duplicate" path inlined as SVG (avoids depending on whether `flux:icon` accepts a dynamic name inside a button). Two `<svg>` elements — copy and check — are toggled by `x-show`.
- `@js($markdown)` produces a safe JS string literal in the `x-data`.

- [ ] **Step 3: Run the new tests — expect PASS**

Run: `./vendor/bin/sail test --filter='markdown payload'`

Expected: 2 tests, both PASS. The JSON-encoded markdown string from `@js($markdown)` appears verbatim in the rendered HTML, and the `aria-label` is present.

- [ ] **Step 4: Run the full topics test file — expect all PASS**

Run: `./vendor/bin/sail test --filter=TopicsTest`

Expected: 5 tests pass (1 scaffolding + 2 sources + 2 markdown).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/teams/⚡topics.blade.php tests/Feature/Teams/TopicsTest.php
git commit -m "feat(topics): add copy-as-markdown button to topic cards"
```

---

## Task 6: Manual smoke test in the browser

The clipboard API and Alpine `x-show` toggles are not exercised by the Pest tests. Verify them manually before declaring done.

- [ ] **Step 1: Confirm Sail and Vite are running**

```bash
./vendor/bin/sail ps
```

Expected: `marketminded-laravel.test-1`, `marketminded-pgsql-1`, `marketminded-queue-1` all `Running`. Vite dev server should also be running (`./vendor/bin/sail npm run dev` if not).

- [ ] **Step 2: Sign in and visit the topics page**

Open a team with at least one topic that has sources (run a Brainstorm conversation first if none exist). Navigate to `/teams/{team}/topics`.

Verify:
1. Each topic card with sources shows a `Sources (N)` toggle. Clicking it expands to show the list. URL sources render as the host name and link out in a new tab.
2. Cards with no sources show no toggle.
3. The copy button (document icon) sits to the left of the chat icon. Clicking it briefly swaps to a green check, then back. Pasting into a markdown editor produces the expected `# title / angle / ## Sources / -` output.

- [ ] **Step 3: Run the full test suite once more**

Run: `./vendor/bin/sail test`

Expected: green. (Sanity check that nothing else broke.)

- [ ] **Step 4: Push the branch**

```bash
git push -u origin feature/topic-sources-and-copy
```

Then open a PR for review/merge to `main`.

---

## Done criteria

- 5 new Pest tests pass.
- Full test suite green.
- Manual browser check: disclosure expands, copy works and pastes correct markdown, no regressions to the existing Score / Chat / Delete affordances.
- Branch pushed.
