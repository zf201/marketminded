# Create (AI Chat) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a persistent, streaming AI chat page ("Create") to MarketMinded using the team's OpenRouter integration.

**Architecture:** Two new tables (conversations, messages) with Eloquent models. A `streamChat()` method on the existing `OpenRouterClient`. A Volt page component using Livewire's `$this->stream()` for real-time token delivery. Full page at `/{team}/create`.

**Tech Stack:** Laravel 13, Livewire/Volt, Flux UI, OpenRouter API (SSE streaming), Pest for tests.

**Spec:** `docs/superpowers/specs/2026-04-12-create-ai-chat-design.md`

---

### Task 1: Database — conversations and messages tables

**Files:**
- Create: `database/migrations/2026_04_12_200000_create_conversations_table.php`
- Create: `database/migrations/2026_04_12_200001_create_messages_table.php`

- [ ] **Step 1: Create conversations migration**

```bash
sail artisan make:migration create_conversations_table
```

Then edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
            $table->index(['team_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
```

- [ ] **Step 2: Create messages migration**

```bash
sail artisan make:migration create_messages_table
```

Then edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('model', 100)->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
sail artisan migrate
```

Expected: Both tables created successfully.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*conversations* database/migrations/*messages*
git commit -m "feat: add conversations and messages tables"
```

---

### Task 2: Eloquent models — Conversation and Message

**Files:**
- Create: `app/Models/Conversation.php`
- Create: `app/Models/Message.php`
- Modify: `app/Models/Team.php` — add `conversations()` relationship

- [ ] **Step 1: Write test for Conversation model**

Create `tests/Unit/Models/ConversationTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

test('conversation belongs to team and user', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test conversation',
    ]);

    expect($conversation->team->id)->toBe($team->id);
    expect($conversation->user->id)->toBe($user->id);
});

test('conversation has many messages ordered by created_at', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test',
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'First',
        'created_at' => now()->subMinute(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Second',
        'created_at' => now(),
    ]);

    $messages = $conversation->messages;
    expect($messages)->toHaveCount(2);
    expect($messages->first()->content)->toBe('First');
    expect($messages->last()->content)->toBe('Second');
});

test('team has many conversations', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Chat 1',
    ]);

    expect($team->conversations)->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
sail test tests/Unit/Models/ConversationTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create Conversation model**

Create `app/Models/Conversation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'user_id', 'title'])]
class Conversation extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }
}
```

- [ ] **Step 4: Create Message model**

Create `app/Models/Message.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role', 'content', 'model', 'input_tokens', 'output_tokens', 'cost'])]
class Message extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            $message->created_at ??= now();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

- [ ] **Step 5: Add conversations relationship to Team**

In `app/Models/Team.php`, add after the `aiTasks()` method:

```php
/**
 * Get all conversations for this team.
 *
 * @return HasMany<Conversation, $this>
 */
public function conversations(): HasMany
{
    return $this->hasMany(Conversation::class)->orderByDesc('updated_at');
}
```

- [ ] **Step 6: Run tests**

```bash
sail test tests/Unit/Models/ConversationTest.php
```

Expected: All 3 tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Models/Conversation.php app/Models/Message.php app/Models/Team.php tests/Unit/Models/ConversationTest.php
git commit -m "feat: add Conversation and Message models"
```

---

### Task 3: Streaming support in OpenRouterClient

**Files:**
- Modify: `app/Services/OpenRouterClient.php` — add `streamChat()` method
- Create: `app/Services/StreamResult.php`
- Create: `tests/Unit/Services/OpenRouterClientStreamTest.php`

- [ ] **Step 1: Create StreamResult DTO**

Create `app/Services/StreamResult.php`:

```php
<?php

namespace App\Services;

class StreamResult
{
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $cost = 0,
    ) {}
}
```

- [ ] **Step 2: Write test for streamChat**

Create `tests/Unit/Services/OpenRouterClientStreamTest.php`:

```php
<?php

use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('streamChat yields content chunks and returns StreamResult', function () {
    $sseBody = implode("\n", [
        'data: {"choices":[{"delta":{"content":"Hello"}}]}',
        '',
        'data: {"choices":[{"delta":{"content":" world"}}]}',
        '',
        'data: {"choices":[{"delta":{"content":"!"}}],"usage":{"prompt_tokens":10,"completion_tokens":3,"cost":0.001}}',
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $chunks = [];
    $result = null;

    foreach ($client->streamChat('You are helpful.', [['role' => 'user', 'content' => 'Hi']]) as $chunk) {
        if ($chunk instanceof StreamResult) {
            $result = $chunk;
        } else {
            $chunks[] = $chunk;
        }
    }

    expect($chunks)->toBe(['Hello', ' world', '!']);
    expect($result)->toBeInstanceOf(StreamResult::class);
    expect($result->content)->toBe('Hello world!');
    expect($result->inputTokens)->toBe(10);
    expect($result->outputTokens)->toBe(3);

    Http::assertSent(function ($request) {
        return $request['stream'] === true
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'You are helpful.'
            && $request['messages'][1]['role'] === 'user';
    });
});

test('streamChat sends system prompt and message history', function () {
    $sseBody = implode("\n", [
        'data: {"choices":[{"delta":{"content":"OK"}}],"usage":{"prompt_tokens":5,"completion_tokens":1}}',
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $messages = [
        ['role' => 'user', 'content' => 'Hi'],
        ['role' => 'assistant', 'content' => 'Hello'],
        ['role' => 'user', 'content' => 'How are you?'],
    ];

    $result = null;
    foreach ($client->streamChat('System prompt', $messages) as $chunk) {
        if ($chunk instanceof StreamResult) {
            $result = $chunk;
        }
    }

    Http::assertSent(function ($request) {
        return count($request['messages']) === 4
            && $request['messages'][0]['role'] === 'system';
    });
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
sail test tests/Unit/Services/OpenRouterClientStreamTest.php
```

Expected: FAIL — method `streamChat` does not exist.

- [ ] **Step 4: Implement streamChat method**

Add to `app/Services/OpenRouterClient.php`, after the `chat()` method:

```php
/**
 * Stream a chat completion. Yields string chunks, then a StreamResult as the final value.
 *
 * @param  array<int, array{role: string, content: string}>  $messages
 * @return \Generator<int, string|StreamResult>
 */
public function streamChat(string $systemPrompt, array $messages, float $temperature = 0.7): \Generator
{
    $allMessages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $messages,
    );

    $body = [
        'model' => $this->model,
        'messages' => $allMessages,
        'temperature' => $temperature,
        'stream' => true,
    ];

    $response = Http::timeout(120)
        ->withHeader('Authorization', "Bearer {$this->apiKey}")
        ->withOptions(['stream' => true])
        ->post(self::API_URL, $body);

    $fullContent = '';
    $inputTokens = 0;
    $outputTokens = 0;
    $cost = 0.0;
    $buffer = '';

    $body = $response->getBody();

    while (! $body->eof()) {
        $buffer .= $body->read(1024);

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);

            if ($line === '' || $line === 'data: [DONE]') {
                continue;
            }

            if (! str_starts_with($line, 'data: ')) {
                continue;
            }

            $json = json_decode(substr($line, 6), true);

            if (! $json) {
                continue;
            }

            $delta = $json['choices'][0]['delta'] ?? [];
            $content = $delta['content'] ?? '';

            if ($content !== '') {
                $fullContent .= $content;
                yield $content;
            }

            // Usage comes on the final chunk
            if (isset($json['usage'])) {
                $inputTokens = $json['usage']['prompt_tokens'] ?? 0;
                $outputTokens = $json['usage']['completion_tokens'] ?? 0;
                $cost = (float) ($json['usage']['cost'] ?? 0);
            }
        }
    }

    yield new StreamResult(
        content: $fullContent,
        inputTokens: $inputTokens,
        outputTokens: $outputTokens,
        cost: $cost,
    );
}
```

- [ ] **Step 5: Run tests**

```bash
sail test tests/Unit/Services/OpenRouterClientStreamTest.php
```

Expected: All tests pass.

- [ ] **Step 6: Run full test suite to check for regressions**

```bash
sail test
```

Expected: All existing tests still pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/OpenRouterClient.php app/Services/StreamResult.php tests/Unit/Services/OpenRouterClientStreamTest.php
git commit -m "feat: add streaming support to OpenRouterClient"
```

---

### Task 4: Route and sidebar nav item

**Files:**
- Modify: `routes/web.php` — add create route
- Modify: `resources/views/layouts/app/sidebar.blade.php` — add "Create" nav item

- [ ] **Step 1: Add route**

In `routes/web.php`, add inside the team-scoped route group, after the `ai-operations` line:

```php
Route::livewire('create', 'pages::teams.create')->name('create');
```

- [ ] **Step 2: Add sidebar item**

In `resources/views/layouts/app/sidebar.blade.php`, add after the "AI Operations" sidebar item (inside the Platform group):

```blade
<flux:sidebar.item icon="chat-bubble-left-right" :href="route('create')" :current="request()->routeIs('create')" wire:navigate>
    {{ __('Create') }}
</flux:sidebar.item>
```

- [ ] **Step 3: Commit**

```bash
git add routes/web.php resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add Create route and sidebar nav item"
```

---

### Task 5: Create page — Volt component with streaming chat

**Files:**
- Create: `resources/views/pages/teams/⚡create.blade.php`

- [ ] **Step 1: Create the Volt page component**

Create `resources/views/pages/teams/⚡create.blade.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public ?int $conversationId = null;

    public string $input = '';

    public bool $isStreaming = false;

    public array $messages = [];

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;

        // Load most recent conversation for this user+team
        $conversation = Conversation::where('team_id', $current_team->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        if ($conversation) {
            $this->conversationId = $conversation->id;
            $this->loadMessages();
        }
    }

    public function sendMessage(): void
    {
        $content = trim($this->input);

        if ($content === '' || $this->isStreaming) {
            return;
        }

        if (! $this->teamModel->openrouter_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('OpenRouter API key required. Add it in Team Settings.'));
            return;
        }

        $this->input = '';
        $this->isStreaming = true;

        // Create conversation if needed
        if (! $this->conversationId) {
            $conversation = Conversation::create([
                'team_id' => $this->teamModel->id,
                'user_id' => Auth::id(),
                'title' => mb_substr($content, 0, 80),
            ]);
            $this->conversationId = $conversation->id;
        }

        // Save user message
        Message::create([
            'conversation_id' => $this->conversationId,
            'role' => 'user',
            'content' => $content,
        ]);

        $this->messages[] = ['role' => 'user', 'content' => $content];
        $this->messages[] = ['role' => 'assistant', 'content' => ''];

        // Build message history for API
        $apiMessages = collect($this->messages)
            ->filter(fn ($m) => $m['content'] !== '')
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
            ->toArray();

        $client = new OpenRouterClient(
            apiKey: $this->teamModel->openrouter_api_key,
            model: $this->teamModel->fast_model,
            urlFetcher: new UrlFetcher,
        );

        $fullContent = '';
        $streamResult = null;

        try {
            foreach ($client->streamChat('You are a helpful AI assistant.', $apiMessages) as $chunk) {
                if ($chunk instanceof StreamResult) {
                    $streamResult = $chunk;
                } else {
                    $fullContent .= $chunk;
                    $this->stream('assistant-response', $chunk, true);
                }
            }
        } catch (\Throwable $e) {
            $fullContent = 'Sorry, something went wrong. Please try again.';
            $this->stream('assistant-response', $fullContent, true);
        }

        // Save assistant message
        Message::create([
            'conversation_id' => $this->conversationId,
            'role' => 'assistant',
            'content' => $fullContent,
            'model' => $this->teamModel->fast_model,
            'input_tokens' => $streamResult?->inputTokens ?? 0,
            'output_tokens' => $streamResult?->outputTokens ?? 0,
            'cost' => $streamResult?->cost ?? 0,
        ]);

        // Update the last message in our local array
        $this->messages[count($this->messages) - 1]['content'] = $fullContent;
        $this->isStreaming = false;
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->messages = [];
        $this->input = '';
    }

    public function render()
    {
        return $this->view()->title(__('Create'));
    }

    private function loadMessages(): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $this->messages = $conversation->messages
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Create') }}</flux:heading>
        <flux:button variant="subtle" size="sm" icon="plus" wire:click="newConversation">
            {{ __('New conversation') }}
        </flux:button>
    </div>

    {{-- Messages --}}
    <div
        class="flex-1 overflow-y-auto px-6 py-4 space-y-4"
        id="messages-container"
        x-data
        x-effect="$nextTick(() => { const el = document.getElementById('messages-container'); el.scrollTop = el.scrollHeight; })"
    >
        @if (empty($messages))
            <div class="flex h-full items-center justify-center">
                <div class="text-center">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                    <flux:heading size="lg" class="mt-4">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mt-1">{{ __('Start a conversation with your AI assistant.') }}</flux:subheading>
                </div>
            </div>
        @else
            @foreach ($messages as $index => $message)
                @if ($message['role'] === 'user')
                    <div class="flex justify-end">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-3 dark:bg-zinc-700">
                            <flux:text class="whitespace-pre-wrap">{{ $message['content'] }}</flux:text>
                        </div>
                    </div>
                @else
                    <div class="flex justify-start">
                        <div class="max-w-2xl px-4 py-3">
                            @if ($isStreaming && $index === count($messages) - 1)
                                <flux:text class="whitespace-pre-wrap" wire:stream="assistant-response">{{ $message['content'] }}</flux:text>
                            @else
                                <flux:text class="whitespace-pre-wrap">{{ $message['content'] }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>

    {{-- Input --}}
    <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
        <form wire:submit="sendMessage" class="flex items-end gap-3">
            <div class="flex-1">
                <flux:textarea
                    wire:model="input"
                    placeholder="{{ __('Type your message...') }}"
                    rows="1"
                    :disabled="$isStreaming"
                    x-data
                    x-on:keydown.enter.prevent="if (!$event.shiftKey) { $wire.sendMessage(); }"
                    x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                />
            </div>
            <flux:button
                type="submit"
                variant="primary"
                icon="paper-airplane"
                :disabled="$isStreaming"
            />
        </form>
    </div>
</div>
```

- [ ] **Step 2: Verify the page loads**

Navigate to `http://localhost/{team-slug}/create` in the browser.

Expected: Page loads with empty state showing "What would you like to create?"

- [ ] **Step 3: Test sending a message**

Type a message and press Enter or click the send button.

Expected:
- User message appears right-aligned with zinc background
- Assistant response streams in on the left
- Message persists after page reload

- [ ] **Step 4: Test "New conversation"**

Click "New conversation" button.

Expected: Chat clears, empty state returns. Previous conversation is preserved in the DB.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/teams/⚡create.blade.php"
git commit -m "feat: add Create page with streaming AI chat"
```

---

### Task 6: Verify end-to-end and run full test suite

- [ ] **Step 1: Run all tests**

```bash
sail test
```

Expected: All tests pass, no regressions.

- [ ] **Step 2: Manual browser test checklist**

Verify in the browser:
- Page accessible from sidebar "Create" link
- Empty state shows on first visit
- Typing a message and pressing Enter sends it
- Shift+Enter creates a new line (doesn't send)
- Assistant response streams in token by token
- Input is disabled during streaming
- After streaming completes, input re-enables
- Reloading the page shows the conversation history
- "New conversation" clears the chat
- Works on mobile (sidebar collapses, chat is responsive)
- Missing API key shows a toast error

- [ ] **Step 3: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: polish Create chat page"
```
