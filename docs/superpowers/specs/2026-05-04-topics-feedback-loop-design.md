# Topics feedback loop: page guidance + scored-backlog prompt

**Date:** 2026-05-04
**Branch:** `feature/topic-sources-and-copy` (continuing the same branch — these changes extend the same theme)
**Status:** approved, ready for implementation plan

## Why

Two halves of one feedback loop:

1. **Tell the user that scoring matters.** Today the score slider on each topic card has no visible payoff — users don't know it does anything beyond decoration. We add helper text under the Topics page title that explains the slider trains the next brainstorm, that they should delete irrelevant topics, and that low-source topics deserve lower scores.
2. **Actually use the scores.** The brainstorm prompt currently passes a flat list of existing topic *titles* into a `<existing-topics>` block as a "don't duplicate" hint. We replace that with the 25 most recent topics tagged with their user-provided score, framed as quality calibration. The model then steers future proposals toward topic shapes the user has rated highly.

Closing the loop only works if both halves ship together — the user needs to know their scores matter, and the model needs to actually receive them.

## Scope

Two files change. No schema changes, no migrations, no new routes.

### Part 1 — Topics page helper text

**Location:** `resources/views/pages/teams/⚡topics.blade.php`, inside the page header container (around line 47–57), immediately after the `<flux:heading>Topics</flux:heading>` row.

**Conditional rendering:** show only when `$this->topics->isNotEmpty()`. On the empty state the page already shows its own callout prompting the user to start a brainstorm; piling on with feedback-loop guidance there would be noise.

**Wording (single muted paragraph):**

> Score topics 1–10 to teach the AI what good looks like — this directly improves your next brainstorm. Delete topics that aren't relevant. Score topics with weak or missing sources lower.

**Markup:** a single `<flux:subheading>` (or `<flux:text>` styled muted) wrapped to be visually subordinate to the heading. Not a bulleted list — one short paragraph reads cleaner for three sentences.

### Part 2 — Brainstorm prompt: scored backlog

**Location:** `app/Services/ChatPromptBuilder.php::topicsPrompt()`, the `$existingTopics` query and `<existing-topics>` block (currently lines ~196–211).

**Replace** the existing query:

```php
$existingTopics = Topic::where('team_id', $team->id)
    ->whereIn('status', ['available', 'used'])
    ->pluck('title')
    ->toArray();
```

**With** a richer query that pulls title + score, ordered newest-first, capped at 25:

```php
$pastTopics = Topic::where('team_id', $team->id)
    ->whereIn('status', ['available', 'used'])
    ->orderByDesc('created_at')
    ->limit(25)
    ->get(['title', 'score']);
```

**Replace** the existing block append (when non-empty):

```
## Existing topics in backlog (do not propose duplicates)
<existing-topics>
- {title}
...
</existing-topics>
```

**With** a calibration-focused block (when non-empty):

```
## Your past topics for this team (most recent 25)
These are topics you proposed in earlier sessions. The "score" is the user's quality rating (1–10) — it's how the user tells you what good looks like. Use it to recalibrate.

- Treat unrated topics ("not yet rated") as average (≈5/10).
- Do not propose duplicates of these.
- Lean toward topic shapes that scored 7+. Avoid shapes that scored ≤4.

<past-topics>
- {title} — score: {N}/10
- {title} — score: not yet rated
...
</past-topics>
```

**Score formatting:** integer score renders as `score: {N}/10`. Null score renders as `score: not yet rated`. The block's bullet "Treat unrated topics … as average (≈5/10)" tells the model how to interpret the latter.

**Empty case:** when there are no past topics for the team, the entire block (heading + bullets + `<past-topics>` element) is omitted — same behavior as today.

**Excluded topics:** rows with `status = 'deleted'` are filtered out, exactly as today. Deletion is a noisy signal (user may delete because the topic was bad, already used, or stale) and the kept-topic score is the deliberate quality channel. This was discussed during brainstorm — choosing not to add a `<rejected-topics>` block is intentional, not an oversight.

## Out of scope

- No schema changes; `score` is already a nullable integer column.
- No backfill of existing topics' scores.
- No changes to other prompt modes (`brand-intelligence`, `content-piece`, `ai-log`).
- No "rejected topics" block. Deletion is a dashboard-only action with no model-facing side effect.

## Testing

### Unit tests in `tests/Unit/Services/ChatPromptBuilderTopicsTest.php`

This file already covers the topics prompt — extend it.

1. **Scored topics are included with score formatting.** Create three topics with scores `8`, `null`, `2`. Assert the prompt contains `Your past topics for this team (most recent 25)`, `score: 8/10`, `score: not yet rated`, and `score: 2/10`.
2. **Only 25 most recent topics are included, ordered newest-first.** Create 30 topics with monotonically increasing `created_at` and titles `Topic 0` … `Topic 29`. Assert the prompt contains `Topic 29` and `Topic 5`, and does NOT contain `Topic 4`. (Topics 5–29 = 25 most recent; Topic 4 is the first cut.)
3. **Deleted topics are excluded.** Create one available topic and one with `status = 'deleted'`. Assert the deleted topic's title does not appear in the prompt.
4. **Empty backlog: block is omitted.** No topics → assert the prompt does not contain `Your past topics for this team`.

### Feature tests in `tests/Feature/Teams/TopicsTest.php`

5. **Helper text renders when at least one topic exists.** Create one topic; assert `Score topics 1–10` (or another stable substring of the helper text) appears in the rendered HTML.
6. **Helper text omitted on empty backlog.** No topics; assert the helper text substring does NOT appear.

## Files touched

- `resources/views/pages/teams/⚡topics.blade.php` — helper text under page heading.
- `app/Services/ChatPromptBuilder.php` — query + prompt block.
- `tests/Feature/Teams/TopicsTest.php` — 2 new feature tests.
- `tests/Unit/Services/ChatPromptBuilderTopicsTest.php` — 4 new unit tests.

## Risks

Low. The prompt change is additive in capability (richer signal) and substitutive in shape (replaces the de-dupe block, doesn't pile on top of it). The duplicate-avoidance instruction is preserved in the new block. The helper text is one paragraph in one Blade file, gated by the existing `isNotEmpty` check we already use elsewhere on the page.

The only behavioral risk is that the model, given calibration data, starts ignoring the duplicate-avoidance bullet. The wording "Do not propose duplicates of these" is identical in intent to the old `## Existing topics in backlog (do not propose duplicates)` heading; if regressions surface in production we can sharpen.
