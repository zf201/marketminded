# Reverb Event Bus Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the SSE-coupled streaming architecture with a Laravel Reverb WebSocket channel per conversation; every event (text chunks, sub-agent lifecycle, stop) flows as a typed message on one bus; stop works reliably; frontend renders events in arrival order.

**Architecture:** `ask()` stays a synchronous Livewire method but publishes events via a `ConversationBus` service instead of yielding through an SSE generator. `streamChatWithTools()` becomes a plain method (not a generator) that accepts a `ConversationBus` and publishes `text_chunk` events directly. Sub-agents receive the same bus instance and publish their own lifecycle events. Alpine.js + Laravel Echo listen on `private-conversation.{id}` and render an ordered `items[]` array. On turn complete, Livewire reloads message history from the database.

**Tech Stack:** Laravel Reverb, Laravel Echo, pusher-js, Alpine.js, Redis (already live), PHP synchronous execution.

---

## File Map

| Action | File |
|---|---|
| Create | `app/Events/ConversationEvent.php` |
| Create | `app/Services/ConversationBus.php` |
| Create | `app/Services/TurnStoppedException.php` |
| Create | `routes/channels.php` |
| Modify | `app/Services/OpenRouterClient.php` — `streamChatWithTools()` refactor |
| Modify | `app/Services/Writer/BaseAgent.php` — bus property, lifecycle events |
| Modify | `app/Services/Writer/Agents/ResearchAgent.php` — agentTitle/agentColor |
| Modify | `app/Services/Writer/Agents/AudiencePickerAgent.php` — agentTitle/agentColor |
| Modify | `app/Services/Writer/Agents/EditorAgent.php` — agentTitle/agentColor |
| Modify | `app/Services/Writer/Agents/StyleReferenceAgent.php` — agentTitle/agentColor |
| Modify | `app/Services/Writer/Agents/WriterAgent.php` — agentTitle/agentColor |
| Modify | `app/Services/Writer/Agents/ProofreadAgent.php` — agentTitle/agentColor |
| Modify | `app/Services/ResearchTopicToolHandler.php` — pass bus |
| Modify | `app/Services/PickAudienceToolHandler.php` — pass bus |
| Modify | `app/Services/CreateOutlineToolHandler.php` — pass bus |
| Modify | `app/Services/FetchStyleReferenceToolHandler.php` — pass bus |
| Modify | `app/Services/WriteBlogPostToolHandler.php` — pass bus |
| Modify | `app/Services/ProofreadBlogPostToolHandler.php` — pass bus |
| Modify | `resources/views/pages/teams/⚡create-chat.blade.php` — gut ask(), new stop(), new persistTurn(), Alpine Echo component, history rendering |
| Modify | `resources/js/bootstrap.js` — Laravel Echo / Reverb config |
| Modify | `config/broadcasting.php` — set driver to reverb (created by artisan) |
| Delete | `app/Services/SubagentStoppedException.php` |
| Delete | `app/Services/ToolEvent.php` |
| Delete | `app/Services/ReasoningChunk.php` |

---

## Task 1: Create Feature Branch + Install Reverb

**Files:** none

- [ ] **Step 1: Create the feature branch off current branch**

```bash
git checkout -b feature/reverb-event-bus
```

- [ ] **Step 2: Install Laravel Reverb**

```bash
./vendor/bin/sail composer require laravel/reverb
```

Expected: Reverb added to composer.json.

- [ ] **Step 3: Run the broadcasting installer**

```bash
./vendor/bin/sail artisan install:broadcasting
```

When prompted "Would you like to install Laravel Reverb?", choose **yes**.
When prompted about installing npm packages, choose **yes**.

Expected output includes: published config files, `config/broadcasting.php` created, `routes/channels.php` created, `resources/js/bootstrap.js` updated, npm packages added to `package.json`.

- [ ] **Step 4: Install npm packages**

```bash
npm install
```

Expected: `laravel-echo` and `pusher-js` added to `node_modules`.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json config/broadcasting.php routes/channels.php resources/js/bootstrap.js
git commit -m "chore: install Laravel Reverb and Echo"
```

---

## Task 2: Core Event Infrastructure

**Files:**
- Create: `app/Events/ConversationEvent.php`
- Create: `app/Services/ConversationBus.php`
- Create: `app/Services/TurnStoppedException.php`
- Modify: `routes/channels.php`
- Create: `tests/Unit/Services/ConversationBusTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/ConversationBusTest.php`:

```php
<?php

use App\Services\ConversationBus;
use App\Services\TurnStoppedException;
use Illuminate\Support\Facades\Cache;

test('ConversationBus accumulates text from text_chunk events', function () {
    $bus = new class(99) extends ConversationBus {
        protected function doBroadcast(string $type, array $payload): void {}
    };

    $bus->publish('text_chunk', ['content' => 'Hello ']);
    $bus->publish('text_chunk', ['content' => 'world']);

    expect($bus->text())->toBe('Hello world');
    expect($bus->events())->toHaveCount(0); // text_chunks not stored in events[]
});

test('ConversationBus stores non-text events', function () {
    $bus = new class(99) extends ConversationBus {
        protected function doBroadcast(string $type, array $payload): void {}
    };

    $bus->publish('subagent_started', ['agent' => 'research_topic', 'title' => 'Research', 'color' => 'purple']);
    $bus->publish('subagent_completed', ['agent' => 'research_topic', 'card' => ['kind' => 'research']]);

    expect($bus->events())->toHaveCount(2);
    expect($bus->events()[0]['type'])->toBe('subagent_started');
});

test('ConversationBus throws TurnStoppedException when stop flag set', function () {
    Cache::put('conv-stop:42', true, 60);

    $bus = new class(42) extends ConversationBus {
        protected function doBroadcast(string $type, array $payload): void {}
    };

    expect(fn () => $bus->publish('text_chunk', ['content' => 'x']))->toThrow(TurnStoppedException::class);
    expect(Cache::get('conv-stop:42'))->toBeNull(); // flag consumed
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
./vendor/bin/sail artisan test tests/Unit/Services/ConversationBusTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create TurnStoppedException**

Create `app/Services/TurnStoppedException.php`:

```php
<?php

namespace App\Services;

class TurnStoppedException extends \RuntimeException {}
```

- [ ] **Step 4: Create ConversationEvent**

Create `app/Events/ConversationEvent.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ConversationEvent implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int $conversationId,
        public readonly string $type,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("conversation.{$this->conversationId}");
    }

    public function broadcastAs(): string
    {
        return 'ConversationEvent';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'payload' => $this->payload];
    }
}
```

- [ ] **Step 5: Create ConversationBus**

Create `app/Services/ConversationBus.php`:

```php
<?php

namespace App\Services;

use App\Events\ConversationEvent;
use Illuminate\Support\Facades\Cache;

class ConversationBus
{
    private array $events = [];
    private string $text = '';

    public function __construct(private int $conversationId) {}

    public function publish(string $type, array $payload = []): void
    {
        if (Cache::pull("conv-stop:{$this->conversationId}")) {
            throw new TurnStoppedException();
        }

        $this->doBroadcast($type, $payload);

        if ($type === 'text_chunk') {
            $this->text .= $payload['content'];
        } else {
            $this->events[] = compact('type', 'payload');
        }
    }

    protected function doBroadcast(string $type, array $payload): void
    {
        broadcast(new ConversationEvent($this->conversationId, $type, $payload));
    }

    public function events(): array
    {
        return $this->events;
    }

    public function text(): string
    {
        return $this->text;
    }
}
```

- [ ] **Step 6: Add channel auth to routes/channels.php**

The file was created by `install:broadcasting`. Open it and add the conversation channel auth. The file will have some default content — replace it entirely:

```php
<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conv = Conversation::find($id);
    if (! $conv) return false;
    // Team owner
    if ($conv->team->user_id === $user->id) return true;
    // Team member via team_members pivot
    return $conv->team->members()->where('user_id', $user->id)->exists();
});
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
./vendor/bin/sail artisan test tests/Unit/Services/ConversationBusTest.php
```

Expected: 3 PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Events/ConversationEvent.php app/Services/ConversationBus.php app/Services/TurnStoppedException.php routes/channels.php tests/Unit/Services/ConversationBusTest.php
git commit -m "feat: add ConversationBus, ConversationEvent, TurnStoppedException, channel auth"
```

---

## Task 3: streamChatWithTools() Refactor — Generator → Plain Method

**Files:**
- Modify: `app/Services/OpenRouterClient.php`

The method currently has:
- 4x `yield new ToolEvent(...)` calls (2 in the non-streaming path, 2 in the streaming path) — **remove all**
- `yield new ReasoningChunk(...)` in the streaming path — **remove** (reasoning already accumulates in `$totalReasoningContent`)
- Multiple `yield $content` and `yield $fullContent` — **replace** with `$bus->publish('text_chunk', ...)`
- Final `yield new StreamResult(...)` — **replace** with `return new StreamResult(...)`

- [ ] **Step 1: Update the method signature**

In `app/Services/OpenRouterClient.php`, find the `streamChatWithTools()` method at line 269. Change:

```php
// Before:
public function streamChatWithTools(
    string $systemPrompt,
    array $messages,
    array $tools = [],
    ?callable $toolExecutor = null,
    float $temperature = 0.7,
    bool $useServerTools = true,
): \Generator {
```

To:

```php
// After:
public function streamChatWithTools(
    string $systemPrompt,
    array $messages,
    array $tools = [],
    ?callable $toolExecutor = null,
    float $temperature = 0.7,
    bool $useServerTools = true,
    ?\App\Services\ConversationBus $bus = null,
): \App\Services\StreamResult {
```

Also update the docblock above the method (line 263-268):

```php
/**
 * Stream a chat completion with tool support.
 * Publishes text_chunk events to the bus as they arrive.
 * Returns a StreamResult with token counts and final content.
 */
```

- [ ] **Step 2: Remove ToolEvent yields and add bus publish in non-streaming path**

Find the non-streaming path (around line 360). Remove the two `yield new ToolEvent(...)` lines and replace `yield $content` with a bus publish.

The non-streaming `foreach ($choice['tool_calls'])` section currently has:

```php
yield new ToolEvent($fnName, $fnArgs, null, 'started');

// ... tool execution ...

yield new ToolEvent($fnName, $fnArgs, $toolResult, 'completed');
```

Delete both `yield new ToolEvent(...)` lines entirely.

Also in the non-streaming final content yield (around line 388-391):

```php
// Before:
if ($content !== '') {
    $fullContent .= $content;
    yield $content;
}
```

```php
// After:
if ($content !== '') {
    $fullContent .= $content;
    $bus?->publish('text_chunk', ['content' => $content]);
}
```

- [ ] **Step 3: Remove ReasoningChunk yield and ToolEvent yields in streaming path**

Find the streaming SSE path (around line 427-430). Remove:

```php
yield new ReasoningChunk($delta['reasoning_content']);
```

Replace the content yield (around line 437):

```php
// Before:
yield $content;
```

```php
// After:
$bus?->publish('text_chunk', ['content' => $content]);
```

In the streaming tool-calls dispatch section (around line 502, 517), remove both:

```php
yield new ToolEvent($fnName, $fnArgs, null, 'started');
```

and:

```php
yield new ToolEvent($fnName, $fnArgs, $toolResult, 'completed');
```

- [ ] **Step 4: Replace final yield with return**

At the very end of the method (around line 532):

```php
// Before:
yield new StreamResult(
    content: $fullContent,
    // ...
);
```

```php
// After:
return new StreamResult(
    content: $fullContent,
    inputTokens: $totalInputTokens,
    outputTokens: $totalOutputTokens,
    cost: $totalCost,
    webSearchRequests: $webSearchRequests,
    reasoningTokens: $totalReasoningTokens,
    cacheReadTokens: $totalCacheReadTokens,
    cacheWriteTokens: $totalCacheWriteTokens,
    reasoningContent: $totalReasoningContent,
);
```

- [ ] **Step 5: Verify no remaining yields or ToolEvent/ReasoningChunk references in streamChatWithTools**

```bash
grep -n "yield\|ToolEvent\|ReasoningChunk" /home/zanfridau/Code/AI/AI/marketminded/app/Services/OpenRouterClient.php
```

Expected: zero matches inside the `streamChatWithTools()` method body. (The `use` imports at the top may still reference them — that's fine for now, they'll be cleaned up in Task 10.)

- [ ] **Step 6: Run existing tests to verify nothing broken**

```bash
./vendor/bin/sail artisan test tests/Unit/Services/Writer/
```

Expected: all pass (agent tests use the `chat()` method via `llmCall()`, not `streamChatWithTools()`).

- [ ] **Step 7: Commit**

```bash
git add app/Services/OpenRouterClient.php
git commit -m "refactor(openrouter): streamChatWithTools becomes plain method, publishes to bus"
```

---

## Task 4: BaseAgent Bus Integration + agentTitle/agentColor

**Files:**
- Modify: `app/Services/Writer/BaseAgent.php`
- Modify: `app/Services/Writer/Agents/ResearchAgent.php`
- Modify: `app/Services/Writer/Agents/AudiencePickerAgent.php`
- Modify: `app/Services/Writer/Agents/EditorAgent.php`
- Modify: `app/Services/Writer/Agents/StyleReferenceAgent.php`
- Modify: `app/Services/Writer/Agents/WriterAgent.php`
- Modify: `app/Services/Writer/Agents/ProofreadAgent.php`

- [ ] **Step 1: Add new abstract methods and bus property to BaseAgent**

In `app/Services/Writer/BaseAgent.php`, add the following after the existing abstract methods (after `abstract protected function buildSummary(...)`):

```php
/** Human-readable display title shown in the working card (e.g., "Research sub-agent"). */
abstract protected function agentTitle(): string;

/** Tailwind colour name for the working card badge (e.g., "purple", "blue"). */
abstract protected function agentColor(): string;
```

Add the `bus` property after `public ?int $conversationId = null`:

```php
/** Set by tool handlers before execute() so lifecycle events can be published. */
public ?\App\Services\ConversationBus $bus = null;
```

Add the required use statement at the top of the file (after existing uses):

```php
use App\Services\ConversationBus;
use App\Services\TurnStoppedException;
```

- [ ] **Step 2: Update execute() — add subagent_started publish, update $onToolCall**

In `execute()` in `BaseAgent.php`, add after the SubagentLogger::write 'start' call (around line 114, after the SubagentLogger block):

```php
$this->bus?->publish('subagent_started', [
    'agent' => $submitToolName,
    'title' => $this->agentTitle(),
    'color' => $this->agentColor(),
]);
```

Replace the entire `$onToolCall` closure (currently lines 140-150):

```php
// Before:
$this->lastIntermediateTools = [];
$conversationId = $this->conversationId;
if ($conversationId !== null) {
    Cache::put("subagent-active:{$conversationId}", $callId, 1800);
}

$onToolCall = function (string $name, array $args) use ($callId, $conversationId): void {
    if ($conversationId !== null && Cache::get("streaming-stop:{$conversationId}")) {
        throw new SubagentStoppedException('Stopped by user.');
    }
    $entry = ['name' => $name, 'args' => $args, 'ts' => time()];
    $this->lastIntermediateTools[] = $entry;
    if ($conversationId !== null) {
        $key = "subagent-tools:{$callId}:{$conversationId}";
        Cache::put($key, $this->lastIntermediateTools, 1800);
    }
};
```

```php
// After:
$onToolCall = function (string $name, array $args) use ($submitToolName): void {
    if ($this->conversationId !== null && Cache::get("conv-stop:{$this->conversationId}")) {
        throw new TurnStoppedException('Stopped by user.');
    }
    $this->bus?->publish('subagent_tool_call', [
        'agent' => $submitToolName,
        'name'  => $name,
        'args'  => $args,
    ]);
};
```

- [ ] **Step 3: Remove cache cleanup after llmCall**

Remove the `Cache::forget` call that currently appears after `$this->llmCall(...)` (around line 166-168):

```php
// Remove this block:
if ($conversationId !== null) {
    Cache::forget("subagent-active:{$conversationId}");
}
```

- [ ] **Step 4: Update the stopped check + publish lifecycle events after llmCall**

After the SubagentLogger::write 'end' block (around line 203), the existing code checks `$payload === null` and returns `AgentResult::error`. Keep this check but update the stopped condition. Change the entire block below SubagentLogger 'end' to:

```php
if ($payload === null) {
    if ($this->lastTransportError === 'stopped') {
        $this->bus?->publish('subagent_error', [
            'agent'   => $submitToolName,
            'message' => 'Stopped.',
        ]);
        return AgentResult::error('Stopped.');
    }
    $hint = $this->lastTextResponse !== null
        ? ' Model said: "' . mb_substr($this->lastTextResponse, 0, 300) . '"'
        : '';
    $this->bus?->publish('subagent_error', [
        'agent'   => $submitToolName,
        'message' => "Sub-agent ({$submitToolName}) did not call the submit tool.{$hint}",
    ]);
    return AgentResult::error("Sub-agent ({$submitToolName}) did not call the submit tool.{$hint}");
}

if ($validationError !== null) {
    $this->bus?->publish('subagent_error', [
        'agent'   => $submitToolName,
        'message' => $validationError,
    ]);
    return AgentResult::error($validationError);
}
```

After building `$card` (after the `$card['reasoning'] = ...` block, before `return AgentResult::ok(...)`), add:

```php
$this->bus?->publish('subagent_completed', [
    'agent' => $submitToolName,
    'card'  => $card,
]);
```

- [ ] **Step 5: Remove lastIntermediateTools property and cache-related use statements**

Remove the property declaration (around line 256):

```php
// Remove:
protected array $lastIntermediateTools = [];
```

Remove from execute() anywhere `$this->lastIntermediateTools` is referenced:

```php
// Remove:
if (! empty($this->lastIntermediateTools)) {
    $card['intermediate_tools'] = $this->lastIntermediateTools;
}
```

Remove the `use App\Services\SubagentStoppedException;` import line from the top of BaseAgent.

- [ ] **Step 6: Update llmCall catch block to catch TurnStoppedException**

In `llmCall()`, find the `catch (SubagentStoppedException)` block and change it to:

```php
// Before:
} catch (SubagentStoppedException) {
```

```php
// After:
} catch (TurnStoppedException) {
```

- [ ] **Step 7: Add agentTitle/agentColor to all 6 agents**

**ResearchAgent** (`app/Services/Writer/Agents/ResearchAgent.php`) — add at end of class before closing `}`:

```php
protected function agentTitle(): string { return 'Research sub-agent'; }
protected function agentColor(): string { return 'purple'; }
```

**AudiencePickerAgent** (`app/Services/Writer/Agents/AudiencePickerAgent.php`):

```php
protected function agentTitle(): string { return 'Audience sub-agent'; }
protected function agentColor(): string { return 'amber'; }
```

**EditorAgent** (`app/Services/Writer/Agents/EditorAgent.php`):

```php
protected function agentTitle(): string { return 'Editor sub-agent'; }
protected function agentColor(): string { return 'blue'; }
```

**StyleReferenceAgent** (`app/Services/Writer/Agents/StyleReferenceAgent.php`):

```php
protected function agentTitle(): string { return 'Style sub-agent'; }
protected function agentColor(): string { return 'violet'; }
```

**WriterAgent** (`app/Services/Writer/Agents/WriterAgent.php`):

```php
protected function agentTitle(): string { return 'Writer sub-agent'; }
protected function agentColor(): string { return 'green'; }
```

**ProofreadAgent** (`app/Services/Writer/Agents/ProofreadAgent.php`):

```php
protected function agentTitle(): string { return 'Proofread sub-agent'; }
protected function agentColor(): string { return 'green'; }
```

- [ ] **Step 8: Run existing agent tests**

```bash
./vendor/bin/sail artisan test tests/Unit/Services/Writer/
```

Expected: all pass (the stubbed agents in tests don't use the bus so its absence doesn't matter; abstract methods now require implementations but tests use concrete stubs that already implement everything).

If tests fail because `agentTitle`/`agentColor` are now abstract but not implemented in test stubs, add them to each `Stubbed*Agent` in the test files:

```php
protected function agentTitle(): string { return 'Test'; }
protected function agentColor(): string { return 'zinc'; }
```

- [ ] **Step 8b: Verify no stale references to removed BaseAgent state**

```bash
grep -rn "lastIntermediateTools\|subagent-active:\|subagent-tools:\|streaming-stop:\|stopAndWait\|pollSubagentTools\|SubagentStoppedException" \
    app/ resources/ tests/ --include="*.php" --include="*.blade.php"
```

Expected: zero results. If any appear, trace and remove them before committing.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Writer/BaseAgent.php \
        app/Services/Writer/Agents/ResearchAgent.php \
        app/Services/Writer/Agents/AudiencePickerAgent.php \
        app/Services/Writer/Agents/EditorAgent.php \
        app/Services/Writer/Agents/StyleReferenceAgent.php \
        app/Services/Writer/Agents/WriterAgent.php \
        app/Services/Writer/Agents/ProofreadAgent.php \
        tests/Unit/Services/Writer/Agents/
git commit -m "feat(agents): integrate ConversationBus, publish lifecycle events, add agentTitle/agentColor"
```

---

## Task 5: Tool Handlers — Pass Bus to Agents

**Files:**
- Modify: `app/Services/ResearchTopicToolHandler.php`
- Modify: `app/Services/PickAudienceToolHandler.php`
- Modify: `app/Services/CreateOutlineToolHandler.php`
- Modify: `app/Services/FetchStyleReferenceToolHandler.php`
- Modify: `app/Services/WriteBlogPostToolHandler.php`
- Modify: `app/Services/ProofreadBlogPostToolHandler.php`

Each handler needs:
1. Add `use App\Services\ConversationBus;` import
2. Add `?ConversationBus $bus = null` parameter at the end of `execute()`
3. Set `$agent->bus = $bus` after creating the agent

- [ ] **Step 1: Update ResearchTopicToolHandler**

In `app/Services/ResearchTopicToolHandler.php`:

Add import at top: `use App\Services\ConversationBus;`

Change the `execute()` signature:
```php
// Before:
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
```
```php
// After:
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
```

After `$agent->conversationId = $conversationId;`, add:
```php
$agent->bus = $bus;
```

- [ ] **Step 2: Update PickAudienceToolHandler**

Same pattern in `app/Services/PickAudienceToolHandler.php`:

Add `use App\Services\ConversationBus;`

```php
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
```

After `$agent->conversationId = $conversationId;`:
```php
$agent->bus = $bus;
```

- [ ] **Step 3: Update CreateOutlineToolHandler**

Same pattern in `app/Services/CreateOutlineToolHandler.php`:

Add `use App\Services\ConversationBus;`

```php
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
```

After `$agent->conversationId = $conversationId;`:
```php
$agent->bus = $bus;
```

- [ ] **Step 4: Update FetchStyleReferenceToolHandler**

Same pattern in `app/Services/FetchStyleReferenceToolHandler.php`:

Add `use App\Services\ConversationBus;`

```php
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
```

After `$agent->conversationId = $conversationId;`:
```php
$agent->bus = $bus;
```

- [ ] **Step 5: Update WriteBlogPostToolHandler**

Same pattern in `app/Services/WriteBlogPostToolHandler.php`:

Add `use App\Services\ConversationBus;`

```php
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
```

After `$agent->conversationId = $conversationId;`:
```php
$agent->bus = $bus;
```

- [ ] **Step 6: Update ProofreadBlogPostToolHandler**

Same pattern in `app/Services/ProofreadBlogPostToolHandler.php`:

Add `use App\Services\ConversationBus;`

```php
public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
```

After `$agent->conversationId = $conversationId;`:
```php
$agent->bus = $bus;
```

- [ ] **Step 7: Run tests**

```bash
./vendor/bin/sail artisan test tests/Unit/
```

Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add app/Services/ResearchTopicToolHandler.php \
        app/Services/PickAudienceToolHandler.php \
        app/Services/CreateOutlineToolHandler.php \
        app/Services/FetchStyleReferenceToolHandler.php \
        app/Services/WriteBlogPostToolHandler.php \
        app/Services/ProofreadBlogPostToolHandler.php
git commit -m "feat(handlers): pass ConversationBus to agent execute() so lifecycle events are published"
```

---

## Task 6: Gut ask() + Add persistTurn() + stop()

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php`

This is the largest task. We replace the entire `ask()` method body and several related methods.

- [ ] **Step 1: Replace stopAndWait() with stop()**

Find `public function stopAndWait(): void` (around line 162) and replace the entire method with:

```php
public function stop(): void
{
    if (! $this->conversation) {
        $this->isStreaming = false;
        return;
    }

    \Illuminate\Support\Facades\Cache::put('conv-stop:' . $this->conversation->id, true, 60);
}
```

- [ ] **Step 2: Add persistTurn() method**

After the `stop()` method, add:

```php
private function persistTurn(
    \App\Services\ConversationBus $bus,
    ?\App\Services\StreamResult $streamResult,
    bool $interrupted,
): void {
    $metadata = [];

    $events = $bus->events();
    if (! empty($events)) {
        $metadata['events'] = $events;
    }

    if ($streamResult && $streamResult->webSearchRequests > 0) {
        $metadata['web_searches'] = $streamResult->webSearchRequests;
    }

    $reasoning = $streamResult?->reasoningContent ?: '';
    if ($reasoning !== '') {
        $metadata['reasoning'] = $reasoning;
    }

    if ($streamResult && $streamResult->reasoningTokens > 0) {
        $metadata['reasoning_tokens'] = $streamResult->reasoningTokens;
    }

    if ($interrupted) {
        $metadata['interrupted'] = true;
    }

    // cleanContent() is called once on the full accumulated string (not per-chunk).
    // Verify it handles multi-chunk input correctly before shipping — run it against
    // a sample multi-chunk string in tinker: \App\Livewire\...\cleanContent("chunk1\nchunk2").
    $content = $this->cleanContent($bus->text());

    if ($content === '' && empty($metadata)) {
        return;
    }

    $message = Message::create([
        'conversation_id' => $this->conversation->id,
        'role'            => 'assistant',
        'content'         => $content,
        'model'           => $this->teamModel->fast_model,
        'input_tokens'    => $streamResult?->inputTokens ?? 0,
        'output_tokens'   => $streamResult?->outputTokens ?? 0,
        'cost'            => $streamResult?->cost ?? 0,
        'metadata'        => ! empty($metadata) ? $metadata : null,
    ]);

    if ($message) {
        $this->messages[] = [
            'role'         => 'assistant',
            'content'      => $message->content,
            'metadata'     => $message->metadata,
            'input_tokens' => $message->input_tokens,
            'output_tokens'=> $message->output_tokens,
            'cost'         => (float) $message->cost,
        ];
    }
}
```

- [ ] **Step 3: Make loadMessages() public**

Find `private function loadMessages(): void` (around line 664) and change `private` to `public`:

```php
public function loadMessages(): void
```

- [ ] **Step 4: Replace the ask() method body**

Find `public function ask(): void` and replace everything from the opening brace to the closing brace with:

```php
public function ask(): void
{
    set_time_limit(900);
    ignore_user_abort(true);

    $type = $this->conversation->type;
    $this->teamModel->refresh();
    $this->conversation->load('topic');

    if ($type === 'writer' && $this->conversation->topic && empty(($this->conversation->brief ?? [])['topic'])) {
        $topic = $this->conversation->topic;
        $brief = $this->conversation->brief ?? [];
        $brief['topic'] = [
            'id'      => $topic->id,
            'title'   => $topic->title,
            'angle'   => $topic->angle,
            'sources' => $topic->sources ?? [],
        ];
        $this->conversation->update(['brief' => $brief]);
        $this->conversation->refresh();
    }

    $systemPrompt = ChatPromptBuilder::build($type, $this->teamModel, $this->conversation);
    $tools        = ChatPromptBuilder::tools($type);

    $apiMessages = collect($this->messages)
        ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
        ->toArray();

    $webSearchProvider = $this->teamModel->web_search_provider ?? 'openrouter_builtin';
    $braveClient = ($webSearchProvider === 'brave' && $this->teamModel->brave_api_key)
        ? new BraveSearchClient($this->teamModel->brave_api_key)
        : null;

    $client = new OpenRouterClient(
        apiKey: $this->teamModel->ai_api_key,
        model: $this->teamModel->fast_model,
        urlFetcher: new UrlFetcher,
        maxIterations: 8,
        baseUrl: $this->teamModel->ai_api_url ?? 'https://openrouter.ai/api/v1',
        provider: $this->teamModel->ai_provider ?? 'openrouter',
        braveSearchClient: $braveClient,
    );

    $brandHandler    = new BrandIntelligenceToolHandler;
    $topicHandler    = new TopicToolHandler;
    $researchHandler = new ResearchTopicToolHandler;
    $audienceHandler = new PickAudienceToolHandler;
    $outlineHandler  = new CreateOutlineToolHandler;
    $styleRefHandler = new FetchStyleReferenceToolHandler;
    $writeHandler    = new WriteBlogPostToolHandler;
    $proofreadHandler= new ProofreadBlogPostToolHandler;
    $socialHandler   = new SocialPostToolHandler;
    $team            = $this->teamModel;
    $conversation    = $this->conversation;

    $priorTurnTools = [];

    $bus = new \App\Services\ConversationBus($this->conversation->id);
    session()->save();

    $toolExecutor = function (string $name, array $args) use (
        $brandHandler, $topicHandler, $researchHandler, $audienceHandler, $outlineHandler,
        $styleRefHandler, $writeHandler, $proofreadHandler, $socialHandler,
        $team, $conversation, $bus, &$priorTurnTools
    ): string {
        if ($name === 'update_brand_intelligence') {
            return $brandHandler->execute($team, $args);
        }
        if ($name === 'save_topics') {
            return $topicHandler->execute($team, $conversation->id, $args);
        }
        if ($name === 'fetch_url') {
            return (new UrlFetcher)->fetch($args['url'] ?? '');
        }
        if ($name === 'research_topic') {
            $result = $researchHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
            $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
            return $result;
        }
        if ($name === 'pick_audience') {
            $result = $audienceHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
            $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
            return $result;
        }
        if ($name === 'create_outline') {
            $result = $outlineHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
            $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
            return $result;
        }
        if ($name === 'fetch_style_reference') {
            $result = $styleRefHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
            $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
            return $result;
        }
        if ($name === 'write_blog_post') {
            $result = $writeHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
            $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
            return $result;
        }
        if ($name === 'proofread_blog_post') {
            $result = $proofreadHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
            $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
            return $result;
        }
        if ($name === 'propose_posts') {
            $piece = $conversation->contentPiece;
            if (! $piece) {
                return json_encode(['status' => 'error', 'message' => 'No content piece is associated with this conversation.']);
            }
            return $socialHandler->propose($team, $conversation->id, $piece, $args);
        }
        if ($name === 'update_post') {
            return $socialHandler->update($team, $conversation->id, $args);
        }
        if ($name === 'delete_post') {
            return $socialHandler->delete($team, $args);
        }
        if ($name === 'replace_all_posts') {
            $piece = $conversation->contentPiece;
            if (! $piece) {
                return json_encode(['status' => 'error', 'message' => 'No content piece is associated with this conversation.']);
            }
            return $socialHandler->replaceAll($team, $conversation->id, $piece, $args);
        }
        return "Unknown tool: {$name}";
    };

    $useServerTools = $this->teamModel->ai_provider !== 'custom'
        && $webSearchProvider === 'openrouter_builtin';
    $chatTools = $tools;
    if ($braveClient !== null) {
        $chatTools[] = BraveSearchClient::toolSchema();
    }

    try {
        $streamResult = $client->streamChatWithTools(
            systemPrompt: $systemPrompt,
            messages: $apiMessages,
            tools: $chatTools,
            toolExecutor: $toolExecutor,
            temperature: 0.7,
            useServerTools: $useServerTools,
            bus: $bus,
        );

        $this->writeChatDebugLog($systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), $streamResult, false);
        $this->persistTurn($bus, $streamResult, interrupted: false);
        $bus->publish('turn_complete');

    } catch (\App\Services\TurnStoppedException) {
        $this->writeChatDebugLog($systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), null, true);
        $this->persistTurn($bus, streamResult: null, interrupted: true);
        try {
            $bus->publish('turn_interrupted');
        } catch (\App\Services\TurnStoppedException) {
            // flag already consumed; broadcast manually
            broadcast(new \App\Events\ConversationEvent($this->conversation->id, 'turn_interrupted', []));
        }

    } catch (\Throwable $e) {
        $this->writeChatDebugLog($systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), null, true);
        $this->persistTurn($bus, streamResult: null, interrupted: true);
        \Log::error('ask() failed', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        try {
            $bus->publish('turn_error', ['message' => $e->getMessage()]);
        } catch (\App\Services\TurnStoppedException) {
            broadcast(new \App\Events\ConversationEvent($this->conversation->id, 'turn_error', ['message' => $e->getMessage()]));
        }
    }

    $this->loadMessages();
    $this->isStreaming = false;
}
```

- [ ] **Step 5: Update writeChatDebugLog() signature to accept bus data**

The old `writeChatDebugLog()` references `ToolEvent` objects. Update it to accept the bus text + events array instead. The call is retained in ask() (added in Step 4 above). Find `writeChatDebugLog()` and replace its signature and body:

```php
private function writeChatDebugLog(
    string $systemPrompt,
    array $tools,
    array $apiMessages,
    string $responseContent,
    array $busEvents,
    ?\App\Services\StreamResult $streamResult,
    bool $interrupted,
): void {
    $entry = [
        'ts'               => now()->toIso8601String(),
        'conversation_id'  => $this->conversation->id,
        'type'             => $this->conversation->type,
        'topic_id'         => $this->conversation->topic_id,
        'team_id'          => $this->teamModel->id,
        'model'            => $this->teamModel->fast_model,
        'system_prompt'    => $systemPrompt,
        'tool_schemas'     => array_map(fn ($t) => $t['function']['name'] ?? ($t['type'] ?? 'unknown'), $tools),
        'history_sent'     => $apiMessages,
        'response_content' => $responseContent,
        'bus_events'       => array_map(fn ($e) => ['type' => $e['type'], 'agent' => $e['payload']['agent'] ?? null], $busEvents),
        'input_tokens'     => $streamResult?->inputTokens ?? 0,
        'output_tokens'    => $streamResult?->outputTokens ?? 0,
        'cost'             => (float) ($streamResult?->cost ?? 0),
        'web_searches'     => (int) ($streamResult?->webSearchRequests ?? 0),
        'interrupted'      => $interrupted,
    ];

    try {
        $path = storage_path('logs/chat-debug.log');
        file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    } catch (\Throwable $e) {
        \Log::warning('chat-debug log write failed', ['error' => $e->getMessage()]);
    }
}
```

The `writeChatDebugLog()` call is already wired in Step 4's ask() body — all required vars (`$systemPrompt`, `$chatTools`, `$apiMessages`, `$bus`) are in scope throughout the try/catch.

- [ ] **Step 6: Remove all methods that are no longer needed**

Delete these private methods from the blade file's PHP class (they are all replaced by the Alpine frontend or the new persistTurn/bus model):

- `streamUI()` — entire method
- `activeSubAgentCard()` — entire method
- `intermediateToolsPills()` — entire method
- `pollSubagentTools()` — entire method (was `public`)
- `persistAssistantMessage()` — entire static method (replaced by `persistTurn()`)
- `toolPill()` — entire method (no longer used by streamUI)
- `savedTopicCards()` — entire method (moved to blade template)
- `contentPieceCards()` — entire method (moved to blade template)
- `socialPostCards()` — entire method (moved to blade template)
- `renderResearchCard()` — entire method
- `renderOutlineCard()` — entire method
- `renderAudienceCard()` — entire method
- `renderStyleReferenceCard()` — entire method
- `renderSkippedCard()` — entire method (keep only for history rendering below)
- `renderContentPieceCard()` — entire method
- `cardMetricsFooter()` — entire method (moved inline to blade)

**Keep:** `ask()`, `stop()`, `persistTurn()`, `loadMessages()`, `cleanContent()`, `getConversationStatsProperty()`, `submitPrompt()`, `mount()`, `render()`, and all the `select*()` methods.

Note: `renderSkippedCard()` is used in the history Blade rendering (@php blocks). Either keep it or inline the HTML directly in the Blade template. For safety, keep it as-is.

- [ ] **Step 7: Remove imports no longer needed from the top of the blade file**

At the top of the blade file (lines 1-24), remove:

```php
use App\Services\ReasoningChunk;
use App\Services\ToolEvent;
```

Keep all other imports including `use App\Services\StreamResult;` (still needed by persistTurn's type hint).

Actually add the new imports needed:

```php
use App\Services\ConversationBus;
```

- [ ] **Step 8: Run tests**

```bash
./vendor/bin/sail artisan test tests/Unit/
```

Expected: all pass.

- [ ] **Step 9: Commit**

```bash
git add "resources/views/pages/teams/⚡create-chat.blade.php"
git commit -m "feat(chat): gut ask() loop, add persistTurn(), stop() — bus-driven orchestration"
```

---

## Task 7: Frontend Alpine + Echo Streaming Component

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (Blade HTML section, lines 1228–1236)

Replace the current streaming area and update the stop button. Add a `<script>` block at the bottom.

- [ ] **Step 1: Replace the streaming area in the Blade HTML**

Find the streaming area (around line 1228):

```blade
{{-- Streaming response --}}
@if ($isStreaming)
    <div class="mb-6">
        <div class="mb-1.5 flex items-center gap-2">
            <flux:badge variant="pill" color="indigo" size="sm">AI</flux:badge>
            <flux:icon.loading class="size-3.5 text-zinc-500" />
        </div>
        <div class="text-sm" wire:stream="streamed-response"><span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span></div>
    </div>
@endif
```

Replace with:

```blade
{{-- Streaming response (Echo/Alpine) --}}
@if ($isStreaming)
    <div class="mb-6"
        x-data="conversationStream({{ $conversation->id }})"
        x-init="init()"
    >
        <div class="mb-1.5 flex items-center gap-2">
            <flux:badge variant="pill" color="indigo" size="sm">AI</flux:badge>
            <flux:icon.loading class="size-3.5 text-zinc-500" x-show="items.length === 0" />
        </div>
        <div class="text-sm">
            <template x-if="items.length === 0">
                <span class="inline-flex items-center gap-1.5 text-zinc-500">
                    <flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}
                </span>
            </template>
            <template x-for="(item, idx) in items" :key="idx">
                <div class="mb-1">
                    {{-- Text item --}}
                    <template x-if="item.type === 'text'">
                        <p class="whitespace-pre-wrap text-sm" x-text="item.content"></p>
                    </template>

                    {{-- Subagent working card --}}
                    <template x-if="item.type === 'subagent' && item.status === 'working'">
                        <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                            <div class="flex items-center gap-2">
                                <flux:icon.loading class="size-3.5" :class="'text-' + item.color + '-400'" />
                                <span class="text-xs font-semibold" :class="'text-' + item.color + '-400'" x-text="item.title"></span>
                                <span class="text-xs text-zinc-500">working…</span>
                            </div>
                            <template x-if="item.pills.length > 0">
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <template x-for="(pill, pi) in item.pills" :key="pi">
                                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs bg-zinc-800 text-zinc-300 border border-zinc-700">
                                            <svg class="size-3 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                                            <span x-text="pill"></span>
                                        </span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Subagent completed card --}}
                    <template x-if="item.type === 'subagent' && item.status === 'done'">
                        <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold" :class="'text-' + item.color + '-400'">&#10003; <span x-text="item.title"></span></span>
                            </div>
                            <template x-if="item.card && item.card.summary">
                                <p class="mt-1 text-xs text-zinc-400" x-text="item.card.summary"></p>
                            </template>
                            <template x-if="item.pills.length > 0">
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <template x-for="(pill, pi) in item.pills" :key="pi">
                                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs bg-zinc-800 text-zinc-400 border border-zinc-700" x-text="pill"></span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Subagent error card --}}
                    <template x-if="item.type === 'subagent' && item.status === 'error'">
                        <div class="mt-2 rounded-lg border border-red-900/50 bg-zinc-900 p-3">
                            <span class="text-xs text-red-400">&#9888; <span x-text="item.title"></span> failed</span>
                            <p class="mt-1 text-xs text-zinc-500" x-text="item.message || ''"></p>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
@endif
```

- [ ] **Step 2: Update the stop button to call stop() instead of stopAndWait()**

Find the stop button (around line 1601):

```blade
x-on:click="
    stopping = true;
    $wire.stopAndWait();">
<span x-show="!stopping">{{ __('Stop generating') }}</span>
<span x-show="stopping" x-cloak>{{ __('Saving partial…') }}</span>
```

Replace with:

```blade
x-on:click="stopping = true; $wire.stop();">
<span x-show="!stopping">{{ __('Stop generating') }}</span>
<span x-show="stopping" x-cloak>{{ __('Stopping…') }}</span>
```

- [ ] **Step 3: Add the Alpine conversationStream function**

Just before the closing `</div>` of the root element (the very last line of the blade file), add:

```blade
@push('scripts')
<script>
function conversationStream(conversationId) {
    return {
        items: [],

        init() {
            if (typeof Echo === 'undefined') return;
            window.Echo
                .private('conversation.' + conversationId)
                .listen('.ConversationEvent', e => this.handle(e));
        },

        handle(e) {
            const p = e.payload;
            switch (e.type) {
                case 'text_chunk': {
                    const last = this.items[this.items.length - 1];
                    if (last && last.type === 'text') {
                        last.content += p.content;
                    } else {
                        this.items.push({ type: 'text', content: p.content });
                    }
                    break;
                }
                case 'subagent_started':
                    this.items.push({
                        type: 'subagent', agent: p.agent,
                        title: p.title, color: p.color,
                        status: 'working', pills: [], card: null, message: null,
                    });
                    break;
                case 'subagent_tool_call': {
                    const sa = this.findLastAgent(p.agent);
                    if (sa) sa.pills.push(p.name.replace(/_/g, ' '));
                    break;
                }
                case 'subagent_completed': {
                    const done = this.findLastAgent(p.agent);
                    if (done) { done.status = 'done'; done.card = p.card; }
                    break;
                }
                case 'subagent_error': {
                    const err = this.findLastAgent(p.agent);
                    if (err) { err.status = 'error'; err.message = p.message; }
                    break;
                }
                case 'turn_complete':
                case 'turn_interrupted':
                case 'turn_error':
                    this.items = [];
                    $wire.loadMessages();
                    break;
            }
        },

        findLastAgent(agent) {
            for (let i = this.items.length - 1; i >= 0; i--) {
                if (this.items[i].type === 'subagent' && this.items[i].agent === agent) {
                    return this.items[i];
                }
            }
            return null;
        },
    };
}
</script>
@endpush
```

Note: the Blade layout must have `@stack('scripts')` before `</body>`. Check `resources/views/layouts/app.blade.php` or similar. If `@stack('scripts')` is not present, add it or use an inline script tag directly in the blade file instead.

If `@push`/`@stack` is not available (anonymous component), replace the `@push('scripts')...@endpush` wrapper with a plain `<script>...</script>` block just before the closing `</div>`.

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/sail artisan test tests/Unit/
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/teams/⚡create-chat.blade.php"
git commit -m "feat(frontend): replace SSE wire:stream with Alpine Echo streaming component"
```

---

## Task 8: History Rendering — metadata.events + Backwards Compat

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (Blade HTML history section)

New messages will have `metadata.events[]`. Old messages have `metadata.tools[]`. Both must render.

- [ ] **Step 1: Add metadata.events history rendering in the Blade template**

Find the history section where assistant messages are rendered (around line 1258). Currently it shows text content and then iterates `$message['metadata']['tools']` multiple times. We need to add an `events`-based renderer.

After the reasoning block and text content `<p>` tag (after line 1258), and before the `@if (!empty($message['metadata']['tools'])...)` block, add:

```blade
{{-- Sub-agent cards from metadata.events (new format) --}}
@if (!empty($message['metadata']['events']))
    @php $seenEventPieceIds = []; @endphp
    @foreach ($message['metadata']['events'] as $event)
        @php
            $etype   = $event['type'] ?? '';
            $epayload = $event['payload'] ?? [];
            $ecard   = $epayload['card'] ?? null;
            $ekind   = $ecard['kind'] ?? null;
        @endphp

        @if ($etype === 'subagent_started')
            {{-- handled implicitly below by subagent_completed --}}
        @elseif ($etype === 'subagent_tool_call')
            {{-- pills shown inside the completed card, no standalone render needed --}}
        @elseif ($etype === 'subagent_completed')
            @php
                $eagent      = $epayload['agent'] ?? '';
                $eMetrics    = $ecard ? $this->cardMetricsFooterFromArray($ecard) : '';
                $ePills      = $this->eventPillsFromHistory($message['metadata']['events'], $eagent);
            @endphp

            @if ($eagent === 'research_topic' && $ekind === 'research')
                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                    <div class="text-xs text-purple-400">&#10003; {{ $ecard['summary'] ?? 'Research complete' }}</div>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach (array_slice($ecard['claims'] ?? [], 0, 5) as $c)
                            <li class="text-xs text-zinc-400">{{ $c['text'] ?? '' }}</li>
                        @endforeach
                    </ul>
                    @if (count($ecard['claims'] ?? []) > 5)
                        <div class="mt-1 text-xs text-zinc-500">…and {{ count($ecard['claims']) - 5 }} more</div>
                    @endif
                    {!! $ePills !!}{!! $eMetrics !!}
                </div>
            @elseif ($eagent === 'pick_audience' && $ekind === 'audience')
                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                    <div class="text-xs text-amber-400">&#10003; {{ $ecard['summary'] ?? 'Audience selected' }}</div>
                    <div class="mt-1 text-xs text-zinc-400">{{ $ecard['guidance_for_writer'] ?? '' }}</div>
                    {!! $ePills !!}{!! $eMetrics !!}
                </div>
            @elseif ($eagent === 'create_outline' && $ekind === 'outline')
                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                    <div class="text-xs text-blue-400">&#10003; {{ $ecard['summary'] ?? 'Outline ready' }}</div>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($ecard['sections'] ?? [] as $s)
                            <li class="text-xs text-zinc-400">{{ $s['heading'] }}</li>
                        @endforeach
                    </ul>
                    {!! $ePills !!}{!! $eMetrics !!}
                </div>
            @elseif ($eagent === 'fetch_style_reference' && $ekind === 'style_reference')
                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                    <div class="text-xs text-violet-400">&#10003; {{ $ecard['summary'] ?? 'Style reference ready' }}</div>
                    <ul class="mt-1 list-none">
                        @foreach ($ecard['examples'] ?? [] as $ex)
                            <li class="text-xs text-zinc-400">· {{ $ex['title'] ?? '' }}</li>
                        @endforeach
                    </ul>
                    {!! $ePills !!}{!! $eMetrics !!}
                </div>
            @elseif (in_array($eagent, ['write_blog_post', 'proofread_blog_post'], true))
                @php
                    $ePieceId = $ecard['piece_id'] ?? null;
                    if ($ePieceId !== null && ! isset($seenEventPieceIds[$ePieceId])) {
                        $seenEventPieceIds[$ePieceId] = true;
                        $ePiece = \App\Models\ContentPiece::where('id', $ePieceId)
                            ->where('team_id', $teamModel->id)->first();
                    } else {
                        $ePiece = null;
                    }
                    $eBadge = $eagent === 'write_blog_post' ? __('Draft created') : __('Revised');
                @endphp
                @if ($ePiece)
                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-green-400">&#10003; {{ $eBadge }} &middot; v{{ $ePiece->current_version }} &middot; {{ number_format(str_word_count(strip_tags($ePiece->body))) }} words</span>
                            <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $ePiece->id]) }}" wire:navigate class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('Open') }} &rarr;</a>
                        </div>
                        <div class="text-sm font-semibold text-zinc-200">{{ $ePiece->title }}</div>
                        <div class="mt-1 text-xs text-zinc-400 line-clamp-3">{{ mb_substr(strip_tags($ePiece->body), 0, 200) }}</div>
                        {!! $ePills !!}{!! $eMetrics !!}
                    </div>
                @endif
            @endif

        @elseif ($etype === 'subagent_error')
            <div class="mt-2 rounded-lg border border-red-900/50 bg-zinc-900 p-3">
                <span class="text-xs text-red-400">&#9888; {{ $epayload['agent'] ?? '' }} failed</span>
                <p class="mt-1 text-xs text-zinc-500">{{ $epayload['message'] ?? '' }}</p>
            </div>
        @endif
    @endforeach
@endif
```

- [ ] **Step 2: Add helper methods to the PHP class**

In the PHP class section of the blade file (before the closing `}; ?>`), add two helper methods:

```php
private function cardMetricsFooterFromArray(array $card): string
{
    $cost         = (float) ($card['cost'] ?? 0);
    $inTok        = (int) ($card['input_tokens'] ?? 0);
    $outTok       = (int) ($card['output_tokens'] ?? 0);
    $reasoningTok = (int) ($card['reasoning_tokens'] ?? 0);
    $reasoning    = (string) ($card['reasoning'] ?? '');

    if ($cost === 0.0 && $inTok === 0 && $outTok === 0 && $reasoning === '') {
        return '';
    }

    $parts = [];
    if ($inTok > 0 || $outTok > 0) {
        $parts[] = number_format($inTok + $outTok) . ' tokens';
    }
    if ($reasoningTok > 0) {
        $parts[] = number_format($reasoningTok) . ' reasoning';
    }
    if ($cost > 0) {
        $parts[] = '$' . number_format($cost, 4);
    }

    $wrapAttr = $reasoning !== '' ? ' x-data="{ open: false }"' : '';
    $html = '<div class="mt-2 border-t border-zinc-700 pt-2 text-xs text-zinc-500"' . $wrapAttr . '>';
    $html .= '<div class="flex items-center justify-between gap-2">';
    $html .= '<span>' . e(implode(' · ', $parts)) . '</span>';
    if ($reasoning !== '') {
        $html .= '<button @click="open = !open" class="inline-flex items-center gap-1 hover:text-zinc-300 transition-colors">reasoning<svg x-bind:class="open ? \'rotate-180\' : \'\'" class="size-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg></button>';
    }
    $html .= '</div>';
    if ($reasoning !== '') {
        $html .= '<div x-show="open" x-cloak class="mt-2 rounded-md border border-zinc-700 bg-zinc-900/50 p-2 text-xs text-zinc-400 whitespace-pre-wrap">' . e($reasoning) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

private function eventPillsFromHistory(array $events, string $agent): string
{
    $pills = '';
    foreach ($events as $e) {
        if (($e['type'] ?? '') === 'subagent_tool_call' && ($e['payload']['agent'] ?? '') === $agent) {
            $name  = $e['payload']['name'] ?? '';
            $label = str_replace('_', ' ', $name);
            $pills .= '<span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs bg-zinc-800 text-zinc-400 border border-zinc-700">'
                . '<svg class="size-3 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>'
                . e($label) . '</span>';
        }
    }
    return $pills !== '' ? '<div class="mt-2 flex flex-wrap gap-1">' . $pills . '</div>' : '';
}
```

- [ ] **Step 3: Verify old tool-based history still renders**

The existing `@foreach ($message['metadata']['tools'] ?? [])` blocks remain untouched — they act as the fallback for old messages. They are now guarded implicitly because new messages use `metadata.events` and won't have `metadata.tools`.

To make this explicit, wrap the existing `@if (!empty($message['metadata']['tools'])...)` block with a check:

```blade
{{-- Legacy tool cards (messages created before the bus rewrite) --}}
@if (empty($message['metadata']['events']) && !empty($message['metadata']['tools']))
```

Change the opening condition from:

```blade
@if (!empty($message['metadata']['tools']) || ($message['input_tokens'] ?? 0) > 0)
```

to:

```blade
@if (empty($message['metadata']['events']) && (!empty($message['metadata']['tools']) || ($message['input_tokens'] ?? 0) > 0))
```

And wrap the multiple `@foreach ($message['metadata']['tools'] ?? [])` sections below with a similar guard so they only run for legacy messages.

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/sail artisan test tests/Unit/
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/teams/⚡create-chat.blade.php"
git commit -m "feat(history): render metadata.events for new messages, keep metadata.tools as legacy fallback"
```

---

## Task 9: Echo Config + Environment Setup

**Files:**
- Modify: `resources/js/bootstrap.js`
- Modify: `.env.example`

- [ ] **Step 1: Check what install:broadcasting generated in bootstrap.js**

```bash
cat resources/js/bootstrap.js
```

The file should already have an Echo/Reverb configuration block added by `install:broadcasting`. It should look like:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

If it's already there, no changes needed. If missing, add it.

- [ ] **Step 2: Add VITE_ vars to .env**

Open `.env` (not `.env.example` — this is your actual running env) and add:

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=marketminded
REVERB_APP_KEY=marketminded-key
REVERB_APP_SECRET=marketminded-secret
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Note: on Forge/production you'll need real values for REVERB_APP_ID/KEY/SECRET and REVERB_HOST must be the public domain. Set REVERB_PORT=443 and REVERB_SCHEME=https for production.

- [ ] **Step 3: Check config/broadcasting.php has reverb driver**

```bash
grep -A5 "'connections'" config/broadcasting.php | head -20
```

The `reverb` connection should already be there from `install:broadcasting`. If `BROADCAST_CONNECTION` isn't set to `reverb`, that's handled by the .env change above.

- [ ] **Step 4: Run npm build**

```bash
npm run build
```

Expected: compiled successfully, `public/build/assets/` updated.

- [ ] **Step 5: Verify Sail exposes port 8080, then start Reverb locally**

Check whether `docker-compose.yml` (or `docker-compose.override.yml`) maps port 8080:

```bash
grep -n "8080" docker-compose.yml 2>/dev/null || echo "no docker-compose.yml found"
```

If no mapping exists, add it to the `laravel.test` service ports section:

```yaml
ports:
    - '${APP_PORT:-80}:80'
    - '${VITE_PORT:-5173}:${VITE_PORT:-5173}'
    - '${REVERB_PORT:-8080}:8080'
```

If there is no `docker-compose.yml` (Sail not in use for this project), run Reverb directly on the host instead:

```bash
php artisan reverb:start &
```

Otherwise start it through Sail:

```bash
./vendor/bin/sail artisan reverb:start &
```

Expected: Reverb starts on port 8080. Leave it running for manual testing.

- [ ] **Step 6: Add .env.example entries**

Open `.env.example` and add (after the existing DB/cache vars):

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

- [ ] **Step 7: Commit**

```bash
git add resources/js/bootstrap.js .env.example config/broadcasting.php
git commit -m "chore: configure Echo/Reverb env vars and build frontend"
```

---

## Task 10: Delete Old Files + Clean Up Imports

**Files:**
- Delete: `app/Services/SubagentStoppedException.php`
- Delete: `app/Services/ToolEvent.php`
- Delete: `app/Services/ReasoningChunk.php`
- Modify: `app/Services/OpenRouterClient.php` (remove stale imports)
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` (remove stale imports)

- [ ] **Step 1: Verify nothing still uses these files**

```bash
grep -rn "SubagentStoppedException\|ToolEvent\|ReasoningChunk" \
    app/ resources/ tests/ --include="*.php" --include="*.blade.php"
```

Expected: zero results. If any appear, trace and fix them before deleting.

If `ToolEvent` still appears in OpenRouterClient imports (it should not appear in the method body after Task 3), remove the `use App\Services\ToolEvent;` line from the top of the file.

If `ReasoningChunk` still appears in OpenRouterClient imports, remove `use App\Services\ReasoningChunk;`.

- [ ] **Step 2: Delete the three files**

```bash
rm app/Services/SubagentStoppedException.php
rm app/Services/ToolEvent.php
rm app/Services/ReasoningChunk.php
```

- [ ] **Step 3: Run the full test suite**

```bash
./vendor/bin/sail artisan test
```

Expected: all pass, no class-not-found errors.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: delete ToolEvent, ReasoningChunk, SubagentStoppedException — replaced by bus events"
```

---

## Task 11: Integration Smoke Test + Production Setup Notes

**Files:** none

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/sail artisan test
```

Expected: all pass.

- [ ] **Step 2: Manual smoke test — start a chat turn**

1. Start Reverb: `./vendor/bin/sail artisan reverb:start`
2. Open the app in a browser at a writer conversation
3. Open browser devtools → Network → WS tab
4. Confirm a WebSocket connection opens to `ws://localhost:8080/app/marketminded-key`
5. Submit a prompt
6. Confirm the streaming area shows "Thinking..." initially
7. Confirm text chunks appear as the model responds
8. If a sub-agent fires, confirm the working card appears with spinner + pills
9. After completion, confirm the full message renders in history

- [ ] **Step 3: Manual smoke test — stop button**

1. Start a writer conversation that will run sub-agents
2. Click "Stop generating" immediately
3. Verify: the button shows "Stopping…", then the partial message appears, and isStreaming goes false promptly

- [ ] **Step 4: Final commit and push**

```bash
git push -u origin feature/reverb-event-bus
```

---

## Production Setup (Forge)

After the branch is merged, these one-time operations are needed on the server:

**Forge Daemon:**  
Add a new daemon: `php artisan reverb:start --host=0.0.0.0 --port=8080`

**Nginx WebSocket Proxy:**  
Add inside the server block:
```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
}
```

**Environment Variables on Forge:**
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<generate>
REVERB_APP_KEY=<generate>
REVERB_APP_SECRET=<generate>
REVERB_HOST=<your-domain.com>
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_APP_KEY=<same as REVERB_APP_KEY>
VITE_REVERB_HOST=<same as REVERB_HOST>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Run `npm run build` in the deploy script after adding these.
