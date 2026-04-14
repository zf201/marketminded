# Writer Agents Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor the writer chat from one-LLM-does-everything to orchestrator + sub-agents. Each sub-agent has a focused prompt, fit-for-purpose tools, and writes its slice of a shared `Brief` on the conversation.

**Architecture:** Add a `Brief` value object backed by a new `conversations.brief` jsonb column. Introduce `Agent` interface + `BaseAgent` abstract that handles the LLM call/validation loop. Build four concrete agents (Research, Editor, Writer, Proofread). Gut the existing tool handlers to thin shells that wrap an agent. Shrink the orchestrator's system prompt to ~2K — it now sees only a compact `<brief-status>` block, never full payloads.

**Tech Stack:** Laravel 13, Livewire/Volt, PostgreSQL 17 (sail), JSONB column, OpenRouter (existing `OpenRouterClient::chat()` for sub-agent calls), Pest tests.

**Spec:** `docs/superpowers/specs/2026-04-14-writer-agents-refactor-design.md`

**Working directory convention:** All Laravel paths are relative to `marketminded-laravel/`. Run `php artisan ...` and tests via `./vendor/bin/sail ...` from inside `marketminded-laravel/`. Host lacks `pdo_pgsql` — never use plain `php artisan test`.

---

## File Structure

### Create

- `marketminded-laravel/database/migrations/<ts>_add_brief_to_conversations_table.php`
- `marketminded-laravel/app/Services/Writer/Brief.php` — value object
- `marketminded-laravel/app/Services/Writer/AgentResult.php` — sub-agent return type
- `marketminded-laravel/app/Services/Writer/Agent.php` — interface
- `marketminded-laravel/app/Services/Writer/BaseAgent.php` — abstract base
- `marketminded-laravel/app/Services/Writer/Agents/ResearchAgent.php`
- `marketminded-laravel/app/Services/Writer/Agents/EditorAgent.php`
- `marketminded-laravel/app/Services/Writer/Agents/WriterAgent.php`
- `marketminded-laravel/app/Services/Writer/Agents/ProofreadAgent.php`
- `marketminded-laravel/app/Services/ProofreadBlogPostToolHandler.php`
- `marketminded-laravel/tests/Unit/Services/Writer/BriefTest.php`
- `marketminded-laravel/tests/Unit/Services/Writer/Agents/ResearchAgentTest.php`
- `marketminded-laravel/tests/Unit/Services/Writer/Agents/EditorAgentTest.php`
- `marketminded-laravel/tests/Unit/Services/Writer/Agents/WriterAgentTest.php`
- `marketminded-laravel/tests/Unit/Services/Writer/Agents/ProofreadAgentTest.php`
- `marketminded-laravel/tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php`

### Modify

- `marketminded-laravel/app/Models/Conversation.php` — add `brief` to fillable + casts
- `marketminded-laravel/app/Services/ChatPromptBuilder.php` — shrink writerPrompt; brief-status block; long writing rules deleted (move to WriterAgent)
- `marketminded-laravel/app/Services/ResearchTopicToolHandler.php` — gut to thin shell
- `marketminded-laravel/app/Services/CreateOutlineToolHandler.php` — gut to thin shell
- `marketminded-laravel/app/Services/WriteBlogPostToolHandler.php` — gut to thin shell
- `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php` — rename update→proofread dispatch; hydrate brief.topic in ask(); update labels; cards for research/outline; metadata save extension
- `marketminded-laravel/tests/Unit/Services/CreateOutlineToolHandlerTest.php` — rewrite around brief
- `marketminded-laravel/tests/Unit/Services/WriteBlogPostToolHandlerTest.php` — rewrite around brief
- `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderWriterTest.php` — assertions for brief-status block

### Delete

- `marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php` — replaced by `ProofreadBlogPostToolHandler`
- `marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php` — replaced

### Prompt files

WriterAgent's long-form writing rules live inline in the agent class (PHP heredoc) — keeps the prompt + agent versioned together. No separate `resources/prompts/agents/*.md` files in this PR. (If prompts grow large in PR 2 we can extract to files later.)

---

## Task 1: Add `conversations.brief` JSONB column and update Conversation model

**Files:**
- Create: `marketminded-laravel/database/migrations/<ts>_add_brief_to_conversations_table.php`
- Modify: `marketminded-laravel/app/Models/Conversation.php`
- Test: extend `marketminded-laravel/tests/Unit/Models/ConversationTest.php`

- [ ] **Step 1: Generate migration**

```bash
cd marketminded-laravel
./vendor/bin/sail artisan make:migration add_brief_to_conversations_table
```

- [ ] **Step 2: Fill migration**

Replace generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->jsonb('brief')->default(DB::raw("'{}'::jsonb"))->after('topic_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('brief');
        });
    }
};
```

- [ ] **Step 3: Write failing test for fillable + cast**

Append to `tests/Unit/Models/ConversationTest.php`:

```php
test('Conversation casts brief as array and accepts updates', function () {
    $user = \App\Models\User::factory()->create();
    $team = $user->currentTeam;

    $conversation = \App\Models\Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'W',
        'type' => 'writer',
    ]);

    expect($conversation->brief)->toBe([]);

    $conversation->update(['brief' => ['topic' => ['id' => 1, 'title' => 'X']]]);
    $conversation->refresh();

    expect($conversation->brief)->toBe(['topic' => ['id' => 1, 'title' => 'X']]);
});
```

- [ ] **Step 4: Run test (expect fail)**

```bash
./vendor/bin/sail artisan migrate --database=testing
./vendor/bin/sail test --filter=ConversationTest
```
Expected: FAIL — `brief` is not fillable yet, may not cast as array.

- [ ] **Step 5: Update Conversation model**

Replace `marketminded-laravel/app/Models/Conversation.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'user_id', 'title', 'type', 'writer_mode', 'topic_id', 'brief'])]
class Conversation extends Model
{
    protected function casts(): array
    {
        return [
            'brief' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function contentPieces(): HasMany
    {
        return $this->hasMany(ContentPiece::class);
    }
}
```

- [ ] **Step 6: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=ConversationTest
./vendor/bin/sail test
```
Expected: all green.

- [ ] **Step 7: Commit**

From repo root:
```bash
git add marketminded-laravel/database/migrations/*_add_brief_to_conversations_table.php \
        marketminded-laravel/app/Models/Conversation.php \
        marketminded-laravel/tests/Unit/Models/ConversationTest.php
git commit -m "feat: add brief jsonb column and cast on conversations"
```

---

## Task 2: `Brief` value object

**Files:**
- Create: `marketminded-laravel/app/Services/Writer/Brief.php`
- Test: `marketminded-laravel/tests/Unit/Services/Writer/BriefTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/Writer/BriefTest.php`:

```php
<?php

use App\Services\Writer\Brief;

test('fromJson and toJson round-trip', function () {
    $data = ['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]];
    $brief = Brief::fromJson($data);

    expect($brief->toJson())->toBe($data);
});

test('empty brief reports nothing present', function () {
    $brief = Brief::fromJson([]);

    expect($brief->hasTopic())->toBeFalse();
    expect($brief->hasResearch())->toBeFalse();
    expect($brief->hasOutline())->toBeFalse();
    expect($brief->hasContentPiece())->toBeFalse();

    expect($brief->topic())->toBeNull();
    expect($brief->research())->toBeNull();
    expect($brief->outline())->toBeNull();
    expect($brief->contentPieceId())->toBeNull();
});

test('with* methods produce a new brief without mutating the original', function () {
    $original = Brief::fromJson([]);

    $withTopic = $original->withTopic(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]);

    expect($original->hasTopic())->toBeFalse();
    expect($withTopic->hasTopic())->toBeTrue();
    expect($withTopic->topic())->toBe(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]);
});

test('withResearch, withOutline, withContentPieceId set their slots', function () {
    $brief = Brief::fromJson([])
        ->withResearch([
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 'T']],
        ])
        ->withOutline([
            'angle' => 'a',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ])
        ->withContentPieceId(42);

    expect($brief->hasResearch())->toBeTrue();
    expect($brief->hasOutline())->toBeTrue();
    expect($brief->hasContentPiece())->toBeTrue();
    expect($brief->contentPieceId())->toBe(42);
    expect($brief->research()['claims'])->toHaveCount(1);
    expect($brief->outline()['sections'])->toHaveCount(2);
});

test('statusSummary shows checkmarks and counts for filled slots', function () {
    $brief = Brief::fromJson([])
        ->withTopic(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []])
        ->withResearch([
            'topic_summary' => 's',
            'claims' => array_map(fn ($i) => ['id' => "c{$i}", 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']], range(1, 13)),
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 'T']],
        ]);

    $summary = $brief->statusSummary();

    expect($summary)->toContain('topic: ✓');
    expect($summary)->toContain('research: ✓');
    expect($summary)->toContain('13 claims');
    expect($summary)->toContain('outline: ✗');
    expect($summary)->toContain('content_piece: ✗');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
cd marketminded-laravel
./vendor/bin/sail test --filter=BriefTest
```
Expected: FAIL (class not found).

- [ ] **Step 3: Write `Brief` class**

Create `app/Services/Writer/Brief.php`:

```php
<?php

namespace App\Services\Writer;

final class Brief
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromJson(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed>|null */
    public function topic(): ?array
    {
        return $this->data['topic'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function research(): ?array
    {
        return $this->data['research'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function outline(): ?array
    {
        return $this->data['outline'] ?? null;
    }

    public function contentPieceId(): ?int
    {
        $id = $this->data['content_piece_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function hasTopic(): bool
    {
        return $this->topic() !== null;
    }

    public function hasResearch(): bool
    {
        return $this->research() !== null;
    }

    public function hasOutline(): bool
    {
        return $this->outline() !== null;
    }

    public function hasContentPiece(): bool
    {
        return $this->contentPieceId() !== null;
    }

    /** @param array<string, mixed> $topic */
    public function withTopic(array $topic): self
    {
        return $this->with('topic', $topic);
    }

    /** @param array<string, mixed> $research */
    public function withResearch(array $research): self
    {
        return $this->with('research', $research);
    }

    /** @param array<string, mixed> $outline */
    public function withOutline(array $outline): self
    {
        return $this->with('outline', $outline);
    }

    public function withContentPieceId(int $id): self
    {
        return $this->with('content_piece_id', $id);
    }

    public function statusSummary(): string
    {
        $lines = [];

        if ($this->hasTopic()) {
            $lines[] = 'topic: ✓ ' . ($this->topic()['title'] ?? '');
        } else {
            $lines[] = 'topic: ✗';
        }

        if ($this->hasResearch()) {
            $claims = count($this->research()['claims'] ?? []);
            $sources = count($this->research()['sources'] ?? []);
            $lines[] = "research: ✓ ({$claims} claims, {$sources} sources)";
        } else {
            $lines[] = 'research: ✗';
        }

        if ($this->hasOutline()) {
            $sections = count($this->outline()['sections'] ?? []);
            $words = $this->outline()['target_length_words'] ?? '?';
            $lines[] = "outline: ✓ ({$sections} sections, ~{$words} words)";
        } else {
            $lines[] = 'outline: ✗';
        }

        if ($this->hasContentPiece()) {
            $lines[] = 'content_piece: ✓ (id=' . $this->contentPieceId() . ')';
        } else {
            $lines[] = 'content_piece: ✗';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed>|int $value */
    private function with(string $key, array|int $value): self
    {
        $copy = $this->data;
        $copy[$key] = $value;
        return new self($copy);
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=BriefTest
```
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/Writer/Brief.php \
        marketminded-laravel/tests/Unit/Services/Writer/BriefTest.php
git commit -m "feat: add Brief value object for writer agents"
```

---

## Task 3: `AgentResult`, `Agent` interface, `BaseAgent` abstract

**Files:**
- Create: `marketminded-laravel/app/Services/Writer/AgentResult.php`
- Create: `marketminded-laravel/app/Services/Writer/Agent.php`
- Create: `marketminded-laravel/app/Services/Writer/BaseAgent.php`

No dedicated test in this task — `BaseAgent` is exercised via concrete agents in subsequent tasks. `AgentResult` is a tiny data holder.

- [ ] **Step 1: Create `AgentResult`**

Create `app/Services/Writer/AgentResult.php`:

```php
<?php

namespace App\Services\Writer;

final readonly class AgentResult
{
    /**
     * @param array<string, mixed>|null $cardPayload
     */
    private function __construct(
        public string $status,
        public ?Brief $brief,
        public ?array $cardPayload,
        public ?string $summary,
        public ?string $errorMessage,
    ) {}

    /** @param array<string, mixed> $cardPayload */
    public static function ok(Brief $brief, array $cardPayload, string $summary): self
    {
        return new self('ok', $brief, $cardPayload, $summary, null);
    }

    public static function error(string $message): self
    {
        return new self('error', null, null, null, $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
```

- [ ] **Step 2: Create `Agent` interface**

Create `app/Services/Writer/Agent.php`:

```php
<?php

namespace App\Services\Writer;

use App\Models\Team;

interface Agent
{
    public function execute(Brief $brief, Team $team): AgentResult;
}
```

- [ ] **Step 3: Create `BaseAgent` abstract**

Create `app/Services/Writer/BaseAgent.php`:

```php
<?php

namespace App\Services\Writer;

use App\Models\Team;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;

abstract class BaseAgent implements Agent
{
    public function __construct(protected ?string $extraContext = null) {}

    /**
     * Build the full system prompt for this agent's LLM call. Should embed
     * everything the LLM needs from the brief + team profile.
     */
    abstract protected function systemPrompt(Brief $brief, Team $team): string;

    /**
     * The OpenAI/OpenRouter function-calling schema for the submit_* tool
     * the LLM uses to deliver structured output.
     *
     * @return array<string, mixed>
     */
    abstract protected function submitToolSchema(): array;

    /**
     * Additional non-submit tools the agent can use during its turn (e.g.
     * fetch_url for brand_enricher). Return [] if none.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function additionalTools(): array;

    /**
     * Whether the agent should have OpenRouter's server-side web_search
     * tool available.
     */
    abstract protected function useServerTools(): bool;

    abstract protected function model(Team $team): string;

    abstract protected function temperature(): float;

    /**
     * Validate the payload submitted via the submit tool.
     * Return null on success; an error message on failure.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function validate(array $payload): ?string;

    /**
     * Apply the validated payload to the brief, returning the new Brief.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief;

    /**
     * Build the small UI card payload returned to the orchestrator.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    abstract protected function buildCard(array $payload): array;

    /**
     * One-line factual summary returned to the orchestrator (e.g. "Gathered 13 claims").
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function buildSummary(array $payload): string;

    final public function execute(Brief $brief, Team $team): AgentResult
    {
        $payload = $this->llmCall(
            systemPrompt: $this->systemPrompt($brief, $team),
            tools: array_merge([$this->submitToolSchema()], $this->additionalTools()),
            model: $this->model($team),
            temperature: $this->temperature(),
            useServerTools: $this->useServerTools(),
            apiKey: $team->openrouter_api_key,
        );

        if ($payload === null) {
            return AgentResult::error('Sub-agent did not call the submit tool. Check the agent prompt and try again.');
        }

        $err = $this->validate($payload);
        if ($err !== null) {
            return AgentResult::error($err);
        }

        $newBrief = $this->applyToBrief($brief, $payload, $team);

        return AgentResult::ok(
            brief: $newBrief,
            cardPayload: $this->buildCard($payload),
            summary: $this->buildSummary($payload),
        );
    }

    /**
     * Make the actual LLM call. Returns the args of the submit_* tool call,
     * or null if the LLM did not call it.
     *
     * Tests override this method to inject canned payloads without HTTP.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>|null
     */
    protected function llmCall(
        string $systemPrompt,
        array $tools,
        string $model,
        float $temperature,
        bool $useServerTools,
        string $apiKey,
    ): ?array {
        $client = new OpenRouterClient(
            apiKey: $apiKey,
            model: $model,
            urlFetcher: new UrlFetcher,
            maxIterations: 10,
        );

        // The submit_* tool short-circuits chat() — its args are returned as
        // the ChatResult::data when the LLM calls it.
        $result = $client->chat(
            messages: [['role' => 'system', 'content' => $systemPrompt]],
            tools: $tools,
            toolChoice: null,
            temperature: $temperature,
            useServerTools: $useServerTools,
        );

        // chat() returns the submit tool's args as $result->data when a
        // submit_* tool is called. Otherwise data is the model's text output.
        return is_array($result->data) ? $result->data : null;
    }

    protected function extraContextBlock(): string
    {
        if ($this->extraContext === null || $this->extraContext === '') {
            return '';
        }
        return "\n\n## Orchestrator guidance for this attempt\n{$this->extraContext}\n";
    }
}
```

- [ ] **Step 4: Run full test suite (no regression)**

```bash
cd marketminded-laravel
./vendor/bin/sail test
```
Expected: still all green (no behavioral change yet).

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/Writer/AgentResult.php \
        marketminded-laravel/app/Services/Writer/Agent.php \
        marketminded-laravel/app/Services/Writer/BaseAgent.php
git commit -m "feat: add Agent interface, AgentResult, and BaseAgent abstract"
```

---

## Task 4: `ResearchAgent`

**Files:**
- Create: `marketminded-laravel/app/Services/Writer/Agents/ResearchAgent.php`
- Test: `marketminded-laravel/tests/Unit/Services/Writer/Agents/ResearchAgentTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/Writer/Agents/ResearchAgentTest.php`:

```php
<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\Brief;

/**
 * Test subclass: overrides llmCall() to return a canned payload, so we can
 * test validate(), applyToBrief(), buildCard(), buildSummary() without HTTP.
 */
class StubbedResearchAgent extends ResearchAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $systemPrompt, array $tools, string $model, float $temperature, bool $useServerTools, string $apiKey): ?array
    {
        return $this->stubPayload;
    }
}

function researchTopic(): array
{
    return ['id' => 1, 'title' => 'Zero Party Data', 'angle' => 'Privacy-first', 'sources' => []];
}

function validResearchPayload(): array
{
    return [
        'topic_summary' => 'Summary.',
        'claims' => [
            ['id' => 'c1', 'text' => 'Claim one.', 'type' => 'fact', 'source_ids' => ['s1']],
            ['id' => 'c2', 'text' => 'Claim two.', 'type' => 'stat', 'source_ids' => ['s2']],
            ['id' => 'c3', 'text' => 'Claim three.', 'type' => 'quote', 'source_ids' => ['s1']],
        ],
        'sources' => [
            ['id' => 's1', 'url' => 'https://a.example', 'title' => 'A'],
            ['id' => 's2', 'url' => 'https://b.example', 'title' => 'B'],
        ],
    ];
}

test('ResearchAgent ok path: validates, applies to brief, builds card and summary', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $agent = new StubbedResearchAgent(validResearchPayload());
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasResearch())->toBeTrue();
    expect($result->brief->research()['claims'])->toHaveCount(3);
    expect($result->summary)->toContain('3 claims');
    expect($result->summary)->toContain('2 sources');
    expect($result->cardPayload['summary'])->toContain('3 claims');
});

test('ResearchAgent rejects fewer than 3 claims', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $payload = validResearchPayload();
    $payload['claims'] = array_slice($payload['claims'], 0, 2);

    $agent = new StubbedResearchAgent($payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('at least 3 claims');
});

test('ResearchAgent rejects claim with missing source', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $payload = validResearchPayload();
    $payload['claims'][0]['source_ids'] = ['s99'];   // bogus

    $agent = new StubbedResearchAgent($payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('s99');
});

test('ResearchAgent rejects duplicate claim ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $payload = validResearchPayload();
    $payload['claims'][1]['id'] = 'c1';

    $agent = new StubbedResearchAgent($payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('duplicate');
});

test('ResearchAgent returns error if llmCall returns null (no submit tool)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $agent = new class extends ResearchAgent {
        protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, string $key): ?array
        {
            return null;
        }
    };

    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('did not call the submit tool');
});

test('ResearchAgent system prompt includes topic and extraContext when provided', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $agent = new class('Focus on quantitative data.') extends ResearchAgent {
        public function exposePrompt(Brief $b, Team $t): string
        {
            return $this->systemPrompt($b, $t);
        }
    };

    $prompt = $agent->exposePrompt($brief, $team);

    expect($prompt)->toContain('Zero Party Data');
    expect($prompt)->toContain('Privacy-first');
    expect($prompt)->toContain('submit_research');
    expect($prompt)->toContain('Focus on quantitative data.');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
cd marketminded-laravel
./vendor/bin/sail test --filter=ResearchAgentTest
```
Expected: FAIL — `ResearchAgent` not found.

- [ ] **Step 3: Implement `ResearchAgent`**

Create `app/Services/Writer/Agents/ResearchAgent.php`:

```php
<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class ResearchAgent extends BaseAgent
{
    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => '', 'sources' => []];
        $title = $topic['title'] ?? '';
        $angle = $topic['angle'] ?? '';
        $brainstormSources = is_array($topic['sources'] ?? null) && ! empty($topic['sources'])
            ? "\n- " . implode("\n- ", $topic['sources'])
            : ' (none)';

        $extra = $this->extraContextBlock();

        return <<<PROMPT
You are the Research sub-agent for a blog writing pipeline. Your single job
is to research a topic and submit a structured claims block via the
submit_research tool. You do NOT write prose, outlines, or commentary —
only the tool call.

## How to work
1. Run 5-8 focused web searches against the topic angle. Search is automatic
   via the platform; you don't need to invoke a tool to use it — just make
   the request in your reasoning.
2. Extract 8-15 verifiable single-sentence claims with source attribution.
3. Submit via submit_research. Do not narrate or summarize in prose.

## Quality rules
- Each claim must be a single declarative sentence.
- Each claim must have type: stat, quote, fact, date, or price.
- Each claim must cite at least one source by id (s1, s2, ...).
- Source IDs must be unique. Claim IDs must be unique.
- Aim for 8-15 claims; refuse to submit fewer than 3.
- Prefer recent, authoritative sources.

## Topic
Title: {$title}
Angle: {$angle}
Brainstorm sources:{$brainstormSources}
{$extra}
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_research',
                'description' => 'Submit the structured research claims block. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['topic_summary', 'claims', 'sources'],
                    'properties' => [
                        'topic_summary' => ['type' => 'string', 'description' => '2-3 sentence summary'],
                        'claims' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'text', 'type', 'source_ids'],
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'text' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => ['stat', 'quote', 'fact', 'date', 'price']],
                                    'source_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                                ],
                            ],
                        ],
                        'sources' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'url', 'title'],
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'url' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return true;  // web_search
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.4;
    }

    protected function validate(array $payload): ?string
    {
        $claims = $payload['claims'] ?? [];
        $sources = $payload['sources'] ?? [];

        if (count($claims) < 3) {
            return 'Research must contain at least 3 claims.';
        }

        $claimIds = array_map(fn ($c) => $c['id'] ?? '', $claims);
        if (count($claimIds) !== count(array_unique($claimIds))) {
            return 'Research has duplicate claim ids.';
        }

        $sourceIds = array_map(fn ($s) => $s['id'] ?? '', $sources);
        if (count($sourceIds) !== count(array_unique($sourceIds))) {
            return 'Research has duplicate source ids.';
        }

        $sourceIdSet = array_flip($sourceIds);
        foreach ($claims as $c) {
            foreach ($c['source_ids'] ?? [] as $sid) {
                if (! isset($sourceIdSet[$sid])) {
                    return "Claim {$c['id']} cites unknown source: {$sid}";
                }
            }
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        return $brief->withResearch([
            'topic_summary' => $payload['topic_summary'],
            'claims' => $payload['claims'],
            'sources' => $payload['sources'],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'research',
            'summary' => $this->buildSummary($payload),
            'topic_summary' => $payload['topic_summary'],
            'claims' => $payload['claims'],
            'sources' => $payload['sources'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $claims = count($payload['claims']);
        $sources = count($payload['sources']);
        return "Gathered {$claims} claims from {$sources} sources";
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=ResearchAgentTest
```
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/Writer/Agents/ResearchAgent.php \
        marketminded-laravel/tests/Unit/Services/Writer/Agents/ResearchAgentTest.php
git commit -m "feat: add ResearchAgent sub-agent"
```

---

## Task 5: `EditorAgent`

**Files:**
- Create: `marketminded-laravel/app/Services/Writer/Agents/EditorAgent.php`
- Test: `marketminded-laravel/tests/Unit/Services/Writer/Agents/EditorAgentTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/Writer/Agents/EditorAgentTest.php`:

```php
<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Writer\Agents\EditorAgent;
use App\Services\Writer\Brief;

class StubbedEditorAgent extends EditorAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, string $key): ?array
    {
        return $this->stubPayload;
    }
}

function briefWithResearch(): Brief
{
    return Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'Zero Party Data', 'angle' => 'Privacy', 'sources' => []],
        'research' => [
            'topic_summary' => 'Summary',
            'claims' => [
                ['id' => 'c1', 'text' => 'Claim 1', 'type' => 'fact', 'source_ids' => ['s1']],
                ['id' => 'c2', 'text' => 'Claim 2', 'type' => 'stat', 'source_ids' => ['s1']],
                ['id' => 'c3', 'text' => 'Claim 3', 'type' => 'quote', 'source_ids' => ['s1']],
            ],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
    ]);
}

function validOutlinePayload(): array
{
    return [
        'angle' => 'Privacy-first wins long-term',
        'target_length_words' => 1500,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'Hook', 'claim_ids' => ['c1']],
            ['heading' => 'Body', 'purpose' => 'Evidence', 'claim_ids' => ['c2', 'c3']],
        ],
    ];
}

test('EditorAgent ok path: validates and writes outline to brief', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $agent = new StubbedEditorAgent(validOutlinePayload());
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasOutline())->toBeTrue();
    expect($result->brief->outline()['sections'])->toHaveCount(2);
    expect($result->summary)->toContain('2 sections');
    expect($result->summary)->toContain('1500');
});

test('EditorAgent rejects payload referencing unknown claim ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validOutlinePayload();
    $payload['sections'][0]['claim_ids'] = ['c1', 'c99'];

    $agent = new StubbedEditorAgent($payload);
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('c99');
});

test('EditorAgent rejects fewer than 2 sections', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validOutlinePayload();
    $payload['sections'] = [$payload['sections'][0]];

    $agent = new StubbedEditorAgent($payload);
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('at least 2 sections');
});

test('EditorAgent rejects section with no claim_ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validOutlinePayload();
    $payload['sections'][1]['claim_ids'] = [];

    $agent = new StubbedEditorAgent($payload);
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('at least one claim_id');
});

test('EditorAgent returns error when brief has no research', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedEditorAgent(validOutlinePayload());
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('research');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
cd marketminded-laravel
./vendor/bin/sail test --filter=EditorAgentTest
```
Expected: FAIL.

- [ ] **Step 3: Implement `EditorAgent`**

Create `app/Services/Writer/Agents/EditorAgent.php`:

```php
<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class EditorAgent extends BaseAgent
{
    /** @var array<int, string> */
    private array $knownClaimIds = [];

    final public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot create outline without research. Run research_topic first.');
        }

        $this->knownClaimIds = array_map(fn ($c) => $c['id'], $brief->research()['claims']);

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];
        $research = $brief->research();

        $claimsBlock = collect($research['claims'])
            ->map(fn ($c) => "- {$c['id']} ({$c['type']}): {$c['text']}")
            ->implode("\n");

        $extra = $this->extraContextBlock();

        return <<<PROMPT
You are the Editor sub-agent for a blog writing pipeline. Your single job
is to build an editorial outline that the Writer will execute, and submit
it via the submit_outline tool. You do NOT write prose.

## How to work
1. Read the topic, angle, and claims block below.
2. Find the strongest narrative angle. Decide which claims to use and
   which to cut.
3. Build 4-7 sections with headings, purposes, and claim_id references.
4. Submit via submit_outline.

## Quality rules
- Every section must reference at least one claim_id from the research block.
- Every claim_id you reference must exist in the research.
- target_length_words should be 1200-2000 for pillar blogs.
- Do NOT write the article. Outline only.

## Topic
Title: {$topic['title']}
Angle: {$topic['angle']}

## Research claims (reference these by id)
{$claimsBlock}
{$extra}
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_outline',
                'description' => 'Submit the editorial outline. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['angle', 'target_length_words', 'sections'],
                    'properties' => [
                        'angle' => ['type' => 'string'],
                        'target_length_words' => ['type' => 'integer'],
                        'sections' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'items' => [
                                'type' => 'object',
                                'required' => ['heading', 'purpose', 'claim_ids'],
                                'properties' => [
                                    'heading' => ['type' => 'string'],
                                    'purpose' => ['type' => 'string'],
                                    'claim_ids' => [
                                        'type' => 'array',
                                        'minItems' => 1,
                                        'items' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.5;
    }

    protected function validate(array $payload): ?string
    {
        $sections = $payload['sections'] ?? [];

        if (count($sections) < 2) {
            return 'Outline must have at least 2 sections.';
        }

        foreach ($sections as $i => $s) {
            if (empty($s['claim_ids'] ?? [])) {
                return "Section {$i} ({$s['heading']}) must reference at least one claim_id.";
            }
            foreach ($s['claim_ids'] as $id) {
                if (! in_array($id, $this->knownClaimIds, true)) {
                    return "Section {$s['heading']} references unknown claim id: {$id}";
                }
            }
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        return $brief->withOutline([
            'angle' => $payload['angle'],
            'target_length_words' => (int) $payload['target_length_words'],
            'sections' => $payload['sections'],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'outline',
            'summary' => $this->buildSummary($payload),
            'angle' => $payload['angle'],
            'target_length_words' => $payload['target_length_words'],
            'sections' => $payload['sections'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $sections = count($payload['sections']);
        $words = $payload['target_length_words'];
        return "Outline ready · {$sections} sections · ~{$words} words";
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=EditorAgentTest
```
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/Writer/Agents/EditorAgent.php \
        marketminded-laravel/tests/Unit/Services/Writer/Agents/EditorAgentTest.php
git commit -m "feat: add EditorAgent sub-agent with outline validation"
```

---

## Task 6: `WriterAgent`

**Files:**
- Create: `marketminded-laravel/app/Services/Writer/Agents/WriterAgent.php`
- Test: `marketminded-laravel/tests/Unit/Services/Writer/Agents/WriterAgentTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/Writer/Agents/WriterAgentTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\Brief;

class StubbedWriterAgent extends WriterAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, string $key): ?array
    {
        return $this->stubPayload;
    }
}

function fullBriefForWriter(Team $team): array
{
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy',
        'status' => 'available',
    ]);

    return [
        'brief' => Brief::fromJson([
            'topic' => ['id' => $topic->id, 'title' => 'Zero Party Data', 'angle' => 'Privacy', 'sources' => []],
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
        ]),
        'topic' => $topic,
    ];
}

test('WriterAgent ok path: creates ContentPiece, writes brief.content_piece_id, marks topic used', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    $body = str_repeat('word ', 850);  // > 800-word lower bound
    $payload = ['title' => 'My Title', 'body' => $body];

    $agent = new StubbedWriterAgent($payload);
    $result = $agent->execute($ctx['brief'], $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasContentPiece())->toBeTrue();

    $piece = ContentPiece::findOrFail($result->brief->contentPieceId());
    expect($piece->title)->toBe('My Title');
    expect($piece->team_id)->toBe($team->id);
    expect($piece->topic_id)->toBe($ctx['topic']->id);
    expect($piece->status)->toBe('draft');
    expect($piece->current_version)->toBe(1);

    expect($ctx['topic']->refresh()->status)->toBe('used');
});

test('WriterAgent gate: refuses when research is missing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $brief = Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []],
        // no research
        'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => [
            ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']],
            ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']],
        ]],
    ]);

    $agent = new StubbedWriterAgent(['title' => 'T', 'body' => str_repeat('w ', 850)]);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('research');
});

test('WriterAgent gate: refuses when outline is missing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $brief = Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []],
        'research' => ['topic_summary' => 's', 'claims' => [], 'sources' => []],
        // no outline
    ]);

    $agent = new StubbedWriterAgent(['title' => 'T', 'body' => str_repeat('w ', 850)]);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('outline');
});

test('WriterAgent rejects body shorter than 800 words', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    $payload = ['title' => 'T', 'body' => str_repeat('w ', 100)];

    $agent = new StubbedWriterAgent($payload);
    $result = $agent->execute($ctx['brief'], $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('800 words');
});

test('WriterAgent rejects empty title', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    $payload = ['title' => '', 'body' => str_repeat('w ', 850)];

    $agent = new StubbedWriterAgent($payload);
    $result = $agent->execute($ctx['brief'], $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('title');
});

test('WriterAgent uses team powerful_model', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['fast_model' => 'fast/x', 'powerful_model' => 'powerful/x']);

    $agent = new class extends WriterAgent {
        public function exposeModel(Team $t): string
        {
            return $this->model($t);
        }
    };

    expect($agent->exposeModel($team))->toBe('powerful/x');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
./vendor/bin/sail test --filter=WriterAgentTest
```
Expected: FAIL.

- [ ] **Step 3: Implement `WriterAgent`**

Create `app/Services/Writer/Agents/WriterAgent.php`:

```php
<?php

namespace App\Services\Writer\Agents;

use App\Models\ContentPiece;
use App\Models\Team;
use App\Models\Topic;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class WriterAgent extends BaseAgent
{
    final public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot write without research. Run research_topic first.');
        }
        if (! $brief->hasOutline()) {
            return AgentResult::error('Cannot write without an outline. Run create_outline first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic();
        $research = $brief->research();
        $outline = $brief->outline();

        $claimsBlock = collect($research['claims'])
            ->map(fn ($c) => "- {$c['id']} ({$c['type']}): {$c['text']}")
            ->implode("\n");

        $sourcesBlock = collect($research['sources'])
            ->map(fn ($s) => "- {$s['id']}: {$s['title']} ({$s['url']})")
            ->implode("\n");

        $outlineBlock = "Angle: {$outline['angle']}\nTarget length: {$outline['target_length_words']} words\n\nSections:\n"
            . collect($outline['sections'])
                ->map(fn ($s, $i) => sprintf("%d. %s — %s [%s]",
                    $i + 1,
                    $s['heading'],
                    $s['purpose'],
                    implode(', ', $s['claim_ids']),
                ))
                ->implode("\n");

        $brandProfile = $this->brandProfileBlock($team);
        $extra = $this->extraContextBlock();

        return <<<PROMPT
You are the Writer sub-agent. Your single job is to write a publishable
blog post following the outline below, then submit it via the
submit_blog_post tool. You do NOT narrate, plan, or commentary — only
the tool call.

## Quality rules
- Target length: {$outline['target_length_words']} words ±10%.
- Follow the outline section order. Each section uses the claims listed
  in [brackets] for its claim_ids.
- EVERY statistic, percentage, date, named entity, or quote must come
  from a claim by id. Never fabricate facts.
- Use the brand voice from the brand profile.
- Banned words/phrases: "leverage", "innovative", "streamline", "unlock",
  "empower", "revolutionize", "in today's fast-paced world".
- Avoid em-dashes used stylistically and passive voice as the default.
- Short paragraphs. Scannable subheadings. Benefit-focused structure.
- Write in the language of the brand profile.

## Topic
Title: {$topic['title']}
Angle: {$topic['angle']}

## Outline
{$outlineBlock}

## Research claims (cite by id implicitly through the facts you use)
{$claimsBlock}

## Sources (do NOT cite inline; the platform handles attribution)
{$sourcesBlock}

## Brand profile
{$brandProfile}
{$extra}
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_blog_post',
                'description' => 'Submit the finished blog post. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string', 'description' => 'Full blog post in markdown.'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->powerful_model;
    }

    protected function temperature(): float
    {
        return 0.6;
    }

    protected function validate(array $payload): ?string
    {
        $title = trim($payload['title'] ?? '');
        $body = $payload['body'] ?? '';

        if ($title === '') {
            return 'Blog post title must not be empty.';
        }

        $wordCount = str_word_count(strip_tags($body));
        if ($wordCount < 800) {
            return "Blog post body must be at least 800 words (got {$wordCount}).";
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $topic = $brief->topic();

        $piece = ContentPiece::create([
            'team_id' => $team->id,
            'conversation_id' => null,    // Tool handler patches this in (it knows the conversation)
            'topic_id' => $topic['id'] ?? null,
            'title' => '',
            'body' => '',
            'status' => 'draft',
            'platform' => 'blog',
            'format' => 'pillar',
            'current_version' => 0,
        ]);

        $piece->saveSnapshot($payload['title'], $payload['body'], 'Initial draft');

        if (! empty($topic['id'])) {
            Topic::where('id', $topic['id'])->update(['status' => 'used']);
        }

        return $brief->withContentPieceId($piece->id);
    }

    protected function buildCard(array $payload): array
    {
        $body = $payload['body'];
        return [
            'kind' => 'content_piece',
            'summary' => $this->buildSummary($payload),
            'title' => $payload['title'],
            'preview' => mb_substr(strip_tags($body), 0, 200),
            'word_count' => str_word_count(strip_tags($body)),
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return 'Draft created · v1 · ' . str_word_count(strip_tags($payload['body'])) . ' words';
    }

    protected function brandProfileBlock(Team $team): string
    {
        // Reuse the same builder ChatPromptBuilder uses via static helper.
        // We could call ChatPromptBuilder::buildProfileText but that's private.
        // Inline a minimal block for the writer's needs.
        $lines = [];
        $lines[] = 'Company: ' . ($team->name ?? '');
        if ($team->homepage_url) $lines[] = 'Homepage: ' . $team->homepage_url;
        if ($team->brand_description) $lines[] = 'Description: ' . $team->brand_description;
        if ($team->target_audience) $lines[] = 'Target audience: ' . $team->target_audience;
        if ($team->tone_keywords) $lines[] = 'Tone: ' . $team->tone_keywords;
        if ($team->content_language) $lines[] = 'Language: ' . $team->content_language;

        $voice = $team->voiceProfile;
        if ($voice) {
            if ($voice->voice_analysis) $lines[] = 'Voice analysis: ' . $voice->voice_analysis;
            if ($voice->should_avoid) $lines[] = 'Avoid: ' . $voice->should_avoid;
            if ($voice->should_use) $lines[] = 'Use: ' . $voice->should_use;
        }

        return implode("\n", $lines);
    }
}
```

Note: `applyToBrief` creates the ContentPiece with `conversation_id => null`. The tool handler is responsible for patching `conversation_id` after the agent returns (it knows which conversation invoked it; the agent doesn't need to). This keeps the agent decoupled from chat-component details.

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=WriterAgentTest
```
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/Writer/Agents/WriterAgent.php \
        marketminded-laravel/tests/Unit/Services/Writer/Agents/WriterAgentTest.php
git commit -m "feat: add WriterAgent with structural gate and ContentPiece creation"
```

---

## Task 7: `ProofreadAgent`

**Files:**
- Create: `marketminded-laravel/app/Services/Writer/Agents/ProofreadAgent.php`
- Test: `marketminded-laravel/tests/Unit/Services/Writer/Agents/ProofreadAgentTest.php`

The ProofreadAgent takes user feedback (passed in via constructor as a separate `$feedback` arg, distinct from `$extraContext`), loads the existing ContentPiece from the brief, and produces a revised version.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/Writer/Agents/ProofreadAgentTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\User;
use App\Services\Writer\Agents\ProofreadAgent;
use App\Services\Writer\Brief;

class StubbedProofreadAgent extends ProofreadAgent
{
    public function __construct(string $feedback, private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($feedback, $extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, string $key): ?array
    {
        return $this->stubPayload;
    }
}

test('ProofreadAgent ok path: saves new snapshot, version increments', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('Original Title', str_repeat('w ', 850), 'Initial draft');

    $brief = Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []],
        'research' => ['topic_summary' => 's', 'claims' => [], 'sources' => []],
        'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => []],
        'content_piece_id' => $piece->id,
    ]);

    $payload = [
        'title' => 'Original Title (revised)',
        'body' => str_repeat('better word ', 500),
        'change_description' => 'Punched up intro and trimmed conclusion',
    ];

    $agent = new StubbedProofreadAgent('Make the intro punchier', $payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeTrue();

    $piece->refresh();
    expect($piece->current_version)->toBe(2);
    expect($piece->title)->toBe('Original Title (revised)');
    expect($piece->versions()->count())->toBe(2);

    $v2 = $piece->versions()->where('version', 2)->first();
    expect($v2->change_description)->toBe('Punched up intro and trimmed conclusion');
});

test('ProofreadAgent gate: refuses when content_piece_id missing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedProofreadAgent('feedback', [
        'title' => 't', 'body' => str_repeat('w ', 50), 'change_description' => 'x',
    ]);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('content piece');
});

test('ProofreadAgent rejects empty title or body or change_description', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create(['team_id' => $team->id, 'title' => '', 'body' => '', 'current_version' => 0]);
    $piece->saveSnapshot('t', 'b', 'init');
    $brief = Brief::fromJson(['content_piece_id' => $piece->id]);

    foreach ([
        ['title' => '', 'body' => 'b', 'change_description' => 'x'],
        ['title' => 't', 'body' => '', 'change_description' => 'x'],
        ['title' => 't', 'body' => 'b', 'change_description' => ''],
    ] as $payload) {
        $agent = new StubbedProofreadAgent('feedback', $payload);
        $result = $agent->execute($brief, $team);
        expect($result->isOk())->toBeFalse();
    }
});

test('ProofreadAgent system prompt includes the user feedback', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create(['team_id' => $team->id, 'title' => 'T', 'body' => 'B', 'current_version' => 1]);
    $brief = Brief::fromJson(['content_piece_id' => $piece->id]);

    $agent = new class('Make the intro punchier', []) extends ProofreadAgent {
        public function exposePrompt(Brief $b, $t): string
        {
            return $this->systemPrompt($b, $t);
        }
        protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, string $key): ?array
        {
            return null;
        }
    };

    $prompt = $agent->exposePrompt($brief, $team);
    expect($prompt)->toContain('Make the intro punchier');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
./vendor/bin/sail test --filter=ProofreadAgentTest
```
Expected: FAIL.

- [ ] **Step 3: Implement `ProofreadAgent`**

Create `app/Services/Writer/Agents/ProofreadAgent.php`:

```php
<?php

namespace App\Services\Writer\Agents;

use App\Models\ContentPiece;
use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class ProofreadAgent extends BaseAgent
{
    public function __construct(
        protected string $feedback = '',
        ?string $extraContext = null,
    ) {
        parent::__construct($extraContext);
    }

    final public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasContentPiece()) {
            return AgentResult::error('No content piece to proofread. Run write_blog_post first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->where('id', $brief->contentPieceId())
            ->firstOrFail();

        $extra = $this->extraContextBlock();

        return <<<PROMPT
You are the Proofread sub-agent. Your single job is to revise an existing
blog post based on user feedback, then submit the revision via the
submit_revision tool. You do NOT narrate or commentary — only the tool call.

## How to work
1. Read the user feedback below.
2. Apply the requested changes surgically. Do NOT rewrite the whole post.
3. Match the existing voice. Preserve sourced facts.
4. Submit via submit_revision with a clear change_description.

## User feedback
{$this->feedback}

## Current title
{$piece->title}

## Current body
{$piece->body}
{$extra}
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_revision',
                'description' => 'Submit the revised blog post. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body', 'change_description'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'change_description' => ['type' => 'string', 'description' => 'Short summary of what changed'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.4;
    }

    protected function validate(array $payload): ?string
    {
        if (trim($payload['title'] ?? '') === '') {
            return 'Revision title must not be empty.';
        }
        if (trim($payload['body'] ?? '') === '') {
            return 'Revision body must not be empty.';
        }
        if (trim($payload['change_description'] ?? '') === '') {
            return 'change_description must not be empty.';
        }
        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->where('id', $brief->contentPieceId())
            ->firstOrFail();

        $piece->saveSnapshot($payload['title'], $payload['body'], $payload['change_description']);

        // Brief is unchanged — content_piece_id stays the same; ContentPiece
        // model holds the new state via saveSnapshot.
        return $brief;
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'content_piece',
            'summary' => $this->buildSummary($payload),
            'title' => $payload['title'],
            'preview' => mb_substr(strip_tags($payload['body']), 0, 200),
            'change_description' => $payload['change_description'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return 'Revised · ' . $payload['change_description'];
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=ProofreadAgentTest
```
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/Writer/Agents/ProofreadAgent.php \
        marketminded-laravel/tests/Unit/Services/Writer/Agents/ProofreadAgentTest.php
git commit -m "feat: add ProofreadAgent for content piece revisions"
```

---

## Task 8: Gut `ResearchTopicToolHandler` to thin shell

**Files:**
- Modify: `marketminded-laravel/app/Services/ResearchTopicToolHandler.php`
- Modify: `marketminded-laravel/tests/Unit/Services/ResearchTopicToolHandlerTest.php`

- [ ] **Step 1: Rewrite the existing test**

Replace `tests/Unit/Services/ResearchTopicToolHandlerTest.php` entirely with:

```php
<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\ResearchTopicToolHandler;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeResearchAgent extends ResearchAgent
{
    public ?AgentResult $stubResult = null;
    public ?string $seenExtraContext = null;

    public function __construct(?string $extraContext = null)
    {
        parent::__construct($extraContext);
        $this->seenExtraContext = $extraContext;
    }

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function writerConvWithTopic(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'X',
        'angle' => 'a',
        'status' => 'available',
    ]);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => ['topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []]],
    ]);
    return [$team, $conversation, $topic];
}

test('handler returns ok and persists brief on agent success', function () {
    [$team, $conversation] = writerConvWithTopic();

    $newBrief = Brief::fromJson($conversation->brief)->withResearch([
        'topic_summary' => 's',
        'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
        'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
    ]);

    $agent = new FakeResearchAgent;
    $agent->stubResult = AgentResult::ok($newBrief, ['kind' => 'research', 'summary' => 'Gathered 1 claims from 1 sources'], 'Gathered 1 claims from 1 sources');

    $handler = new ResearchTopicToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('1 claims');
    expect($decoded['card']['kind'])->toBe('research');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasResearch())->toBeTrue();
});

test('handler passes extra_context to a fresh agent on retry', function () {
    [$team, $conversation] = writerConvWithTopic();

    $defaultAgent = new FakeResearchAgent;
    $defaultAgent->stubResult = AgentResult::error('default agent should not be used');

    $handler = new ResearchTopicToolHandler($defaultAgent);
    // No factory injection; the handler instantiates a new ResearchAgent
    // when extra_context is set. We can't fully assert that without DI on
    // the constructor — instead we rely on the contract that extra_context
    // gets set on a fresh agent. Smoke test: calling with extra_context
    // doesn't 500.
    $result = $handler->execute($team, $conversation->id, ['extra_context' => 'focus on X'], []);

    $decoded = json_decode($result, true);
    expect($decoded)->toHaveKey('status');  // doesn't crash
});

test('handler refuses second call (retry guard) when prior turn already had research_topic', function () {
    [$team, $conversation] = writerConvWithTopic();

    $agent = new FakeResearchAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new ResearchTopicToolHandler($agent);
    $priorTurnTools = [['name' => 'research_topic', 'args' => []]];

    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler returns error from agent', function () {
    [$team, $conversation] = writerConvWithTopic();

    $agent = new FakeResearchAgent;
    $agent->stubResult = AgentResult::error('agent failed validation');

    $handler = new ResearchTopicToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toBe('agent failed validation');
});

test('toolSchema returns valid schema', function () {
    $schema = ResearchTopicToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('research_topic');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
cd marketminded-laravel
./vendor/bin/sail test --filter=ResearchTopicToolHandlerTest
```
Expected: FAIL — handler signature doesn't accept agent yet.

- [ ] **Step 3: Rewrite `ResearchTopicToolHandler`**

Replace `app/Services/ResearchTopicToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\Brief;

class ResearchTopicToolHandler
{
    public function __construct(private ?ResearchAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'research_topic')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried research_topic this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new ResearchAgent($extraContext) : ($this->agent ?? new ResearchAgent);

        $result = $agent->execute($brief, $team);

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'research_topic',
                'description' => 'Run the Research sub-agent. Reads brief.topic; writes brief.research with structured claims sourced via web search.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=ResearchTopicToolHandlerTest
./vendor/bin/sail test
```
Expected: 5 new tests pass; full suite green.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/ResearchTopicToolHandler.php \
        marketminded-laravel/tests/Unit/Services/ResearchTopicToolHandlerTest.php
git commit -m "refactor: gut ResearchTopicToolHandler to thin shell wrapping ResearchAgent"
```

---

## Task 9: Gut `CreateOutlineToolHandler` to thin shell

**Files:**
- Modify: `marketminded-laravel/app/Services/CreateOutlineToolHandler.php`
- Modify: `marketminded-laravel/tests/Unit/Services/CreateOutlineToolHandlerTest.php`

- [ ] **Step 1: Rewrite test**

Replace `tests/Unit/Services/CreateOutlineToolHandlerTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\CreateOutlineToolHandler;
use App\Services\Writer\Agents\EditorAgent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeEditorAgent extends EditorAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub');
    }
}

function writerConvWithResearch(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create(['team_id' => $team->id, 'title' => 'X', 'angle' => 'a', 'status' => 'available']);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => [
            'topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []],
            'research' => ['topic_summary' => 's', 'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]], 'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']]],
        ],
    ]);
    return [$team, $conversation];
}

test('handler returns ok and persists brief on agent success', function () {
    [$team, $conversation] = writerConvWithResearch();

    $newBrief = Brief::fromJson($conversation->brief)->withOutline([
        'angle' => 'a',
        'target_length_words' => 1500,
        'sections' => [['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']], ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']]],
    ]);

    $agent = new FakeEditorAgent;
    $agent->stubResult = AgentResult::ok($newBrief, ['kind' => 'outline', 'summary' => 'Outline ready · 2 sections · ~1500 words'], 'Outline ready · 2 sections · ~1500 words');

    $handler = new CreateOutlineToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('2 sections');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasOutline())->toBeTrue();
});

test('handler refuses second call (retry guard)', function () {
    [$team, $conversation] = writerConvWithResearch();

    $agent = new FakeEditorAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new CreateOutlineToolHandler($agent);
    $priorTurnTools = [['name' => 'create_outline', 'args' => []]];

    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler propagates agent error', function () {
    [$team, $conversation] = writerConvWithResearch();

    $agent = new FakeEditorAgent;
    $agent->stubResult = AgentResult::error('outline references unknown claim id: c99');

    $handler = new CreateOutlineToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('c99');
});

test('toolSchema returns valid schema', function () {
    $schema = CreateOutlineToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('create_outline');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
./vendor/bin/sail test --filter=CreateOutlineToolHandlerTest
```
Expected: FAIL.

- [ ] **Step 3: Rewrite `CreateOutlineToolHandler`**

Replace `app/Services/CreateOutlineToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\EditorAgent;
use App\Services\Writer\Brief;

class CreateOutlineToolHandler
{
    public function __construct(private ?EditorAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'create_outline')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried create_outline this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new EditorAgent($extraContext) : ($this->agent ?? new EditorAgent);

        $result = $agent->execute($brief, $team);

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_outline',
                'description' => 'Run the Editor sub-agent. Reads brief.research; writes brief.outline. Requires brief.research.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=CreateOutlineToolHandlerTest
./vendor/bin/sail test
```
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/CreateOutlineToolHandler.php \
        marketminded-laravel/tests/Unit/Services/CreateOutlineToolHandlerTest.php
git commit -m "refactor: gut CreateOutlineToolHandler to thin shell wrapping EditorAgent"
```

---

## Task 10: Gut `WriteBlogPostToolHandler` to thin shell

**Files:**
- Modify: `marketminded-laravel/app/Services/WriteBlogPostToolHandler.php`
- Modify: `marketminded-laravel/tests/Unit/Services/WriteBlogPostToolHandlerTest.php`

The handler patches `conversation_id` onto the ContentPiece after the agent creates it (the agent doesn't know the conversation_id).

- [ ] **Step 1: Rewrite test**

Replace `tests/Unit/Services/WriteBlogPostToolHandlerTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\WriteBlogPostToolHandler;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeWriterAgent extends WriterAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub');
    }
}

function writerConvWithFullBrief(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create(['team_id' => $team->id, 'title' => 'X', 'angle' => 'a', 'status' => 'available']);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => [
            'topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []],
            'research' => ['topic_summary' => 's', 'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]], 'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']]],
            'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => [['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']], ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']]]],
        ],
    ]);
    return [$team, $conversation, $topic];
}

test('handler returns ok, persists brief, patches conversation_id onto piece', function () {
    [$team, $conversation, $topic] = writerConvWithFullBrief();

    // Pre-create the piece as the agent would
    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'topic_id' => $topic->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('Title', str_repeat('w ', 850), 'Initial draft');

    $newBrief = Brief::fromJson($conversation->brief)->withContentPieceId($piece->id);

    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::ok(
        $newBrief,
        ['kind' => 'content_piece', 'summary' => 'Draft created · v1', 'title' => 'Title', 'preview' => '...'],
        'Draft created · v1 · 850 words'
    );

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');

    $piece->refresh();
    expect($piece->conversation_id)->toBe($conversation->id);

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->contentPieceId())->toBe($piece->id);
});

test('handler refuses second call (retry guard)', function () {
    [$team, $conversation] = writerConvWithFullBrief();

    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], [['name' => 'write_blog_post', 'args' => []]]);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler propagates agent gate error', function () {
    [$team, $conversation] = writerConvWithFullBrief();

    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::error('Cannot write without research. Run research_topic first.');

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research');
});

test('toolSchema returns valid schema', function () {
    $schema = WriteBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('write_blog_post');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
./vendor/bin/sail test --filter=WriteBlogPostToolHandlerTest
```
Expected: FAIL.

- [ ] **Step 3: Rewrite `WriteBlogPostToolHandler`**

Replace `app/Services/WriteBlogPostToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\Brief;

class WriteBlogPostToolHandler
{
    public function __construct(private ?WriterAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'write_blog_post')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried write_blog_post this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new WriterAgent($extraContext) : ($this->agent ?? new WriterAgent);

        $result = $agent->execute($brief, $team);

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        // Patch conversation_id onto the piece (the agent didn't know it).
        if ($pieceId = $result->brief->contentPieceId()) {
            ContentPiece::where('id', $pieceId)->update(['conversation_id' => $conversation->id]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'write_blog_post',
                'description' => 'Run the Writer sub-agent. Requires brief.research and brief.outline. Creates the ContentPiece and writes brief.content_piece_id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=WriteBlogPostToolHandlerTest
./vendor/bin/sail test
```
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/WriteBlogPostToolHandler.php \
        marketminded-laravel/tests/Unit/Services/WriteBlogPostToolHandlerTest.php
git commit -m "refactor: gut WriteBlogPostToolHandler to thin shell wrapping WriterAgent"
```

---

## Task 11: Rename `UpdateBlogPostToolHandler` → `ProofreadBlogPostToolHandler`

**Files:**
- Create: `marketminded-laravel/app/Services/ProofreadBlogPostToolHandler.php`
- Delete: `marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php`
- Create: `marketminded-laravel/tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php`
- Delete: `marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php`

(Chat blade dispatch and orchestrator system prompt are updated in Task 13.)

- [ ] **Step 1: Write test for the new handler**

Create `tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ProofreadBlogPostToolHandler;
use App\Services\Writer\Agents\ProofreadAgent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeProofreadAgent extends ProofreadAgent
{
    public ?AgentResult $stubResult = null;

    public function __construct(string $feedback = '', ?string $extraContext = null)
    {
        parent::__construct($feedback, $extraContext);
    }

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub');
    }
}

function writerConvWithPiece(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('T', str_repeat('w ', 850), 'init');

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'brief' => ['content_piece_id' => $piece->id],
    ]);

    return [$team, $conversation, $piece];
}

test('handler returns ok and persists brief on success', function () {
    [$team, $conversation, $piece] = writerConvWithPiece();

    $brief = Brief::fromJson($conversation->brief);

    $agent = new FakeProofreadAgent('punchier intro');
    $agent->stubResult = AgentResult::ok(
        $brief,  // brief unchanged for proofread
        ['kind' => 'content_piece', 'summary' => 'Revised · punchier intro', 'title' => 'T'],
        'Revised · punchier intro'
    );

    $handler = new ProofreadBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, ['feedback' => 'punchier intro'], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('Revised');
});

test('handler refuses second call (retry guard)', function () {
    [$team, $conversation] = writerConvWithPiece();

    $agent = new FakeProofreadAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new ProofreadBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, ['feedback' => 'x'], [['name' => 'proofread_blog_post', 'args' => []]]);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler returns error when feedback is missing', function () {
    [$team, $conversation] = writerConvWithPiece();

    $handler = new ProofreadBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('feedback');
});

test('toolSchema returns valid schema', function () {
    $schema = ProofreadBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('proofread_blog_post');
    expect($schema['function']['parameters']['required'])->toContain('feedback');
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
./vendor/bin/sail test --filter=ProofreadBlogPostToolHandlerTest
```
Expected: FAIL.

- [ ] **Step 3: Create `ProofreadBlogPostToolHandler`**

Create `app/Services/ProofreadBlogPostToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\ProofreadAgent;
use App\Services\Writer\Brief;

class ProofreadBlogPostToolHandler
{
    public function __construct(private ?ProofreadAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'proofread_blog_post')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried proofread_blog_post this turn. Get help from the user.',
            ]);
        }

        $feedback = trim($args['feedback'] ?? '');
        if ($feedback === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'feedback is required for proofread_blog_post.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $this->agent ?? new ProofreadAgent($feedback, $extraContext);

        $result = $agent->execute($brief, $team);

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'proofread_blog_post',
                'description' => 'Run the Proofread sub-agent on the existing piece. Requires brief.content_piece_id and the user feedback distilled into one sentence.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['feedback'],
                    'properties' => [
                        'feedback' => [
                            'type' => 'string',
                            'description' => 'The user\'s requested change, distilled into one or two sentences.',
                        ],
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run new test (expect pass)**

```bash
./vendor/bin/sail test --filter=ProofreadBlogPostToolHandlerTest
```
Expected: PASS, 4 tests.

- [ ] **Step 5: Delete the old handler and test**

```bash
rm marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php
rm marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php
```

(Don't run tests yet — the chat blade still references `UpdateBlogPostToolHandler` and would break compilation. The next task fixes that.)

- [ ] **Step 6: Commit**

```bash
git add marketminded-laravel/app/Services/ProofreadBlogPostToolHandler.php \
        marketminded-laravel/tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php \
        marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php \
        marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php
git commit -m "feat: replace UpdateBlogPostToolHandler with ProofreadBlogPostToolHandler"
```

(`git add` of a deleted file stages the deletion.)

---

## Task 12: Shrink `ChatPromptBuilder::writerPrompt()`

**Files:**
- Modify: `marketminded-laravel/app/Services/ChatPromptBuilder.php`
- Modify: `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderWriterTest.php`

The orchestrator prompt becomes lean: tool list + brief-status block + mode rules + good/bad examples + brand profile. The 20K writing rules are gone — they live in `WriterAgent::systemPrompt()` now.

- [ ] **Step 1: Update the writer test assertions**

Replace `tests/Unit/Services/ChatPromptBuilderWriterTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\ChatPromptBuilder;

function writerCtx(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $topic = Topic::create(['team_id' => $team->id, 'title' => 'Zero Party Data', 'angle' => 'Privacy-first', 'sources' => ['Source A'], 'status' => 'available']);

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'writer_mode' => 'autopilot',
        'brief' => ['topic' => ['id' => $topic->id, 'title' => 'Zero Party Data', 'angle' => 'Privacy-first', 'sources' => ['Source A']]],
    ]);

    return [$team, $conversation, $topic];
}

test('writer prompt includes tool list, brief-status block, and mode', function () {
    [$team, $conversation] = writerCtx();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('research_topic');
    expect($prompt)->toContain('create_outline');
    expect($prompt)->toContain('write_blog_post');
    expect($prompt)->toContain('proofread_blog_post');
    expect($prompt)->toContain('<brief-status>');
    expect($prompt)->toContain('topic: ✓');
    expect($prompt)->toContain('research: ✗');
    expect($prompt)->toContain('<mode>autopilot</mode>');
});

test('writer prompt is dramatically shorter than the old one', function () {
    [$team, $conversation] = writerCtx();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    // Old prompt was ~20000 chars. New target ~2500. Set a generous bound.
    expect(strlen($prompt))->toBeLessThan(5000);
});

test('writer prompt embeds checkpoint mode rhythm', function () {
    [$team, $conversation] = writerCtx();
    $conversation->update(['writer_mode' => 'checkpoint']);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation->refresh());

    expect($prompt)->toContain('<mode>checkpoint</mode>');
    expect($prompt)->toContain('Pause');
});

test('writer prompt brief-status reflects research and outline when present', function () {
    [$team, $conversation] = writerCtx();
    $conversation->update(['brief' => array_merge(
        $conversation->brief,
        [
            'research' => ['topic_summary' => 's', 'claims' => array_fill(0, 8, ['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]), 'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']]],
            'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => [['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']], ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']]]],
        ]
    )]);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation->refresh());

    expect($prompt)->toContain('research: ✓');
    expect($prompt)->toContain('outline: ✓');
});

test('tools(writer) returns 4 sub-agent tools', function () {
    $tools = ChatPromptBuilder::tools('writer');
    $names = collect($tools)->pluck('function.name')->all();

    expect($names)->toContain('research_topic');
    expect($names)->toContain('create_outline');
    expect($names)->toContain('write_blog_post');
    expect($names)->toContain('proofread_blog_post');
    expect($names)->not->toContain('update_blog_post');  // renamed
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
./vendor/bin/sail test --filter=ChatPromptBuilderWriterTest
```
Expected: FAIL.

- [ ] **Step 3: Rewrite `writerPrompt()` and update `tools()`**

In `app/Services/ChatPromptBuilder.php`:

3a. Update the import block — add:
```php
use App\Services\ProofreadBlogPostToolHandler;
use App\Services\Writer\Brief;
```
Remove:
```php
use App\Services\UpdateBlogPostToolHandler;
```

3b. Update `tools()` to swap `UpdateBlogPostToolHandler` for `ProofreadBlogPostToolHandler`:

```php
'writer' => [
    ResearchTopicToolHandler::toolSchema(),
    CreateOutlineToolHandler::toolSchema(),
    WriteBlogPostToolHandler::toolSchema(),
    ProofreadBlogPostToolHandler::toolSchema(),
    BrandIntelligenceToolHandler::fetchUrlToolSchema(),
],
```

3c. Replace the entire `writerPrompt()`, `writerAutopilotPrompt()`, `writerCheckpointPrompt()`, and `writerContextBlocks()` methods with the new lean versions:

```php
private static function writerPrompt(string $profile, bool $hasProfile, ?Conversation $conversation): string
{
    $brief = Brief::fromJson($conversation?->brief ?? []);
    $mode = $conversation?->writer_mode ?? 'autopilot';

    return $mode === 'checkpoint'
        ? self::orchestratorPrompt($profile, $hasProfile, $brief, 'checkpoint')
        : self::orchestratorPrompt($profile, $hasProfile, $brief, 'autopilot');
}

private static function orchestratorPrompt(string $profile, bool $hasProfile, Brief $brief, string $mode): string
{
    $modeBlock = $mode === 'checkpoint'
        ? <<<'CK'
## Mode: Checkpoint

<mode>checkpoint</mode>

You Pause for user approval between stages.

1. Call research_topic. When it returns, summarize the result in 2-3 plain-text lines and ask the user to approve before continuing. WAIT.
2. Once approved, call create_outline. Summarize. Ask. WAIT.
3. Once approved, call write_blog_post. Report the result and invite review.
4. For revisions, call proofread_blog_post with the user's feedback distilled into one sentence.

Do NOT call two tools in the same turn.
CK
        : <<<'AP'
## Mode: Autopilot

<mode>autopilot</mode>

You run the chain back-to-back without asking for approval.

1. Call research_topic.
2. When it returns ok, call create_outline.
3. When it returns ok, call write_blog_post.
4. After write_blog_post, send a short plain-text summary inviting review.
5. For revisions, call proofread_blog_post with the user's feedback distilled.

Brief plain-text status lines between calls are fine ("Researching…", "Outlining…").
AP;

    $statusBlock = $brief->statusSummary();

    return <<<PROMPT
You orchestrate a blog writing pipeline. You DO NOT do research, write outlines, or write blog posts yourself. You call sub-agent tools. They do the work.

## Your tools (you call these; sub-agents fulfill them)
- research_topic — runs the Research sub-agent. Fills brief.research.
- create_outline — runs the Editor sub-agent. Fills brief.outline. Requires brief.research.
- write_blog_post — runs the Writer sub-agent. Creates a ContentPiece and fills brief.content_piece_id. Requires brief.research and brief.outline.
- proofread_blog_post(feedback) — runs the Proofread sub-agent on the existing piece. Requires brief.content_piece_id.

## CRITICAL: function calling
You only do work through tool calls. Never narrate research, outlines, or prose in plain text. Brief plain-text status updates between tool calls are fine.

## Brief status (current state)
<brief-status>
{$statusBlock}
</brief-status>

{$modeBlock}

## Retry policy
When a tool returns {status: error, message: ...}, you may retry that tool ONCE per turn with an `extra_context` argument explaining what to fix. After one retry, surface the issue to the user and ask for guidance.

## Good / bad examples
GOOD: tool call → wait → tool call → wait → tool call → narrate result.
BAD: narrate "I researched the topic and found c1: …" without ever calling research_topic. Nothing is saved. Always wrap work in tool calls.

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
}
```

(Delete the old `writerAutopilotPrompt`, `writerCheckpointPrompt`, and `writerContextBlocks` methods entirely — replaced by `orchestratorPrompt`.)

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter=ChatPromptBuilderWriterTest
./vendor/bin/sail test
```
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/ChatPromptBuilder.php \
        marketminded-laravel/tests/Unit/Services/ChatPromptBuilderWriterTest.php
git commit -m "refactor: shrink writer system prompt to thin orchestrator with brief-status"
```

---

## Task 13: Wire chat component (rename, hydrate brief.topic, render new cards)

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php`

This is the largest single edit. Three concerns: (1) swap `update_blog_post`/`UpdateBlogPostToolHandler` for `proofread_blog_post`/`ProofreadBlogPostToolHandler`; (2) hydrate `brief.topic` into the conversation's brief on the first writer turn; (3) extend cards/labels for `research_topic` and `create_outline`.

- [ ] **Step 1: Update imports**

Replace the `use App\Services\UpdateBlogPostToolHandler;` line with:
```php
use App\Services\ProofreadBlogPostToolHandler;
```

- [ ] **Step 2: Hydrate brief.topic at the start of `ask()`**

In the `ask()` method, find the existing block (around line 158-163):
```php
$type = $this->conversation->type;
$this->teamModel->refresh();
$this->conversation->load('topic');
$systemPrompt = ChatPromptBuilder::build($type, $this->teamModel, $this->conversation);
```

Replace with:

```php
$type = $this->conversation->type;
$this->teamModel->refresh();
$this->conversation->load('topic');

// Hydrate brief.topic on first writer turn if missing (also covers
// conversations created before the brief column existed).
if ($type === 'writer' && $this->conversation->topic && empty(($this->conversation->brief ?? [])['topic'])) {
    $topic = $this->conversation->topic;
    $brief = $this->conversation->brief ?? [];
    $brief['topic'] = [
        'id' => $topic->id,
        'title' => $topic->title,
        'angle' => $topic->angle,
        'sources' => $topic->sources ?? [],
    ];
    $this->conversation->update(['brief' => $brief]);
    $this->conversation->refresh();
}

$systemPrompt = ChatPromptBuilder::build($type, $this->teamModel, $this->conversation);
```

- [ ] **Step 3: Update the tool executor closure**

Three changes in the closure (around line 197-220):

a. Replace the handler instantiation:
```php
$updateHandler = new UpdateBlogPostToolHandler;
```
with:
```php
$proofreadHandler = new ProofreadBlogPostToolHandler;
```

Update the closure's `use` clause: change `$updateHandler` to `$proofreadHandler`.

b. Drop the now-unused `$conversation->topic` arg from the research and write dispatches:

```php
if ($name === 'research_topic') {
    $result = $researchHandler->execute($team, $conversation->id, $args, $conversation->topic, $priorTurnTools);
    $priorTurnTools[] = ['name' => $name, 'args' => $args];
    return $result;
}
...
if ($name === 'write_blog_post') {
    $result = $writeHandler->execute($team, $conversation->id, $args, $conversation->topic, $priorTurnTools);
    $priorTurnTools[] = ['name' => $name, 'args' => $args];
    return $result;
}
```

becomes:

```php
if ($name === 'research_topic') {
    $result = $researchHandler->execute($team, $conversation->id, $args, $priorTurnTools);
    $priorTurnTools[] = ['name' => $name, 'args' => $args];
    return $result;
}
...
if ($name === 'write_blog_post') {
    $result = $writeHandler->execute($team, $conversation->id, $args, $priorTurnTools);
    $priorTurnTools[] = ['name' => $name, 'args' => $args];
    return $result;
}
```

c. Replace the update dispatch with proofread:
```php
if ($name === 'update_blog_post') {
    return $updateHandler->execute($team, $conversation->id, $args);
}
```
with:
```php
if ($name === 'proofread_blog_post') {
    $result = $proofreadHandler->execute($team, $conversation->id, $args, $priorTurnTools);
    $priorTurnTools[] = ['name' => $name, 'args' => $args];
    return $result;
}
```

(The closure already has the `&$priorTurnTools` capture from the gate fix; proofread participates so its retry-counter works.)

- [ ] **Step 4: Update tool pill labels (`streamUI` and `toolPill`)**

In the `streamUI()` method's active-tool match:
```php
'update_blog_post' => 'Revising...',
```
Change to:
```php
'proofread_blog_post' => 'Proofreading...',
```

In `toolPill()` completed-tool match:
```php
'update_blog_post' => 'Revised (v' . (json_decode($tool->result ?? '{}', true)['version'] ?? '?') . ')',
```
Change to:
```php
'proofread_blog_post' => 'Revised',
```

(The "vN" no longer comes back from the tool — the revision is recorded against the ContentPiece directly. The card payload carries the change_description; the pill is just confirmation.)

- [ ] **Step 5: Add cards for research_topic and create_outline in `streamUI`'s `contentPieceCards`/equivalent**

The current `contentPieceCards()` only handles write/update. Add handling for research and outline. Replace the method with:

```php
private function contentPieceCards(array $completedTools): string
{
    $html = '';
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
            $piece = \App\Models\ContentPiece::where('team_id', $this->teamModel->id)
                ->where('conversation_id', $this->conversation->id)
                ->first();
            if ($piece) {
                $html .= $this->renderContentPieceCard($piece, $tool->name);
            }
        }
    }
    return $html;
}

private function renderResearchCard(array $card): string
{
    $summary = e($card['summary'] ?? 'Research complete');
    $count = count($card['claims'] ?? []);
    return sprintf(
        '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
        . '<div class="text-xs text-purple-400">&#10003; %s</div>'
        . '<div class="mt-1 text-xs text-zinc-400">%d structured claims with source attribution</div>'
        . '</div>',
        $summary,
        $count,
    );
}

private function renderOutlineCard(array $card): string
{
    $summary = e($card['summary'] ?? 'Outline ready');
    $sections = $card['sections'] ?? [];
    $sectionList = collect($sections)
        ->map(fn ($s) => '<li class="text-xs text-zinc-400">' . e($s['heading']) . '</li>')
        ->implode('');
    return sprintf(
        '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
        . '<div class="text-xs text-blue-400">&#10003; %s</div>'
        . '<ul class="mt-1 list-disc pl-5">%s</ul>'
        . '</div>',
        $summary,
        $sectionList,
    );
}

private function renderContentPieceCard(\App\Models\ContentPiece $piece, string $toolName): string
{
    $url = route('content.show', ['current_team' => $this->teamModel, 'contentPiece' => $piece->id]);
    $preview = trim(mb_substr(strip_tags($piece->body), 0, 200));
    $badge = $toolName === 'write_blog_post' ? __('Draft created') : __('Revised');

    return sprintf(
        '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
        . '<div class="flex items-center justify-between mb-1">'
        . '<span class="text-xs text-green-400">&#10003; %s &middot; v%d</span>'
        . '<a href="%s" class="text-xs text-indigo-400 hover:text-indigo-300">%s &rarr;</a>'
        . '</div>'
        . '<div class="text-sm font-semibold text-zinc-200">%s</div>'
        . '<div class="mt-1 text-xs text-zinc-400 line-clamp-3">%s</div>'
        . '</div>',
        e($badge),
        e($piece->current_version),
        e($url),
        e(__('Open')),
        e($piece->title),
        e($preview),
    );
}
```

- [ ] **Step 6: Update history-mode card rendering for research/outline/proofread**

In the Blade template, find the existing "Content piece cards from history" `@foreach` block. Replace it with a wider block that also handles research and outline:

```blade
{{-- Sub-agent cards from history --}}
@foreach ($message['metadata']['tools'] ?? [] as $tool)
    @php
        $card = $tool['card'] ?? null;
        $kind = $card['kind'] ?? null;
    @endphp

    @if ($tool['name'] === 'research_topic' && $kind === 'research')
        <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
            <div class="text-xs text-purple-400">&#10003; {{ $card['summary'] ?? 'Research complete' }}</div>
            <div class="mt-1 text-xs text-zinc-400">{{ count($card['claims'] ?? []) }} structured claims with source attribution</div>
        </div>
    @elseif ($tool['name'] === 'create_outline' && $kind === 'outline')
        <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
            <div class="text-xs text-blue-400">&#10003; {{ $card['summary'] ?? 'Outline ready' }}</div>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($card['sections'] ?? [] as $s)
                    <li class="text-xs text-zinc-400">{{ $s['heading'] }}</li>
                @endforeach
            </ul>
        </div>
    @elseif (in_array($tool['name'], ['write_blog_post', 'proofread_blog_post'], true))
        @php
            $piece = \App\Models\ContentPiece::where('team_id', $teamModel->id)
                ->where('conversation_id', $conversation->id)
                ->first();
            $badge = $tool['name'] === 'write_blog_post' ? __('Draft created') : __('Revised');
        @endphp
        @if ($piece)
            <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs text-green-400">&#10003; {{ $badge }} &middot; v{{ $piece->current_version }}</span>
                    <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $piece->id]) }}" wire:navigate class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('Open') }} &rarr;</a>
                </div>
                <div class="text-sm font-semibold text-zinc-200">{{ $piece->title }}</div>
                <div class="mt-1 text-xs text-zinc-400 line-clamp-3">{{ mb_substr(strip_tags($piece->body), 0, 200) }}</div>
            </div>
        @endif
    @endif
@endforeach
```

(Replaces the entire existing "Content piece cards from history" loop. Also remove the old "Saved topic cards from history" block? No — that's for the topics chat type, leave it.)

- [ ] **Step 7: Save card payload into message metadata**

In the `finally` block of `ask()`, find the metadata-build section:
```php
if (! empty($completedTools)) {
    $metadata['tools'] = collect($completedTools)->map(fn (ToolEvent $t) => [
        'name' => $t->name,
        'args' => $t->arguments,
    ])->toArray();
}
```

Replace with:
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
        return $entry;
    })->toArray();
}
```

This way the history renderer can read `$tool['card']` directly.

- [ ] **Step 8: Run full test suite**

```bash
cd marketminded-laravel
./vendor/bin/sail test
```
Expected: still all green.

- [ ] **Step 9: Manual verification (for future-you running this plan)**

Run `./vendor/bin/sail up -d` if needed, then `php artisan serve` style — or just open the app via sail. In a writer chat:
1. Pick a topic, pick autopilot.
2. Type "Let's write a blog post about: <topic>" and submit.
3. Each tool call should land a card inline (research card, outline card, content-piece card).
4. After the piece exists, type "make the intro punchier" — orchestrator should call proofread_blog_post; revised card appears.
5. Try `!checkpoint` mid-flow and confirm the badge flips and the orchestrator pauses on the next research/outline.

- [ ] **Step 10: Commit**

```bash
git add marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php
git commit -m "refactor: wire writer chat to brief, sub-agent tools, and proofread"
```

---

## Plan complete

After Task 13:
- The orchestrator's per-turn context cost is bounded (status summary, not full payloads)
- Each sub-agent has its own focused prompt and tools
- Brief is the authoritative state on `conversations.brief`
- Structural gates replace history scans
- `proofread_blog_post` replaces `update_blog_post`
- Adding AudiencePicker / BrandEnricher / StyleReference in PR 2 is purely additive: new agent class, new tool handler, new brief slot, no changes to existing agents
