# Topics feedback loop — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tell users their topic scoring trains the next brainstorm, and actually feed those scores into the brainstorm prompt as quality calibration.

**Architecture:** Two minimal edits to existing files. Part 1 adds one paragraph of helper text under the Topics page heading, gated on a non-empty backlog. Part 2 swaps the brainstorm prompt's flat de-dupe block for a scored, capped, recency-ordered "past topics" block.

**Tech Stack:** Laravel 13, Livewire (volt-style), Flux UI, Pest. Commands run via Sail.

**Branch:** `feature/topic-sources-and-copy` (continuing the same branch — same theme as the prior spec).

**Spec:** `docs/superpowers/specs/2026-05-04-topics-feedback-loop-design.md`

---

## File Structure

- **Modify:** `resources/views/pages/teams/⚡topics.blade.php`
  - Add a `<flux:subheading>` paragraph beneath the page heading. Renders only when `$this->topics->isNotEmpty()`.

- **Modify:** `app/Services/ChatPromptBuilder.php`
  - Replace the `$existingTopics` query (titles only, no order, no limit) with a `$pastTopics` query (title + score, `created_at` desc, limit 25).
  - Replace the `## Existing topics in backlog (do not propose duplicates)` block with the new `## Your past topics for this team (most recent 25)` block.

- **Modify:** `tests/Unit/Services/ChatPromptBuilderTopicsTest.php`
  - Update the existing `topics prompt includes existing backlog titles` test: the assertion `expect($prompt)->toContain('existing-topics')` must change because the wrapping XML element is renamed to `past-topics` in this work.
  - Add four new tests covering score formatting, the 25-cap, deletion exclusion (renamed), and empty-backlog omission.

- **Modify:** `tests/Feature/Teams/TopicsTest.php`
  - Add two tests for helper text presence/absence.

No new files, no migrations, no factories.

---

## Task Conventions for This Codebase

- All commands run via Sail: `./vendor/bin/sail test --filter=<name>`.
- Pest, not PHPUnit class style.
- The `ChatPromptBuilderTopicsTest.php` uses the shorthand `$user = User::factory()->create(); $team = $user->currentTeam;` — that pattern is fine to reuse for new unit tests in that file.
- The `TopicsTest.php` uses `makeOwnerWithTeam()` (defined in that file) — reuse it for the new feature tests.
- `Topic` has no factory; create rows with `Topic::create([...])`. Required: `team_id`, `title`. `score` is nullable integer; omit it to test the unrated case.

---

## Task 1: Helper text — failing tests

**Files:**
- Modify: `tests/Feature/Teams/TopicsTest.php` (append to end)

- [ ] **Step 1: Append two tests for helper text presence/absence**

Append to `tests/Feature/Teams/TopicsTest.php`:

```php
test('topics page shows scoring guidance when at least one topic exists', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Some topic',
        'angle' => 'Angle.',
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('Score topics 1–10')
        ->assertSee("Delete topics that aren't relevant");
});

test('topics page omits scoring guidance on an empty backlog', function () {
    [$user, $team] = makeOwnerWithTeam();

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertDontSee('Score topics 1–10');
});
```

- [ ] **Step 2: Run the new tests — expect first to FAIL, second to PASS**

Run: `./vendor/bin/sail test --filter='scoring guidance'`

Expected: 1 fail (the helper text doesn't exist yet) + 1 pass (no helper text on empty page is the current behaviour).

---

## Task 2: Helper text — implementation

**Files:**
- Modify: `resources/views/pages/teams/⚡topics.blade.php`

- [ ] **Step 1: Add the helper paragraph under the page heading**

Locate the page header in `resources/views/pages/teams/⚡topics.blade.php` (currently lines 47–57):

```blade
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Topics') }}</flux:heading>
            @if ($this->topics->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->topics->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create')" wire:navigate>
            {{ __('New brainstorm') }}
        </flux:button>
    </div>
```

Immediately after the closing `</div>` of that header row, insert a second container with the helper paragraph (still inside the outer `<div>` that wraps the whole page):

```blade
    @if ($this->topics->isNotEmpty())
        <div class="mx-auto w-full max-w-5xl px-6 pb-2">
            <flux:subheading>
                {{ __('Score topics 1–10 to teach the AI what good looks like — this directly improves your next brainstorm. Delete topics that aren\'t relevant. Score topics with weak or missing sources lower.') }}
            </flux:subheading>
        </div>
    @endif
```

The full edited region should read:

```blade
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Topics') }}</flux:heading>
            @if ($this->topics->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->topics->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create')" wire:navigate>
            {{ __('New brainstorm') }}
        </flux:button>
    </div>

    @if ($this->topics->isNotEmpty())
        <div class="mx-auto w-full max-w-5xl px-6 pb-2">
            <flux:subheading>
                {{ __('Score topics 1–10 to teach the AI what good looks like — this directly improves your next brainstorm. Delete topics that aren\'t relevant. Score topics with weak or missing sources lower.') }}
            </flux:subheading>
        </div>
    @endif
```

- [ ] **Step 2: Run the helper text tests — expect both PASS**

Run: `./vendor/bin/sail test --filter='scoring guidance'`

Expected: 2 tests pass.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/teams/⚡topics.blade.php tests/Feature/Teams/TopicsTest.php
git commit -m "feat(topics): add scoring guidance under page heading"
```

---

## Task 3: Brainstorm prompt — failing tests

**Files:**
- Modify: `tests/Unit/Services/ChatPromptBuilderTopicsTest.php`

This task does two things in one edit: (a) update the one existing test that asserts `existing-topics`, since the XML element is renamed to `past-topics` in this work; (b) append four new tests for the new behaviour.

- [ ] **Step 1: Update the existing assertion and append four new tests**

In `tests/Unit/Services/ChatPromptBuilderTopicsTest.php`, change the body of the existing test `topics prompt includes existing backlog titles` so its second assertion looks for `past-topics` instead of `existing-topics` (the title assertion stays). The full test should read:

```php
test('topics prompt includes existing backlog titles', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Existing Topic About Privacy',
        'angle' => 'An angle',
        'status' => 'available',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Existing Topic About Privacy');
    expect($prompt)->toContain('past-topics');
});
```

Then append these four new tests at the end of the file:

```php
test('topics prompt formats user scores and treats null as not yet rated', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Highly rated topic',
        'angle' => 'Angle',
        'status' => 'available',
        'score' => 8,
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Unrated topic',
        'angle' => 'Angle',
        'status' => 'available',
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Poorly rated topic',
        'angle' => 'Angle',
        'status' => 'available',
        'score' => 2,
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Your past topics for this team (most recent 25)');
    expect($prompt)->toContain('score: 8/10');
    expect($prompt)->toContain('score: not yet rated');
    expect($prompt)->toContain('score: 2/10');
});

test('topics prompt caps the past-topics list at 25 items, newest first', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    // Insert 30 topics with monotonically increasing created_at.
    // Topic 0 is oldest, Topic 29 is newest. created_at is not in
    // Topic's Fillable, so we set it after create() and save() — the
    // model has $timestamps = false, so save() will not overwrite it.
    $base = now()->subHours(40);
    for ($i = 0; $i < 30; $i++) {
        $topic = Topic::create([
            'team_id' => $team->id,
            'title' => "Topic {$i}",
            'angle' => 'Angle',
            'status' => 'available',
        ]);
        $topic->created_at = $base->copy()->addHours($i);
        $topic->save();
    }

    $prompt = ChatPromptBuilder::build('topics', $team);

    // Top of the recency window is included.
    expect($prompt)->toContain('Topic 29');
    // Boundary at the 25th most recent (created index 5) is included.
    expect($prompt)->toContain('Topic 5');
    // The 26th most recent (created index 4) is excluded.
    expect($prompt)->not->toContain('Topic 4');
});

test('topics prompt past-topics block excludes deleted topics', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Kept topic',
        'angle' => 'Angle',
        'status' => 'available',
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Removed topic',
        'angle' => 'Angle',
        'status' => 'deleted',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Kept topic');
    expect($prompt)->not->toContain('Removed topic');
});

test('topics prompt omits the past-topics block on an empty backlog', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->not->toContain('Your past topics for this team');
    expect($prompt)->not->toContain('past-topics');
});
```

- [ ] **Step 2: Run the topics prompt unit tests — expect failures**

Run: `./vendor/bin/sail test --filter=ChatPromptBuilderTopicsTest`

Expected: at least 5 failures — the existing renamed-assertion test now fails (the prompt still emits `existing-topics`), plus the four new tests fail because the prompt has no scored block. The other existing tests should still pass.

---

## Task 4: Brainstorm prompt — implementation

**Files:**
- Modify: `app/Services/ChatPromptBuilder.php` (lines ~196–211)

- [ ] **Step 1: Replace the query and block in `topicsPrompt()`**

In `app/Services/ChatPromptBuilder.php`, locate this region (currently lines ~195–211):

```php
        // Add existing backlog to avoid duplicates
        $existingTopics = Topic::where('team_id', $team->id)
            ->whereIn('status', ['available', 'used'])
            ->pluck('title')
            ->toArray();

        if (! empty($existingTopics)) {
            $topicList = implode("\n", array_map(fn ($t) => "- {$t}", $existingTopics));
            $prompt .= <<<BACKLOG


## Existing topics in backlog (do not propose duplicates)
<existing-topics>
{$topicList}
</existing-topics>
BACKLOG;
        }
```

Replace it with:

```php
        // Past topics, scored. Calibration signal for the next brainstorm.
        $pastTopics = Topic::where('team_id', $team->id)
            ->whereIn('status', ['available', 'used'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['title', 'score']);

        if ($pastTopics->isNotEmpty()) {
            $topicList = $pastTopics
                ->map(fn ($t) => '- ' . $t->title . ' — score: ' . ($t->score === null ? 'not yet rated' : $t->score . '/10'))
                ->implode("\n");
            $prompt .= <<<BACKLOG


## Your past topics for this team (most recent 25)
These are topics you proposed in earlier sessions. The "score" is the user's quality rating (1–10) — it's how the user tells you what good looks like. Use it to recalibrate.

- Treat unrated topics ("not yet rated") as average (≈5/10).
- Do not propose duplicates of these.
- Lean toward topic shapes that scored 7+. Avoid shapes that scored ≤4.

<past-topics>
{$topicList}
</past-topics>
BACKLOG;
        }
```

- [ ] **Step 2: Run the topics prompt unit tests — expect all PASS**

Run: `./vendor/bin/sail test --filter=ChatPromptBuilderTopicsTest`

Expected: all tests in the file pass (the original 5 + the 4 new ones = 9 tests).

- [ ] **Step 3: Run the full TopicsTest feature file — sanity check**

Run: `./vendor/bin/sail test --filter=TopicsTest`

Expected: green. (No reason for the prompt change to affect feature tests, but verifying.)

- [ ] **Step 4: Commit**

```bash
git add app/Services/ChatPromptBuilder.php tests/Unit/Services/ChatPromptBuilderTopicsTest.php
git commit -m "feat(brainstorm): feed scored past topics into the prompt"
```

---

## Task 5: Final verification

- [ ] **Step 1: Run the full test suite**

Run: `./vendor/bin/sail test`

Expected: green, with assertion count up by ~12 from the prior run.

- [ ] **Step 2: Manual smoke test**

In a browser:

1. On the Topics page with at least one topic, the helper paragraph appears under the heading. With no topics, the paragraph is absent (the existing empty-state callout is unchanged).
2. Start a new brainstorm conversation. After the model's first response, open the AI log (or inspect the request body) and confirm the system prompt contains a `## Your past topics for this team (most recent 25)` section with `- {title} — score: {N}/10` lines for the team's recent scored topics.

- [ ] **Step 3: Push the branch**

```bash
git push -u origin feature/topic-sources-and-copy
```

(Or skip if already pushed earlier — push the new commits.)

---

## Done criteria

- 6 new tests pass (2 feature, 4 unit).
- 1 existing unit test updated (`existing-topics` → `past-topics`) still passes.
- Full test suite green.
- Manual: helper text visible on a populated Topics page; new prompt block visible in a fresh brainstorm.
- Branch pushed.
