# Writer Save Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Writer AI flow save exactly one `ContentPiece` per conversation (with N versions), and make the UI render one card per piece regardless of how many tool events were emitted along the way.

**Architecture:** Enforce the "one conversation = one piece" invariant at the agent boundary via `ContentPiece::firstOrCreate(['conversation_id' => …])`. Make both the handler and the agent idempotent so duplicate tool calls are safe no-ops. Add `piece_id` to every tool result so the UI binds cards to specific pieces instead of `ContentPiece::first()`-ing. Reorder the streaming abort check so completed tool events aren't dropped mid-tool.

**Tech Stack:** Laravel 13, Livewire, Pest (run via `./vendor/bin/sail test`), PostgreSQL, Flux UI.

**Scope anchor:** all paths below are relative to `marketminded-laravel/`. Run `cd marketminded-laravel` once per shell.

**Deferred (NOT in this plan):**
- Data migration to clean up existing duplicate pieces — user explicitly chose to keep old rows.
- Adding a DB-level unique constraint on `conversation_id` — blocked by the above; revisit after production data is clean.
- Changes to the outer orchestrator prompt (that's a separate prompt-engineering pass).

---

## File Structure

**Create:**
- `tests/Unit/Services/Writer/Agents/WriterAgentIdempotencyTest.php` — new Pest file for idempotency behaviour.

**Modify:**
- `app/Services/Writer/Brief.php` — add `conversationId()` / `withConversationId()`.
- `app/Services/Writer/Agents/WriterAgent.php` — `firstOrCreate` on `conversation_id`, idempotent `applyToBrief`, drop the hard error when a piece already exists.
- `app/Services/WriteBlogPostToolHandler.php` — inject conversation_id into the brief; return `piece_id` in the JSON; make the "already retried this turn" path idempotent-success instead of error.
- `app/Services/ProofreadBlogPostToolHandler.php` — return `piece_id` in the JSON (mirror of write handler); make retry guard idempotent-success.
- `app/Services/Writer/Agents/ProofreadAgent.php` — no logic change; `applyToBrief` already uses the brief's `content_piece_id`. Re-verify the test still passes.
- `resources/views/pages/teams/⚡create-chat.blade.php`:
  - Streaming loop: reorder abort check so completed `ToolEvent`s are captured before break.
  - `finally` block: persist `piece_id` into each `metadata.tools` entry.
  - `contentPieceCards()`: look up piece by `piece_id` from the tool result (not `->first()`); dedupe to one card per piece.
  - History template (`@foreach message['metadata']['tools']`): gate on `status === 'ok'`, look up piece by `piece_id`, dedupe.
- `tests/Unit/Services/WriteBlogPostToolHandlerTest.php` — update existing "refuses second call" test to expect idempotent success + extend happy-path to assert `piece_id`.
- `tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php` — update retry-guard test; assert `piece_id` in JSON.
- `tests/Unit/Services/Writer/Agents/WriterAgentTest.php` — update "refuses to create a second piece" test to expect the new idempotent success behaviour.

---

### Task 1: Add `conversationId` to Brief

**Files:**
- Modify: `app/Services/Writer/Brief.php`
- Test: none (covered by downstream tests in Task 3/4)

- [ ] **Step 1: Add getter and setter on Brief**

In `app/Services/Writer/Brief.php`, add immediately after `contentPieceId()` (around line 44):

```php
    public function conversationId(): ?int
    {
        $id = $this->data['conversation_id'] ?? null;
        return $id === null ? null : (int) $id;
    }
```

And add after `withContentPieceId()` (around line 87):

```php
    public function withConversationId(int $id): self
    {
        return $this->with('conversation_id', $id);
    }
```

- [ ] **Step 2: Sanity-check existing tests still pass**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=Brief
```

Expected: all existing Brief-touching tests green (no behaviour change).

- [ ] **Step 3: Commit**

```bash
git add app/Services/Writer/Brief.php
git commit -m "feat(writer): add conversationId getter/setter to Brief"
```

---

### Task 2: Handler injects conversation_id into Brief

**Files:**
- Modify: `app/Services/WriteBlogPostToolHandler.php`
- Modify: `app/Services/ProofreadBlogPostToolHandler.php`

- [ ] **Step 1: Inject conversation_id in write handler**

In `app/Services/WriteBlogPostToolHandler.php`, replace the line that builds `$brief` (around line 27):

```php
        $brief = Brief::fromJson($conversation->brief ?? []);
```

with:

```php
        $brief = Brief::fromJson($conversation->brief ?? [])
            ->withConversationId($conversation->id);
```

- [ ] **Step 2: Same change in proofread handler**

In `app/Services/ProofreadBlogPostToolHandler.php`, replace the corresponding line (around line 34):

```php
        $brief = Brief::fromJson($conversation->brief ?? []);
```

with:

```php
        $brief = Brief::fromJson($conversation->brief ?? [])
            ->withConversationId($conversation->id);
```

- [ ] **Step 3: Run handler tests (they should still pass — no behaviour change yet)**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter="WriteBlogPostToolHandler|ProofreadBlogPostToolHandler"
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add app/Services/WriteBlogPostToolHandler.php app/Services/ProofreadBlogPostToolHandler.php
git commit -m "feat(writer): inject conversation_id into Brief in tool handlers"
```

---

### Task 3: WriterAgent uses firstOrCreate and is idempotent

**Files:**
- Create: `tests/Unit/Services/Writer/Agents/WriterAgentIdempotencyTest.php`
- Modify: `app/Services/Writer/Agents/WriterAgent.php`

- [ ] **Step 1: Write failing idempotency test**

Create `tests/Unit/Services/Writer/Agents/WriterAgentIdempotencyTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\Brief;

class StubbedWriterAgentForIdempotency extends WriterAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120): ?array
    {
        return $this->stubPayload;
    }
}

function briefForIdempotency(Team $team, Conversation $conversation): Brief
{
    $topic = Topic::create([
        'team_id' => $team->id, 'title' => 'T', 'angle' => 'a', 'status' => 'available',
    ]);

    return Brief::fromJson([
        'topic' => ['id' => $topic->id, 'title' => 'T', 'angle' => 'a', 'sources' => []],
        'research' => [
            'topic_summary' => 'S',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
        'outline' => [
            'angle' => 'a',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ],
    ])->withConversationId($conversation->id);
}

test('WriterAgent reuses existing piece for the same conversation (no duplicate row)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id, 'title' => 't', 'type' => 'writer',
    ]);

    $brief = briefForIdempotency($team, $conversation);
    $body = str_repeat('word ', 850);
    $agent = new StubbedWriterAgentForIdempotency(['title' => 'T1', 'body' => $body]);

    $first = $agent->execute($brief, $team);
    expect($first->isOk())->toBeTrue();
    $pieceId = $first->brief->contentPieceId();

    // Second call: same conversation, same agent stub. Must NOT create a new row.
    $second = $agent->execute($first->brief, $team);
    expect($second->isOk())->toBeTrue();
    expect($second->brief->contentPieceId())->toBe($pieceId);

    expect(ContentPiece::where('conversation_id', $conversation->id)->count())->toBe(1);

    $piece = ContentPiece::findOrFail($pieceId);
    expect($piece->current_version)->toBe(1); // idempotent: no extra version on re-call
    expect($piece->title)->toBe('T1');
});

test('WriterAgent recovers an orphan piece with current_version=0 and writes v1', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id, 'title' => 't', 'type' => 'writer',
    ]);

    // Simulate a previous run that created the row but crashed before saveSnapshot
    $orphan = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'title' => '', 'body' => '', 'current_version' => 0,
    ]);

    $brief = briefForIdempotency($team, $conversation);
    $agent = new StubbedWriterAgentForIdempotency(['title' => 'Recovered', 'body' => str_repeat('w ', 850)]);

    $result = $agent->execute($brief, $team);
    expect($result->isOk())->toBeTrue();
    expect($result->brief->contentPieceId())->toBe($orphan->id);

    $orphan->refresh();
    expect($orphan->current_version)->toBe(1);
    expect($orphan->title)->toBe('Recovered');
    expect(ContentPiece::where('conversation_id', $conversation->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run the new tests to confirm they fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=WriterAgentIdempotency
```

Expected: both tests FAIL. The first fails because current `WriterAgent::execute` returns an error on the second call (the `hasContentPiece` guard). The second fails because `ContentPiece::create` would create a second row next to the orphan.

- [ ] **Step 3: Implement firstOrCreate + idempotency**

Edit `app/Services/Writer/Agents/WriterAgent.php`. Replace the `execute()` method at lines 14–30:

```php
    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot write without research. Run research_topic first.');
        }
        if (! $brief->hasOutline()) {
            return AgentResult::error('Cannot write without an outline. Run create_outline first.');
        }
        if ($brief->conversationId() === null) {
            return AgentResult::error('Writer requires conversation_id on the brief.');
        }

        // If a completed piece already exists for this conversation, short-circuit
        // with an idempotent success that reflects the current DB state. This
        // protects against orchestrator double-calls without clobbering any
        // proofread revisions the user may have accepted.
        if ($brief->hasContentPiece()) {
            $existing = ContentPiece::where('id', $brief->contentPieceId())
                ->where('team_id', $team->id)
                ->first();

            if ($existing !== null && $existing->current_version >= 1) {
                return AgentResult::ok(
                    brief: $brief,
                    cardPayload: $this->buildCardFromPiece($existing),
                    summary: 'Draft already exists · v' . $existing->current_version,
                );
            }
            // else: fall through — the row exists but has no version yet (orphan
            // from a partial prior run). We'll let applyToBrief claim it.
        }

        return parent::execute($brief, $team);
    }
```

Replace the `applyToBrief()` method at lines 164–187:

```php
    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $topic = $brief->topic();

        $piece = ContentPiece::firstOrCreate(
            ['conversation_id' => $brief->conversationId()],
            [
                'team_id' => $team->id,
                'topic_id' => $topic['id'] ?? null,
                'title' => '',
                'body' => '',
                'status' => 'draft',
                'platform' => 'blog',
                'format' => 'pillar',
                'current_version' => 0,
            ],
        );

        // Only write a snapshot if this piece has no content yet. This makes
        // the agent safe to re-run after a crash between create and snapshot,
        // and prevents writer reruns from clobbering a proofread's v2.
        if ($piece->current_version === 0) {
            $piece->saveSnapshot($payload['title'], $payload['body'], 'Initial draft');
        }

        if (! empty($topic['id'])) {
            Topic::where('id', $topic['id'])->update(['status' => 'used']);
        }

        return $brief->withContentPieceId($piece->id);
    }
```

Add a `buildCardFromPiece()` helper after `buildSummary()` (around line 205):

```php
    /**
     * Build the same shape as buildCard() but from a persisted piece — used
     * when short-circuiting because the piece already exists.
     *
     * @return array<string, mixed>
     */
    protected function buildCardFromPiece(ContentPiece $piece): array
    {
        return [
            'kind' => 'content_piece',
            'summary' => 'Draft already exists · v' . $piece->current_version,
            'title' => $piece->title,
            'preview' => mb_substr(strip_tags($piece->body), 0, 200),
            'word_count' => str_word_count(strip_tags($piece->body)),
            'cost' => 0.0,
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
    }
```

- [ ] **Step 4: Run the new tests and confirm they pass**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=WriterAgentIdempotency
```

Expected: both PASS.

- [ ] **Step 5: Update the old "refuses to create a second piece" test**

The old test in `tests/Unit/Services/Writer/Agents/WriterAgentTest.php` at lines 114–128 expects an error. That behaviour is gone. Replace it with:

```php
test('WriterAgent short-circuits with idempotent success when piece already exists', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    // Seed an existing piece with v1
    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => null, // conversation_id comes via the brief
        'title' => 'Existing', 'body' => str_repeat('w ', 850),
        'current_version' => 1,
    ]);

    $briefWithPiece = $ctx['brief']
        ->withConversationId(999)
        ->withContentPieceId($piece->id);

    $agent = new StubbedWriterAgent(['title' => 'New', 'body' => str_repeat('w ', 850)]);
    $result = $agent->execute($briefWithPiece, $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->contentPieceId())->toBe($piece->id);
    expect($result->cardPayload['title'])->toBe('Existing');
    expect($result->summary)->toContain('v1');
    // No second row, no version bump.
    expect(ContentPiece::count())->toBe(1);
    expect($piece->refresh()->current_version)->toBe(1);
});
```

- [ ] **Step 6: Run the full WriterAgent test file**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=WriterAgent
```

Expected: all pass. If "WriterAgent ok path" fails with a Brief conversation_id check, it's because the test builds a brief without conversation_id. Update `fullBriefForWriter()` to set it:

```php
    return [
        'brief' => Brief::fromJson([
            // … existing entries …
        ])->withConversationId(1), // any int — no Conversation row is needed for that test
        'topic' => $topic,
    ];
```

Then re-run.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Writer/Agents/WriterAgent.php tests/Unit/Services/Writer/Agents/
git commit -m "feat(writer): make WriterAgent idempotent per conversation (firstOrCreate + replay-safe snapshot)"
```

---

### Task 4: Handler returns `piece_id` and is idempotent across priorTurnTools

**Files:**
- Modify: `app/Services/WriteBlogPostToolHandler.php`
- Modify: `app/Services/ProofreadBlogPostToolHandler.php`
- Modify: `tests/Unit/Services/WriteBlogPostToolHandlerTest.php`
- Modify: `tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php`

- [ ] **Step 1: Update the write-handler happy-path test to assert piece_id**

In `tests/Unit/Services/WriteBlogPostToolHandlerTest.php`, in the "handler returns ok …" test, add after the `expect($decoded['status'])->toBe('ok');` assertion (around line 69):

```php
    expect($decoded['piece_id'])->toBe($piece->id);
```

- [ ] **Step 2: Replace the "refuses second call" test with idempotent-success expectation**

In the same file, replace the test at lines 78–90 with:

```php
test('handler returns existing piece card on duplicate in-turn call (idempotent)', function () {
    [$team, $conversation, $topic] = writerConvWithFullBrief();

    // First: simulate a successful prior call — persist the piece + brief.
    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'topic_id' => $topic->id,
        'title' => 'Prior', 'body' => str_repeat('w ', 850),
        'current_version' => 1,
    ]);
    $conversation->update(['brief' => array_merge(
        $conversation->brief,
        ['content_piece_id' => $piece->id],
    )]);

    // Agent stub throws if invoked (it must NOT be invoked on idempotent path).
    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::error('agent should not be called on idempotent retry');

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute(
        $team,
        $conversation->id,
        [],
        [['name' => 'write_blog_post', 'args' => []]], // priorTurnTools: already called this turn
    );
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['piece_id'])->toBe($piece->id);
    expect($decoded['card']['title'])->toBe('Prior');
});
```

- [ ] **Step 3: Run the handler tests — they should fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=WriteBlogPostToolHandler
```

Expected: both tests FAIL (no `piece_id` in JSON; retry path still errors).

- [ ] **Step 4: Update write handler implementation**

Replace `app/Services/WriteBlogPostToolHandler.php` `execute()` method (lines 16–54) with:

```php
    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $conversation = Conversation::findOrFail($conversationId);

        // Idempotent path: if this turn already tried write_blog_post successfully,
        // return the existing piece's card instead of erroring out.
        $callsSoFar = collect($priorTurnTools)->where('name', 'write_blog_post')->count();
        if ($callsSoFar >= 1) {
            $brief = Brief::fromJson($conversation->brief ?? []);
            if ($brief->hasContentPiece()) {
                $piece = ContentPiece::where('id', $brief->contentPieceId())
                    ->where('team_id', $team->id)
                    ->first();
                if ($piece !== null) {
                    return json_encode([
                        'status' => 'ok',
                        'summary' => 'Draft already exists · v' . $piece->current_version,
                        'card' => [
                            'kind' => 'content_piece',
                            'summary' => 'Draft already exists · v' . $piece->current_version,
                            'title' => $piece->title,
                            'preview' => mb_substr(strip_tags($piece->body), 0, 200),
                            'word_count' => str_word_count(strip_tags($piece->body)),
                        ],
                        'piece_id' => $piece->id,
                    ]);
                }
            }
            // Prior call claimed to have run but we can't find the piece — fall
            // through to a real attempt so the orchestrator can recover.
        }

        $brief = Brief::fromJson($conversation->brief ?? [])
            ->withConversationId($conversation->id);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new WriterAgent($extraContext) : ($this->agent ?? new WriterAgent);

        try {
            $result = $agent->execute($brief, $team);
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $pieceId = $result->brief->contentPieceId();
        if ($pieceId !== null) {
            ContentPiece::where('id', $pieceId)->update(['conversation_id' => $conversation->id]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
            'piece_id' => $pieceId,
        ]);
    }
```

- [ ] **Step 5: Mirror the change in the proofread handler**

Replace `app/Services/ProofreadBlogPostToolHandler.php` `execute()` method with:

```php
    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $conversation = Conversation::findOrFail($conversationId);

        $callsSoFar = collect($priorTurnTools)->where('name', 'proofread_blog_post')->count();
        if ($callsSoFar >= 1) {
            $brief = Brief::fromJson($conversation->brief ?? []);
            if ($brief->hasContentPiece()) {
                $piece = \App\Models\ContentPiece::where('id', $brief->contentPieceId())
                    ->where('team_id', $team->id)
                    ->first();
                if ($piece !== null) {
                    return json_encode([
                        'status' => 'ok',
                        'summary' => 'Revision already applied · v' . $piece->current_version,
                        'card' => [
                            'kind' => 'content_piece',
                            'summary' => 'Revision already applied · v' . $piece->current_version,
                            'title' => $piece->title,
                            'preview' => mb_substr(strip_tags($piece->body), 0, 200),
                            'change_description' => 'already applied this turn',
                        ],
                        'piece_id' => $piece->id,
                    ]);
                }
            }
        }

        $feedback = trim($args['feedback'] ?? '');
        if ($feedback === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'feedback is required for proofread_blog_post.',
            ]);
        }

        $brief = Brief::fromJson($conversation->brief ?? [])
            ->withConversationId($conversation->id);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $this->agent ?? new ProofreadAgent($feedback, $extraContext);

        try {
            $result = $agent->execute($brief, $team);
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
            'piece_id' => $result->brief->contentPieceId(),
        ]);
    }
```

- [ ] **Step 6: Update the proofread handler's retry-guard test**

In `tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php`, find the test that expects the "Already retried" error and replace it with a mirror of the idempotent-success test from Step 2 (adapt: `name => 'proofread_blog_post'`, use `'Revision already applied'` wording). If no such test exists, add:

```php
test('proofread handler returns existing piece card on duplicate in-turn call', function () {
    [$team, $conversation, $topic] = writerConvWithFullBrief();

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'topic_id' => $topic->id,
        'title' => 'Prior revision', 'body' => str_repeat('w ', 850),
        'current_version' => 2,
    ]);
    $conversation->update(['brief' => array_merge(
        $conversation->brief,
        ['content_piece_id' => $piece->id],
    )]);

    $agent = new FakeProofreadAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new ProofreadBlogPostToolHandler($agent);
    $result = $handler->execute(
        $team,
        $conversation->id,
        ['feedback' => 'tighten intro'],
        [['name' => 'proofread_blog_post', 'args' => []]],
    );
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['piece_id'])->toBe($piece->id);
});
```

Note: adapt `FakeProofreadAgent` / `writerConvWithFullBrief` to whatever fixtures already exist in that test file. Read the file first to avoid duplicating helpers.

- [ ] **Step 7: Run handler tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter="WriteBlogPostToolHandler|ProofreadBlogPostToolHandler"
```

Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add app/Services/WriteBlogPostToolHandler.php app/Services/ProofreadBlogPostToolHandler.php tests/Unit/Services/
git commit -m "feat(writer): return piece_id from tool handlers; idempotent on in-turn retry"
```

---

### Task 5: Persist `piece_id` in Message.metadata.tools

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (`finally` block at lines 292–305)

- [ ] **Step 1: Include piece_id when building each tool entry**

In `resources/views/pages/teams/⚡create-chat.blade.php`, replace the `if (! empty($completedTools))` block inside `finally` (lines 293–305):

```php
            if (! empty($completedTools)) {
                $metadata['tools'] = collect($completedTools)->map(function (ToolEvent $t) {
                    $entry = [
                        'name' => $t->name,
                        'args' => $t->arguments,
                    ];
                    $result = json_decode($t->result ?? '{}', true);
                    if (isset($result['card'])) {
                        $entry['card'] = $result['card'];
                    }
                    if (isset($result['piece_id'])) {
                        $entry['piece_id'] = $result['piece_id'];
                    }
                    if (isset($result['status'])) {
                        $entry['status'] = $result['status'];
                    }
                    return $entry;
                })->toArray();
            }
```

- [ ] **Step 2: Manual smoke test**

Nothing automated here — the blade file is feature-tested through UI. Proceed; the next task's history-rendering change will exercise this data shape.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/teams/
git commit -m "feat(writer): persist piece_id and status in Message.metadata.tools"
```

---

### Task 6: Live stream — bind content-piece cards to piece_id + dedupe

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (`contentPieceCards()` at lines 642–668)

- [ ] **Step 1: Replace the write/proofread branch in contentPieceCards()**

In `resources/views/pages/teams/⚡create-chat.blade.php`, replace `contentPieceCards()` (lines 642–668) with:

```php
    private function contentPieceCards(array $completedTools): string
    {
        $html = '';
        $seenPieceIds = [];

        foreach ($completedTools as $tool) {
            $result = json_decode($tool->result ?? '{}', true);
            if (($result['status'] ?? '') !== 'ok') {
                continue;
            }

            $card = $result['card'] ?? null;
            $kind = $card['kind'] ?? null;

            if ($tool->name === 'research_topic' && $kind === 'research') {
                $html .= $this->renderResearchCard($card);
            } elseif ($tool->name === 'create_outline' && $kind === 'outline') {
                $html .= $this->renderOutlineCard($card);
            } elseif (in_array($tool->name, ['write_blog_post', 'proofread_blog_post'], true)) {
                $pieceId = $result['piece_id'] ?? null;
                if ($pieceId === null || isset($seenPieceIds[$pieceId])) {
                    continue;
                }
                $seenPieceIds[$pieceId] = true;

                $piece = \App\Models\ContentPiece::where('id', $pieceId)
                    ->where('team_id', $this->teamModel->id)
                    ->first();
                if ($piece) {
                    $html .= $this->renderContentPieceCard($piece, $tool->name, $card ?? []);
                }
            }
        }
        return $html;
    }
```

- [ ] **Step 2: Browser-smoke the live stream**

```bash
cd marketminded-laravel && ./vendor/bin/sail up -d
```

Then in a browser, run the writer flow that previously produced duplicate cards. Expected: one content-piece card appears per piece during streaming (even if the orchestrator retried the tool).

If you cannot test the UI in the browser right now, say so in the commit message — don't claim success.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/teams/
git commit -m "feat(writer): bind live content-piece cards to piece_id; dedupe per piece"
```

---

### Task 7: History rendering — status filter + piece_id lookup + dedupe

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (history `@foreach` around lines 843–901)

- [ ] **Step 1: Rewrite the `metadata.tools` history loop**

In `resources/views/pages/teams/⚡create-chat.blade.php`, replace the block starting at `{{-- Sub-agent cards from history --}}` (line 842) through the closing `@endforeach` at line 901 with:

```blade
                        {{-- Sub-agent cards from history --}}
                        @php
                            $seenHistoryPieceIds = [];
                        @endphp
                        @foreach ($message['metadata']['tools'] ?? [] as $tool)
                            @php
                                // Gate: only render cards for successful tool results.
                                // Older records may not have a status field — treat
                                // their presence as success for backwards compat.
                                $status = $tool['status'] ?? 'ok';
                                if ($status !== 'ok') {
                                    continue;
                                }

                                $card = $tool['card'] ?? null;
                                $kind = $card['kind'] ?? null;
                                $metricsParts = [];
                                if ($card) {
                                    if (($card['input_tokens'] ?? 0) + ($card['output_tokens'] ?? 0) > 0) {
                                        $metricsParts[] = number_format(($card['input_tokens'] ?? 0) + ($card['output_tokens'] ?? 0)) . ' tokens';
                                    }
                                    if (($card['cost'] ?? 0) > 0) {
                                        $metricsParts[] = '$' . number_format($card['cost'], 4);
                                    }
                                }
                                $metricsFooter = empty($metricsParts) ? '' : '<div class="mt-2 border-t border-zinc-700 pt-2 text-xs text-zinc-500">' . e(implode(' · ', $metricsParts)) . '</div>';
                            @endphp

                            @if ($tool['name'] === 'research_topic' && $kind === 'research')
                                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                    <div class="text-xs text-purple-400">&#10003; {{ $card['summary'] ?? 'Research complete' }}</div>
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach (array_slice($card['claims'] ?? [], 0, 5) as $c)
                                            <li class="text-xs text-zinc-400">{{ $c['text'] ?? '' }}</li>
                                        @endforeach
                                    </ul>
                                    @if (count($card['claims'] ?? []) > 5)
                                        <div class="mt-1 text-xs text-zinc-500">…and {{ count($card['claims']) - 5 }} more</div>
                                    @endif
                                    {!! $metricsFooter !!}
                                </div>
                            @elseif ($tool['name'] === 'create_outline' && $kind === 'outline')
                                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                    <div class="text-xs text-blue-400">&#10003; {{ $card['summary'] ?? 'Outline ready' }}</div>
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach ($card['sections'] ?? [] as $s)
                                            <li class="text-xs text-zinc-400">{{ $s['heading'] }}</li>
                                        @endforeach
                                    </ul>
                                    {!! $metricsFooter !!}
                                </div>
                            @elseif (in_array($tool['name'], ['write_blog_post', 'proofread_blog_post'], true))
                                @php
                                    $pieceId = $tool['piece_id'] ?? null;
                                    if ($pieceId === null || isset($seenHistoryPieceIds[$pieceId])) {
                                        $piece = null;
                                    } else {
                                        $seenHistoryPieceIds[$pieceId] = true;
                                        $piece = \App\Models\ContentPiece::where('id', $pieceId)
                                            ->where('team_id', $teamModel->id)
                                            ->first();
                                    }
                                    $badge = $tool['name'] === 'write_blog_post' ? __('Draft created') : __('Revised');
                                @endphp
                                @if ($piece)
                                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-green-400">&#10003; {{ $badge }} &middot; v{{ $piece->current_version }} &middot; {{ number_format(str_word_count(strip_tags($piece->body))) }} words</span>
                                            <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $piece->id]) }}" wire:navigate class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('Open') }} &rarr;</a>
                                        </div>
                                        <div class="text-sm font-semibold text-zinc-200">{{ $piece->title }}</div>
                                        <div class="mt-1 text-xs text-zinc-400 line-clamp-3">{{ mb_substr(strip_tags($piece->body), 0, 200) }}</div>
                                        {!! $metricsFooter !!}
                                    </div>
                                @endif
                            @endif
                        @endforeach
```

Note: Blade `@php` blocks can use `continue;` inside the `@foreach` because the compiled template wraps them in the loop body. If Blade complains about the `continue` (some versions escape it), replace the `if` with `@if ($status === 'ok')` wrapping the rest of the body, and remove the PHP `continue`.

- [ ] **Step 2: Browser-smoke the history render**

Reload the conversation whose screenshot showed 30 cards. Expected: one card per actual piece (two in your case, since two rows exist).

If there's data where `metadata.tools` entries have no `piece_id` (records written before this plan), those entries will no longer render a content-piece card. That's the price of the fix — it is the correct behaviour (we can't reliably map old entries to the right piece).

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/teams/
git commit -m "fix(writer): history renders one card per piece, gated on status=ok"
```

---

### Task 8: Don't drop completed ToolEvents on client abort

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (streaming loop at lines 254–277)

- [ ] **Step 1: Reorder abort check to happen after processing the yielded item**

Replace the `foreach` loop body at lines 254–277:

```php
            foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor) as $item) {
                if ($item instanceof ToolEvent) {
                    if ($item->status === 'completed') {
                        $completedTools[] = $item;
                    }
                    $activeTool = $item->status === 'started' ? $item : null;
                    $this->streamUI($this->cleanContent($fullContent), $completedTools, $activeTool);
                } elseif ($item instanceof StreamResult) {
                    $streamResult = $item;
                } else {
                    $fullContent .= $item;
                    $this->streamUI($this->cleanContent($fullContent), $completedTools, null);
                }

                // Abort check goes AFTER processing this item. The generator has
                // already executed any synchronous sub-agent work (DB writes
                // included) to yield this $item, so we capture it in our state
                // before we decide to stop asking for more tokens. ignore_user_abort
                // keeps PHP alive either way.
                if (connection_aborted()) {
                    $interrupted = true;
                    break;
                }
            }
```

- [ ] **Step 2: Manual test (optional but recommended)**

With a long-running writer call, click Stop mid-tool. After the server completes, reload the page. Expected: the message shows the completed tool's card + an "Interrupted" pill (since other sub-agents after it didn't get to run). Before this fix, the tool that was in flight at abort time was lost.

If you can't test the UI right now, note that in the commit.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pages/teams/
git commit -m "fix(writer): process yielded ToolEvent before abort-check so completions aren't dropped"
```

---

### Task 9: Full-suite check + manual QA checklist

- [ ] **Step 1: Run the full test suite**

```bash
cd marketminded-laravel && ./vendor/bin/sail test
```

Expected: all green. If any unrelated test fails, investigate before proceeding — do not skip.

- [ ] **Step 2: Manual QA checklist**

In a browser against a fresh conversation:

- [ ] Start a writer flow. Confirm exactly one "Draft created · v1" card appears at completion.
- [ ] In the same conversation, send a proofread-style follow-up. Confirm the card updates to "Revised · v2" — and that there is still only one `ContentPiece` row:
      `./vendor/bin/sail tinker -e "echo App\Models\ContentPiece::where('conversation_id', <id>)->count();"`
- [ ] Click Stop during a writer run that's past the sub-agent LLM call but before the orchestrator's next turn. Confirm the interrupted message still shows a completed card after reload.
- [ ] Reload the conversation from the original bug report (the one with 30 cards). Confirm the number of cards matches the number of distinct piece ids in `metadata.tools` (expected: 2 cards, matching the 2 legacy rows in your DB).

- [ ] **Step 3: Summary commit / PR description**

No code change here — write a PR description when you open the PR summarising:
- Root cause: no invariant at any layer for "one piece per conversation."
- Fix: firstOrCreate on conversation_id at the agent, idempotent handlers, piece_id binding in UI, abort-order fix.
- Known remaining risk: legacy rows with `conversation_id` still point at the same conversation. Not migrated by choice; see plan's "Deferred" section.

---

## Self-Review

**Spec coverage (cross-check against the A–D recommendations in the conversation):**
- A. DB-layer invariant via `conversation_id` uniqueness: partially covered — enforced at agent layer via `firstOrCreate`. DB-level unique index deferred (user chose to keep legacy rows); covered in Task 9 PR description as a known follow-up.
- B. UI cards bound to `piece_id`: covered by Tasks 4 (emit), 5 (persist), 6 (live render), 7 (history render).
- C. Idempotent handler: covered by Task 4 (both handlers).
- D. Stream/persistence reconciliation on abort: covered by Task 8. "Interrupted" pill kept intact deliberately — removing it is out of scope.

**Placeholder scan:** no "TBD", "similar to", or hand-wavy steps. Every step has a concrete code block or bash command.

**Type/name consistency:**
- `Brief::conversationId()` / `withConversationId()` used consistently across Tasks 1, 2, 3, 4.
- `piece_id` key used consistently in JSON result (Task 4), metadata entry (Task 5), live render (Task 6), history render (Task 7).
- `buildCardFromPiece()` defined in Task 3 and not referenced elsewhere — used only within WriterAgent.

**Note for executor:** if `ProofreadBlogPostToolHandlerTest.php` doesn't already contain `writerConvWithFullBrief()` / `FakeProofreadAgent` helpers, create local equivalents in that test file rather than cross-importing — test files are intentionally self-contained in this codebase.
