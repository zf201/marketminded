# Reverb Event Bus — Implementation Design

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current SSE-coupled streaming architecture with a single Laravel Reverb WebSocket channel per conversation. Every event — text chunks, sub-agent lifecycle, tool pills, turn complete — flows as a typed event on one bus. Stop works reliably. Frontend renders events in arrival order.

**Architecture:** `ask()` stays a synchronous long-running Livewire method but publishes events via `ConversationBus` instead of yielding through an SSE generator. `streamChatWithTools()` receives the bus and publishes directly. Sub-agents receive the same bus instance and publish their own lifecycle events. Alpine.js + Laravel Echo listen on `private-conversation.{id}` and render an ordered `items[]` array. On turn complete, Livewire reloads the message list from the database.

**Tech stack:** Laravel Reverb, Laravel Echo, Alpine.js, Redis (already live), PHP synchronous execution (no queue jobs).

---

## 1. Event Vocabulary

All events are instances of a single `ConversationEvent` broadcastable class published on `private-conversation.{id}`.

| `type` | `payload` | fired by | when |
|---|---|---|---|
| `text_chunk` | `{ content: string }` | `streamChatWithTools()` | each streamed text fragment from orchestrator LLM |
| `subagent_started` | `{ agent: string, title: string, color: string }` | `BaseAgent::execute()` | sub-agent begins |
| `subagent_tool_call` | `{ agent: string, name: string, args: array }` | `BaseAgent` `$onToolCall` | web_search / fetch_url inside a sub-agent |
| `subagent_completed` | `{ agent: string, card: array }` | `BaseAgent::execute()` | sub-agent succeeded; `card` is the full render payload |
| `subagent_error` | `{ agent: string, message: string }` | `BaseAgent::execute()` | sub-agent failed or was stopped |
| `turn_complete` | `{}` | `ask()` finally block | orchestrator finished, message persisted |
| `turn_interrupted` | `{}` | `ask()` catch block | user stopped; partial message persisted |
| `turn_error` | `{ message: string }` | `ask()` catch block | unhandled exception |

`text_chunk` events are NOT stored in `metadata.events` — they are reconstructed from `messages.content`. All other event types are stored.

---

## 2. ConversationBus

A single lightweight service class. One instance is created at the start of each `ask()` call and passed through the entire execution chain.

```php
class ConversationBus
{
    private array $events = [];
    private string $text = '';

    public function __construct(private int $conversationId) {}

    public function publish(string $type, array $payload = []): void
    {
        // Cache::pull reads and deletes in one operation — first stop check
        // consumes the flag, so the catch-block's publish('turn_interrupted')
        // does not re-throw.
        if (Cache::pull("conv-stop:{$this->conversationId}")) {
            throw new TurnStoppedException();
        }

        broadcast(new ConversationEvent($this->conversationId, $type, $payload));

        if ($type === 'text_chunk') {
            $this->text .= $payload['content']; // accumulated for persistTurn()
        } else {
            $this->events[] = compact('type', 'payload');
        }
    }

    public function events(): array { return $this->events; }
    public function text(): string  { return $this->text; }
}

```

**Stop check is centralised here.** Every `publish()` call checks the flag. There is no other place that needs to check it except `$onToolCall` in `BaseAgent` (which throws `SubagentStoppedException` to abort the sub-agent's inner HTTP loop before control even returns to the bus).

`TurnStoppedException` and `SubagentStoppedException` are both caught in `ask()` — they map to `turn_interrupted`.

---

## 3. ConversationEvent (broadcastable)

```php
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

`ShouldBroadcastNow` broadcasts synchronously (no queue needed). Channel auth is in `routes/channels.php`: user must own the conversation.

---

## 4. ask() Refactor

`ask()` stops being an SSE generator consumer. The `foreach streamChatWithTools()` loop is replaced by a single call. The `streamChatWithTools()` generator is updated to accept a `ConversationBus` and publish directly instead of yielding.

```php
public function ask(): void
{
    // ... existing setup (system prompt, tools, messages) unchanged ...

    $bus = new ConversationBus($this->conversation->id);
    session()->save(); // release session lock so stop() can fire

    try {
        $streamResult = $client->streamChatWithTools(
            systemPrompt: $systemPrompt,
            messages: $apiMessages,
            tools: $chatTools,
            toolExecutor: $toolExecutor,
            bus: $bus,
            temperature: 0.7,
            useServerTools: $useServerTools,
        );

        $this->persistTurn($bus, $streamResult, interrupted: false);
        $bus->publish('turn_complete');

    } catch (TurnStoppedException | SubagentStoppedException) {
        $this->persistTurn($bus, streamResult: null, interrupted: true);
        $bus->publish('turn_interrupted'); // stop flag already consumed by Cache::pull

    } catch (\Throwable $e) {
        $this->persistTurn($bus, streamResult: null, interrupted: true);
        $bus->publish('turn_error', ['message' => $e->getMessage()]);
        Log::error('ask() failed', ['error' => $e->getMessage()]);
    }

    $this->loadMessages();
    $this->isStreaming = false;
}
```

`persistTurn(ConversationBus $bus, ?StreamResult $streamResult, bool $interrupted)` replaces `persistAssistantMessage()`. It creates the `Message` row using `$bus->events()` for `metadata.events`, `$bus->text()` for `content`, and `$streamResult` for tokens/cost. `$bus->text()` is the accumulated plaintext — the bus appends `text_chunk` payloads to an internal string even though they are not stored in `events[]`.

**What is removed from `ask()`:** the entire `foreach` loop, `$completedTools`, `$fullContent`, `$streamResult`, `$partialReasoning`, `streamUI()` calls, the shutdown function backstop, and `$interrupted` tracking. The bus and try/catch handle all of it.

---

## 5. streamChatWithTools() Changes

`streamChatWithTools()` stops being a generator. It becomes a regular synchronous method that returns `StreamResult`. The internal HTTP loop and tool dispatch logic are unchanged — only the `yield` statements are replaced.

Signature change:
```php
// Before (generator):
public function streamChatWithTools(...): \Generator

// After (plain method):
public function streamChatWithTools(..., ConversationBus $bus): StreamResult
```

Key replacements inside the method:

- `yield $content` (text chunk) → `$bus->publish('text_chunk', ['content' => $content])`
- `yield new ToolEvent($fnName, ..., 'started')` → **removed entirely** — `BaseAgent::execute()` publishes `subagent_started` when it begins; the generator does not duplicate it
- `yield new ToolEvent($fnName, ..., 'completed')` → **removed entirely** — outcome published by `BaseAgent` directly
- `yield new StreamResult(...)` → `return new StreamResult(...)`

`ask()` calls the method and stores the return value for `persistTurn()`:
```php
$streamResult = $client->streamChatWithTools(..., bus: $bus);
```

The `ToolEvent`, `ReasoningChunk` classes are no longer yielded or imported anywhere outside of the method internals. `StreamResult` is still used as a return type.

All sub-agent lifecycle events (`subagent_started`, `subagent_completed`, `subagent_error`) come exclusively from `BaseAgent`. The generator publishes `text_chunk` only.

---

## 6. BaseAgent Changes

`BaseAgent` gains a public `?ConversationBus $bus = null` property. Tool handlers set it before calling `execute()`, same pattern as `$agent->conversationId = ...`.

Inside `execute()`:

```php
public function execute(Brief $brief, Team $team): AgentResult
{
    // ... existing callId, logging setup unchanged ...

    $this->bus?->publish('subagent_started', [
        'agent' => $submitToolName,  // matches orchestrator tool name
        'title' => $this->agentTitle(),
        'color' => $this->agentColor(),
    ]);

    $onToolCall = function (string $name, array $args) use (...): void {
        // Stop check (unchanged — throws SubagentStoppedException)
        if ($this->conversationId && Cache::get("conv-stop:{$this->conversationId}")) {
            throw new SubagentStoppedException();
        }
        // Publish tool call event
        $this->bus?->publish('subagent_tool_call', [
            'agent' => $submitToolName,
            'name'  => $name,
            'args'  => $args,
        ]);
    };

    // ... llmCall() unchanged ...

    if ($result->isOk()) {
        $card = $this->buildCard($payload);
        // ... decorate card with cost/tokens as today ...
        $this->bus?->publish('subagent_completed', [
            'agent' => $submitToolName,
            'card'  => $card,
        ]);
    } else {
        $this->bus?->publish('subagent_error', [
            'agent'   => $submitToolName,
            'message' => $result->errorMessage,
        ]);
    }

    return $result;
}
```

`$lastIntermediateTools`, the `subagent-active:*` and `subagent-tools:*` cache keys, and `pollSubagentTools()` are all deleted — replaced by bus events.

Each concrete agent class gets two protected methods: `agentTitle(): string` and `agentColor(): string`. These return the display name and Tailwind colour used in the working card (currently hardcoded in the blade `$agentMap`).

---

## 7. Stop Mechanism

```
User clicks stop button
  → wire:click="stop"
  → stop() Livewire method
  → Cache::put("conv-stop:{$id}", true, 60)
  → returns immediately (no polling)

ConversationBus::publish()
  → checks flag on every call
  → throws TurnStoppedException

BaseAgent $onToolCall
  → checks same flag before publishing subagent_tool_call
  → throws SubagentStoppedException (caught in llmCall, propagates as null payload)
  → BaseAgent publishes subagent_error
  → TurnStoppedException fires on next bus->publish() in ask()

ask() catch block
  → persistTurn(interrupted: true)
  → bus->publish('turn_interrupted')
     ← Cache::pull already deleted the flag; this publish goes through cleanly
  → loadMessages(), isStreaming = false
```

**Limitation:** Sub-agents with no intermediate tools (Editor, AudiencePicker when fast, Writer, Proofread) cannot be stopped mid-LLM-call. The HTTP request to the LLM API is in-flight. Stop fires as soon as control returns from the HTTP call. For WriterAgent this can be up to 8 minutes. This is an inherent synchronous PHP constraint, not addressable without async job infrastructure.

`stopAndWait()` with its 20-second polling loop is deleted. `stop()` returns in under 100ms.

---

## 8. Persistence — metadata.events

`metadata.tools[]` (current format) is replaced by `metadata.events[]`. Migration adds a new column or the format is changed inline (old messages keep rendering via a compatibility fallback).

Schema of a saved assistant message:

```json
{
  "content": "Based on the research, here's what I recommend...",
  "metadata": {
    "events": [
      { "type": "subagent_started",   "payload": { "agent": "research_topic", "title": "Research sub-agent", "color": "purple" } },
      { "type": "subagent_tool_call", "payload": { "agent": "research_topic", "name": "web_search", "args": { "query": "zero party data" } } },
      { "type": "subagent_completed", "payload": { "agent": "research_topic", "card": { "kind": "research", "summary": "...", "claims": [...] } } },
      { "type": "subagent_started",   "payload": { "agent": "create_outline", "title": "Editor sub-agent", "color": "blue" } },
      { "type": "subagent_completed", "payload": { "agent": "create_outline", "card": { "kind": "outline", ... } } }
    ],
    "interrupted": false,
    "reasoning": "...",
    "reasoning_tokens": 1240,
    "web_searches": 3
  }
}
```

**History reconstruction:** `loadMessages()` passes `metadata.events` to the Blade template, which processes them in order using the same logic as the Alpine live renderer. Cards, pills, error states all render identically from history as they did during streaming.

---

## 9. Frontend — Alpine.js + Laravel Echo

The streaming area is one Alpine component with a single `items[]` array. Each item has a `type` and type-specific fields.

```js
x-data="{
  items: [],
  isStreaming: false,

  init() {
    window.Echo
      .private('conversation.' + conversationId)
      .listen('.ConversationEvent', e => this.handle(e));
  },

  handle(e) {
    const p = e.payload;
    switch (e.type) {
      case 'text_chunk':
        const last = this.items.at(-1);
        if (last?.type === 'text') { last.content += p.content; }
        else { this.items.push({ type: 'text', content: p.content }); }
        break;

      case 'subagent_started':
        this.items.push({ type: 'subagent', agent: p.agent, title: p.title,
                          color: p.color, status: 'working', pills: [], card: null });
        break;

      case 'subagent_tool_call':
        const sa = this.items.findLast(i => i.type === 'subagent' && i.agent === p.agent);
        if (sa) sa.pills.push(p.name.replace(/_/g, ' '));
        break;

      case 'subagent_completed':
        const done = this.items.findLast(i => i.type === 'subagent' && i.agent === p.agent);
        if (done) { done.status = 'done'; done.card = p.card; }
        break;

      case 'subagent_error':
        const err = this.items.findLast(i => i.type === 'subagent' && i.agent === p.agent);
        if (err) { err.status = 'error'; err.message = p.message; }
        break;

      case 'turn_complete':
      case 'turn_interrupted':
      case 'turn_error':
        this.isStreaming = false;
        this.items = [];
        $wire.loadMessages();
        break;
    }
  }
}"
```

The template renders `items` in a `<template x-for>`. Text items render as markdown (via a small JS markdown renderer or `x-html`). Subagent items render as a card with status-appropriate styling — spinner when working, coloured checkmark when done, error state when failed. Pills appear inside the working card as they arrive.

**What is removed from the blade file:**
- `streamUI()` method
- `activeSubAgentCard()` method  
- `pollSubagentTools()` method
- `stopAndWait()` method → replaced by `stop()`
- All `renderResearchCard()`, `renderOutlineCard()`, `renderAudienceCard()`, `renderStyleReferenceCard()`, `renderContentPieceCard()`, `renderSkippedCard()` methods → these card renders move to Alpine templates (live) and a Blade partial (history)
- `cardMetricsFooter()` method → moves into shared partial
- `intermediateToolsPills()` method → same
- `$this->stream()` calls
- `ToolEvent`, `StreamResult`, `ReasoningChunk` imports
- The `foreach $client->streamChatWithTools()` loop
- `$completedTools`, `$fullContent`, `$streamResult`, `$partialReasoning` variables
- The register_shutdown_function backstop

**What is kept:**
- `ask()` (gutted, as described in §4)
- `submitPrompt()` (unchanged)
- `loadMessages()` (made public)
- `persistTurn()` (replaces `persistAssistantMessage()`)
- `stop()` (new, replaces `stopAndWait()`)
- `getConversationStatsProperty()`
- All message history Blade rendering for past turns

---

## 10. Reverb Setup

```bash
composer require laravel/reverb
php artisan install:broadcasting   # publishes config, adds Echo to package.json
npm install
```

Forge daemon command: `php artisan reverb:start --host=0.0.0.0 --port=8080`

Nginx: add WebSocket proxy block to forward `/app/*` to `127.0.0.1:8080`.

`.env` additions:
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

`resources/js/bootstrap.js`: configure `Echo` with the Reverb driver (generated by `install:broadcasting`).

Channel auth in `routes/channels.php`:
```php
Broadcast::channel('conversation.{id}', function ($user, $id) {
    return Conversation::where('id', $id)
        ->whereHas('team', fn($q) => $q->where('user_id', $user->id))
        ->exists();
});
```

---

## 11. Files Created

| File | Purpose |
|---|---|
| `app/Events/ConversationEvent.php` | Broadcastable event |
| `app/Services/ConversationBus.php` | Publishes + accumulates events |
| `app/Services/TurnStoppedException.php` | Thrown by bus when stop flag set |
| `routes/channels.php` | Channel auth |

## 12. Files Significantly Modified

| File | Change |
|---|---|
| `app/Services/OpenRouterClient.php` | `streamChatWithTools()` accepts `ConversationBus`, publishes instead of yielding |
| `app/Services/Writer/BaseAgent.php` | Publishes lifecycle events via bus, removes cache polling |
| `resources/views/pages/teams/⚡create-chat.blade.php` | Gutted SSE loop, new Alpine component, new stop(), new persistTurn() |
| `config/broadcasting.php` | Set driver to reverb |
| `resources/js/bootstrap.js` | Configure Echo with Reverb |

## 13. Files / Code Deleted

- `app/Services/SubagentStoppedException.php` → replaced by `TurnStoppedException`; both stop cases caught together in `ask()`
- `app/Services/ToolEvent.php` → deleted; events go through bus, not yielded
- `app/Services/ReasoningChunk.php` → deleted; reasoning accumulated internally in `streamChatWithTools()`, stored in `StreamResult`
- `app/Services/StreamResult.php` → **kept**; still returned by `streamChatWithTools()` and used by `persistTurn()`
- Cache keys: `subagent-active:*`, `subagent-tools:*` → deleted
- `pollSubagentTools()`, `stopAndWait()`, `streamUI()`, `activeSubAgentCard()`, all `render*Card()` methods in blade

---

## 14. Backwards Compatibility

Old messages in the database have `metadata.tools[]` not `metadata.events[]`. The history renderer checks: if `metadata.events` exists use it; if `metadata.tools` exists fall back to the legacy renderer. The legacy renderer is kept read-only — no new messages will use it.
