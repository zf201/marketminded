# Brand Intelligence Consolidation + Chat Tool-Calling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge Brand Setup and Brand Intelligence into one page, add tool-calling to the streaming chat so the AI can update the brand profile, and make three distinct chat types with separate system prompts.

**Architecture:** Add `type` column to conversations. Build `ToolEvent` DTO and `BrandIntelligenceToolHandler` service. Extend `OpenRouterClient` with a `streamChatWithTools()` method that yields text chunks, tool events, and a final result. Rewrite the chat page to handle type selection, tool activity rendering, and per-type system prompts. Merge brand setup fields into the brand intelligence page.

**Tech Stack:** Laravel 13, Livewire/Volt, Flux UI, OpenRouter API (SSE streaming with tool calls), Pest

**Spec:** `docs/superpowers/specs/2026-04-12-brand-intelligence-chat-consolidation-design.md`

---

### Task 1: Add `type` column to conversations

**Files:**
- Create: `database/migrations/..._add_type_to_conversations_table.php`
- Modify: `app/Models/Conversation.php`

- [ ] **Step 1: Create migration**

```bash
sail artisan make:migration add_type_to_conversations_table
```

Edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type', 30)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
```

- [ ] **Step 2: Update Conversation model fillable**

In `app/Models/Conversation.php`, change the Fillable attribute:

```php
#[Fillable(['team_id', 'user_id', 'title', 'type'])]
```

- [ ] **Step 3: Run migration**

```bash
sail artisan migrate
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*add_type_to_conversations* app/Models/Conversation.php
git commit -m "feat: add type column to conversations table"
```

---

### Task 2: ToolEvent DTO and StreamResult update

**Files:**
- Create: `app/Services/ToolEvent.php`
- Modify: `app/Services/StreamResult.php` — add web search count

- [ ] **Step 1: Create ToolEvent DTO**

Create `app/Services/ToolEvent.php`:

```php
<?php

namespace App\Services;

class ToolEvent
{
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly ?string $result,
        public readonly string $status,
    ) {}
}
```

- [ ] **Step 2: Add webSearchRequests to StreamResult**

Update `app/Services/StreamResult.php`:

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
        public readonly int $webSearchRequests = 0,
    ) {}
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/ToolEvent.php app/Services/StreamResult.php
git commit -m "feat: add ToolEvent DTO and web search count to StreamResult"
```

---

### Task 3: BrandIntelligenceToolHandler service

**Files:**
- Create: `app/Services/BrandIntelligenceToolHandler.php`
- Create: `tests/Unit/Services/BrandIntelligenceToolHandlerTest.php`

- [ ] **Step 1: Write tests**

Create `tests/Unit/Services/BrandIntelligenceToolHandlerTest.php`:

```php
<?php

use App\Models\Team;
use App\Models\User;
use App\Services\BrandIntelligenceToolHandler;

test('updates team setup fields', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $result = $handler->execute($team, [
        'setup' => [
            'homepage_url' => 'https://example.com',
            'brand_description' => 'We do stuff',
        ],
    ]);

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->brand_description)->toBe('We do stuff');
    expect($result)->toContain('setup');
});

test('creates positioning via updateOrCreate', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $handler->execute($team, [
        'positioning' => [
            'value_proposition' => 'We make things better',
            'target_market' => 'Everyone',
        ],
    ]);

    $positioning = $team->brandPositioning;
    expect($positioning)->not->toBeNull();
    expect($positioning->value_proposition)->toBe('We make things better');
    expect($positioning->target_market)->toBe('Everyone');
});

test('replaces all personas when personas key is present', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    // Create existing persona
    $team->audiencePersonas()->create(['label' => 'Old Persona', 'sort_order' => 0]);
    expect($team->audiencePersonas()->count())->toBe(1);

    $handler = new BrandIntelligenceToolHandler;
    $handler->execute($team, [
        'personas' => [
            ['label' => 'New Persona 1', 'role' => 'CTO'],
            ['label' => 'New Persona 2', 'role' => 'CEO'],
        ],
    ]);

    $personas = $team->audiencePersonas()->get();
    expect($personas)->toHaveCount(2);
    expect($personas[0]->label)->toBe('New Persona 1');
    expect($personas[1]->label)->toBe('New Persona 2');
    expect($personas[0]->sort_order)->toBe(0);
    expect($personas[1]->sort_order)->toBe(1);
});

test('creates voice profile via updateOrCreate', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $handler->execute($team, [
        'voice' => [
            'voice_analysis' => 'Professional and warm',
            'preferred_length' => 1200,
        ],
    ]);

    $voice = $team->voiceProfile;
    expect($voice)->not->toBeNull();
    expect($voice->voice_analysis)->toBe('Professional and warm');
    expect($voice->preferred_length)->toBe(1200);
});

test('handles multiple sections in one call', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $result = $handler->execute($team, [
        'setup' => ['homepage_url' => 'https://example.com'],
        'positioning' => ['value_proposition' => 'Best in class'],
    ]);

    expect($result)->toContain('setup');
    expect($result)->toContain('positioning');
    expect($team->fresh()->homepage_url)->toBe('https://example.com');
    expect($team->brandPositioning->value_proposition)->toBe('Best in class');
});

test('returns JSON with saved sections', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $result = $handler->execute($team, [
        'setup' => ['homepage_url' => 'https://example.com'],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('saved');
    expect($decoded['sections'])->toBe(['setup']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
sail test tests/Unit/Services/BrandIntelligenceToolHandlerTest.php
```

- [ ] **Step 3: Implement the handler**

Create `app/Services/BrandIntelligenceToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Arr;

class BrandIntelligenceToolHandler
{
    private const SETUP_FIELDS = [
        'homepage_url', 'blog_url', 'brand_description',
        'product_urls', 'competitor_urls', 'style_reference_urls',
        'target_audience', 'tone_keywords', 'content_language',
    ];

    public function execute(Team $team, array $data): string
    {
        $savedSections = [];

        if (isset($data['setup'])) {
            $team->update(Arr::only($data['setup'], self::SETUP_FIELDS));
            $savedSections[] = 'setup';
        }

        if (isset($data['positioning'])) {
            $team->brandPositioning()->updateOrCreate(
                ['team_id' => $team->id],
                $data['positioning'],
            );
            $savedSections[] = 'positioning';
        }

        if (isset($data['personas'])) {
            $team->audiencePersonas()->delete();

            foreach ($data['personas'] as $i => $persona) {
                $team->audiencePersonas()->create(array_merge($persona, ['sort_order' => $i]));
            }

            $savedSections[] = 'personas';
        }

        if (isset($data['voice'])) {
            $team->voiceProfile()->updateOrCreate(
                ['team_id' => $team->id],
                $data['voice'],
            );
            $savedSections[] = 'voice';
        }

        return json_encode(['status' => 'saved', 'sections' => $savedSections]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_brand_intelligence',
                'description' => 'Update the brand intelligence profile. All sections and fields are optional — only include what you want to change. When updating personas, provide the full list (replaces existing).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'setup' => [
                            'type' => 'object',
                            'properties' => [
                                'homepage_url' => ['type' => 'string'],
                                'blog_url' => ['type' => 'string'],
                                'brand_description' => ['type' => 'string'],
                                'product_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'competitor_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'style_reference_urls' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'target_audience' => ['type' => 'string'],
                                'tone_keywords' => ['type' => 'string'],
                                'content_language' => ['type' => 'string'],
                            ],
                        ],
                        'positioning' => [
                            'type' => 'object',
                            'properties' => [
                                'value_proposition' => ['type' => 'string'],
                                'target_market' => ['type' => 'string'],
                                'differentiators' => ['type' => 'string'],
                                'core_problems' => ['type' => 'string'],
                                'products_services' => ['type' => 'string'],
                                'primary_cta' => ['type' => 'string'],
                            ],
                        ],
                        'personas' => [
                            'type' => 'array',
                            'description' => 'Full list of audience personas. Replaces all existing.',
                            'items' => [
                                'type' => 'object',
                                'required' => ['label'],
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'role' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'pain_points' => ['type' => 'string'],
                                    'push' => ['type' => 'string'],
                                    'pull' => ['type' => 'string'],
                                    'anxiety' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'voice' => [
                            'type' => 'object',
                            'properties' => [
                                'voice_analysis' => ['type' => 'string'],
                                'content_types' => ['type' => 'string'],
                                'should_avoid' => ['type' => 'string'],
                                'should_use' => ['type' => 'string'],
                                'style_inspiration' => ['type' => 'string'],
                                'preferred_length' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function fetchUrlToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Fetch and read the content of a web page URL.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['url'],
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
sail test tests/Unit/Services/BrandIntelligenceToolHandlerTest.php
```

Expected: All 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/BrandIntelligenceToolHandler.php tests/Unit/Services/BrandIntelligenceToolHandlerTest.php
git commit -m "feat: add BrandIntelligenceToolHandler with tool schema"
```

---

### Task 4: streamChatWithTools() on OpenRouterClient

**Files:**
- Modify: `app/Services/OpenRouterClient.php` — add `streamChatWithTools()` method
- Create: `tests/Unit/Services/OpenRouterClientToolStreamTest.php`

This is the most complex task. The method streams SSE, but when the model emits `tool_calls` in the delta, it:
1. Accumulates the tool call arguments across multiple deltas
2. Yields a `ToolEvent` with status `started`
3. Executes the tool
4. Yields a `ToolEvent` with status `completed` and the result
5. Sends the tool result back to the API and starts a new streaming request
6. Continues yielding text chunks from the new stream

For OpenRouter server tools (`openrouter:`), no events are yielded — they're handled transparently.

- [ ] **Step 1: Write test for tool-calling stream**

Create `tests/Unit/Services/OpenRouterClientToolStreamTest.php`:

```php
<?php

use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('streamChatWithTools handles fetch_url tool call', function () {
    // First request: model calls fetch_url
    $toolCallResponse = json_encode([
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'fetch_url',
                        'arguments' => json_encode(['url' => 'https://example.com']),
                    ],
                ]],
            ],
        ]],
        'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
    ]);

    // Second request: model responds with text after getting tool result
    $textStream = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'I read the page.']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5, 'cost' => 0.001]]),
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push($toolCallResponse, 200) // non-streaming tool call response
            ->push($textStream, 200, ['Content-Type' => 'text/event-stream']), // streaming text
        'example.com' => Http::response('<html><head><title>Example</title></head><body>Content here</body></html>'),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    $events = [];
    $chunks = [];
    $result = null;

    foreach ($client->streamChatWithTools('System prompt', [['role' => 'user', 'content' => 'Read example.com']], [
        ['type' => 'function', 'function' => ['name' => 'fetch_url', 'parameters' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]]]],
    ]) as $item) {
        if ($item instanceof ToolEvent) {
            $events[] = $item;
        } elseif ($item instanceof StreamResult) {
            $result = $item;
        } else {
            $chunks[] = $item;
        }
    }

    expect($events)->toHaveCount(2);
    expect($events[0]->name)->toBe('fetch_url');
    expect($events[0]->status)->toBe('started');
    expect($events[1]->status)->toBe('completed');
    expect($chunks)->toBe(['I read the page.']);
    expect($result)->toBeInstanceOf(StreamResult::class);
    expect($result->content)->toBe('I read the page.');
});

test('streamChatWithTools passes through text-only stream', function () {
    $sseBody = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]),
        '',
        'data: ' . json_encode(['choices' => [['delta' => ['content' => ' world']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'cost' => 0.0005]]),
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

    foreach ($client->streamChatWithTools('System', [['role' => 'user', 'content' => 'Hi']]) as $item) {
        if ($item instanceof StreamResult) {
            $result = $item;
        } elseif (is_string($item)) {
            $chunks[] = $item;
        }
    }

    expect($chunks)->toBe(['Hello', ' world']);
    expect($result->content)->toBe('Hello world');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
sail test tests/Unit/Services/OpenRouterClientToolStreamTest.php
```

- [ ] **Step 3: Implement streamChatWithTools**

Add to `app/Services/OpenRouterClient.php` after `streamChat()`:

```php
/**
 * Stream a chat completion with tool support.
 * Yields string chunks, ToolEvent objects, and a final StreamResult.
 *
 * When the model calls a tool, yields ToolEvent(started), executes the tool,
 * yields ToolEvent(completed), then continues streaming.
 *
 * @param  array<int, array{role: string, content: string}>  $messages
 * @param  array  $tools  Tool definitions (function schemas)
 * @param  callable|null  $toolExecutor  fn(string $name, array $args): string — custom tool executor
 * @return \Generator<int, string|ToolEvent|StreamResult>
 */
public function streamChatWithTools(
    string $systemPrompt,
    array $messages,
    array $tools = [],
    ?callable $toolExecutor = null,
    float $temperature = 0.7,
    bool $useServerTools = true,
): \Generator {
    $allMessages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $messages,
    );
    $allTools = $useServerTools ? array_merge(self::SERVER_TOOLS, $tools) : $tools;

    $totalInputTokens = 0;
    $totalOutputTokens = 0;
    $totalCost = 0.0;
    $webSearchRequests = 0;
    $fullContent = '';

    for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
        $body = [
            'model' => $this->model,
            'messages' => $allMessages,
            'temperature' => $temperature,
            'stream' => true,
        ];

        if (! empty($allTools)) {
            $body['tools'] = $allTools;
        }

        $response = Http::timeout(120)
            ->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->withOptions(['stream' => true])
            ->post(self::API_URL, $body);

        $contentType = $response->header('Content-Type') ?? '';
        $isStream = str_contains($contentType, 'text/event-stream');

        // Non-streaming response (tool call without streaming)
        if (! $isStream) {
            $json = $response->json();
            $usage = $json['usage'] ?? [];
            $totalInputTokens += $usage['prompt_tokens'] ?? 0;
            $totalOutputTokens += $usage['completion_tokens'] ?? 0;
            $totalCost += (float) ($usage['cost'] ?? 0);
            $webSearchRequests += $usage['server_tool_use']['web_search_requests'] ?? 0;

            $choice = $json['choices'][0]['message'] ?? [];
            $allMessages[] = $choice;

            if (! empty($choice['tool_calls'])) {
                foreach ($choice['tool_calls'] as $toolCall) {
                    $fnName = $toolCall['function']['name'];
                    $fnArgs = json_decode($toolCall['function']['arguments'], true) ?? [];

                    if (str_starts_with($fnName, 'openrouter:')) {
                        $allMessages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'],
                            'content' => 'Handled by server.',
                        ];
                        continue;
                    }

                    yield new ToolEvent($fnName, $fnArgs, null, 'started');

                    if ($toolExecutor) {
                        $toolResult = $toolExecutor($fnName, $fnArgs);
                    } elseif ($fnName === 'fetch_url') {
                        $toolResult = $this->urlFetcher->fetch($fnArgs['url'] ?? '');
                    } else {
                        $toolResult = "Unknown tool: {$fnName}";
                    }

                    yield new ToolEvent($fnName, $fnArgs, $toolResult, 'completed');

                    $allMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => $toolResult,
                    ];
                }

                continue; // Loop back for next request
            }

            // No tool calls — content response
            $content = $choice['content'] ?? '';
            if ($content !== '') {
                $fullContent .= $content;
                yield $content;
            }
            break;
        }

        // Streaming response — parse SSE
        $buffer = '';
        $streamBody = $response->getBody();
        $hasToolCalls = false;
        $streamToolCalls = [];
        $streamContent = '';

        while (! $streamBody->eof()) {
            $buffer .= $streamBody->read(1024);

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

                // Text content
                $content = $delta['content'] ?? '';
                if ($content !== '') {
                    $fullContent .= $content;
                    $streamContent .= $content;
                    yield $content;
                }

                // Tool calls in delta
                if (isset($delta['tool_calls'])) {
                    $hasToolCalls = true;
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (! isset($streamToolCalls[$idx])) {
                            $streamToolCalls[$idx] = [
                                'id' => $tc['id'] ?? '',
                                'name' => $tc['function']['name'] ?? '',
                                'arguments' => '',
                            ];
                        }
                        if (isset($tc['id']) && $tc['id'] !== '') {
                            $streamToolCalls[$idx]['id'] = $tc['id'];
                        }
                        if (isset($tc['function']['name']) && $tc['function']['name'] !== '') {
                            $streamToolCalls[$idx]['name'] = $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $streamToolCalls[$idx]['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }

                // Usage on final chunk
                if (isset($json['usage'])) {
                    $totalInputTokens += $json['usage']['prompt_tokens'] ?? 0;
                    $totalOutputTokens += $json['usage']['completion_tokens'] ?? 0;
                    $totalCost += (float) ($json['usage']['cost'] ?? 0);
                    $webSearchRequests += $json['usage']['server_tool_use']['web_search_requests'] ?? 0;
                }
            }
        }

        if ($hasToolCalls && ! empty($streamToolCalls)) {
            // Build assistant message with tool calls for history
            $assistantMsg = ['role' => 'assistant', 'content' => $streamContent ?: null, 'tool_calls' => []];
            foreach ($streamToolCalls as $tc) {
                $assistantMsg['tool_calls'][] = [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']],
                ];
            }
            $allMessages[] = $assistantMsg;

            // Execute each tool call
            foreach ($streamToolCalls as $tc) {
                $fnName = $tc['name'];
                $fnArgs = json_decode($tc['arguments'], true) ?? [];

                if (str_starts_with($fnName, 'openrouter:')) {
                    $allMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'],
                        'content' => 'Handled by server.',
                    ];
                    continue;
                }

                yield new ToolEvent($fnName, $fnArgs, null, 'started');

                if ($toolExecutor) {
                    $toolResult = $toolExecutor($fnName, $fnArgs);
                } elseif ($fnName === 'fetch_url') {
                    $toolResult = $this->urlFetcher->fetch($fnArgs['url'] ?? '');
                } else {
                    $toolResult = "Unknown tool: {$fnName}";
                }

                yield new ToolEvent($fnName, $fnArgs, $toolResult, 'completed');

                $allMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content' => $toolResult,
                ];
            }

            continue; // Loop back for next request
        }

        break; // No tool calls — we're done
    }

    yield new StreamResult(
        content: $fullContent,
        inputTokens: $totalInputTokens,
        outputTokens: $totalOutputTokens,
        cost: $totalCost,
        webSearchRequests: $webSearchRequests,
    );
}
```

- [ ] **Step 4: Run tests**

```bash
sail test tests/Unit/Services/OpenRouterClientToolStreamTest.php
```

Expected: Both tests pass.

- [ ] **Step 5: Run full test suite**

```bash
sail test
```

Expected: All tests pass. The existing `streamChat()` tests should still pass since we didn't change that method. The existing `StreamResult` tests may need updating if they check constructor arity.

- [ ] **Step 6: Commit**

```bash
git add app/Services/OpenRouterClient.php tests/Unit/Services/OpenRouterClientToolStreamTest.php
git commit -m "feat: add streamChatWithTools with tool-calling loop"
```

---

### Task 5: Chat type selection and system prompt builder

**Files:**
- Create: `app/Services/ChatPromptBuilder.php`
- Create: `tests/Unit/Services/ChatPromptBuilderTest.php`

- [ ] **Step 1: Write tests**

Create `tests/Unit/Services/ChatPromptBuilderTest.php`:

```php
<?php

use App\Models\Team;
use App\Models\User;
use App\Services\ChatPromptBuilder;

test('brand type prompt includes tool instructions', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $prompt = ChatPromptBuilder::build('brand', $team);

    expect($prompt)->toContain('update_brand_intelligence');
    expect($prompt)->toContain('fetch_url');
    expect($prompt)->toContain('https://example.com');
});

test('topics type prompt includes brand profile', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com', 'brand_description' => 'We do stuff']);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('We do stuff');
    expect($prompt)->not->toContain('update_brand_intelligence');
});

test('write type prompt includes voice profile when available', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->voiceProfile()->create([
        'voice_analysis' => 'Professional and warm',
        'preferred_length' => 1500,
    ]);

    $prompt = ChatPromptBuilder::build('write', $team);

    expect($prompt)->toContain('Professional and warm');
});

test('topics type nudges when profile is thin', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('brand knowledge');
});

test('returns tools for brand type', function () {
    $tools = ChatPromptBuilder::tools('brand');

    $names = collect($tools)->map(fn ($t) => $t['function']['name'] ?? $t['type'] ?? '')->toArray();
    expect($names)->toContain('update_brand_intelligence');
    expect($names)->toContain('fetch_url');
});

test('returns no custom tools for topics type', function () {
    $tools = ChatPromptBuilder::tools('topics');

    expect($tools)->toBeEmpty();
});

test('returns no custom tools for write type', function () {
    $tools = ChatPromptBuilder::tools('write');

    expect($tools)->toBeEmpty();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
sail test tests/Unit/Services/ChatPromptBuilderTest.php
```

- [ ] **Step 3: Implement ChatPromptBuilder**

Create `app/Services/ChatPromptBuilder.php`:

```php
<?php

namespace App\Services;

use App\Models\Team;

class ChatPromptBuilder
{
    public static function build(string $type, Team $team): string
    {
        $profile = self::buildProfileJson($team);
        $hasProfile = $team->homepage_url || $team->brandPositioning || $team->audiencePersonas()->exists();

        return match ($type) {
            'brand' => self::brandPrompt($profile),
            'topics' => self::topicsPrompt($profile, $hasProfile),
            'write' => self::writePrompt($profile, $hasProfile),
            default => 'You are a helpful AI assistant.',
        };
    }

    public static function tools(string $type): array
    {
        return match ($type) {
            'brand' => [
                BrandIntelligenceToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
            default => [],
        };
    }

    private static function buildProfileJson(Team $team): string
    {
        $team->loadMissing(['brandPositioning', 'voiceProfile']);

        $profile = [
            'setup' => [
                'homepage_url' => $team->homepage_url,
                'blog_url' => $team->blog_url,
                'brand_description' => $team->brand_description,
                'product_urls' => $team->product_urls,
                'competitor_urls' => $team->competitor_urls,
                'style_reference_urls' => $team->style_reference_urls,
                'target_audience' => $team->target_audience,
                'tone_keywords' => $team->tone_keywords,
                'content_language' => $team->content_language,
            ],
            'positioning' => $team->brandPositioning?->only([
                'value_proposition', 'target_market', 'differentiators',
                'core_problems', 'products_services', 'primary_cta',
            ]),
            'personas' => $team->audiencePersonas->map(fn ($p) => $p->only([
                'label', 'role', 'description', 'pain_points', 'push', 'pull', 'anxiety',
            ]))->toArray(),
            'voice' => $team->voiceProfile?->only([
                'voice_analysis', 'content_types', 'should_avoid',
                'should_use', 'style_inspiration', 'preferred_length',
            ]),
        ];

        return json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function brandPrompt(string $profile): string
    {
        return <<<PROMPT
        You are a brand strategist helping build a comprehensive brand intelligence profile. Your goal is to deeply understand the user's brand through conversation and research.

        You have two tools:
        - `update_brand_intelligence`: Save structured brand data (setup, positioning, personas, voice). Use this to persist what you learn.
        - `fetch_url`: Read web pages to analyze the brand's online presence.

        Strategy:
        1. Start by asking the user for their website URL if not already provided.
        2. Fetch and analyze their website to understand the business.
        3. Ask targeted follow-up questions about positioning, audience, and voice.
        4. After gathering enough information, use update_brand_intelligence to save your analysis.
        5. Explain what you saved and ask if anything needs adjustment.

        Always save data incrementally — don't wait until the end. Save each section as you complete it.

        Current brand profile:
        {$profile}
        PROMPT;
    }

    private static function topicsPrompt(string $profile, bool $hasProfile): string
    {
        $nudge = $hasProfile ? '' : <<<'NUDGE'

        IMPORTANT: The brand profile is mostly empty. Before brainstorming topics, suggest the user starts with "Build brand knowledge" to establish their positioning and audience first. You can still brainstorm if they insist, but the results will be more generic without brand context.
        NUDGE;

        return <<<PROMPT
        You are a content strategist helping brainstorm content topics. Generate ideas that align with the brand's positioning, resonate with target personas, and match the brand voice.

        Consider:
        - Topics that address audience pain points
        - Content gaps competitors haven't covered
        - Trending themes in the brand's industry
        - Different content formats (blog, social, email, video)
        {$nudge}

        Current brand profile:
        {$profile}
        PROMPT;
    }

    private static function writePrompt(string $profile, bool $hasProfile): string
    {
        $nudge = $hasProfile ? '' : <<<'NUDGE'

        IMPORTANT: The brand profile is mostly empty. Without positioning, audience, and voice data, the content will be generic. Suggest the user starts with "Build brand knowledge" first for better results. You can still write if they insist.
        NUDGE;

        return <<<PROMPT
        You are a skilled copywriter helping create content. Write in the brand's voice, targeting the specified audience personas. Follow the voice profile guidelines for tone, style, and length.

        When writing:
        - Match the brand voice (avoid phrases listed in "should_avoid", use phrases from "should_use")
        - Address the pain points and motivations of the target persona
        - Stay aligned with the brand's positioning and value proposition
        - Aim for the preferred content length when specified
        {$nudge}

        Current brand profile:
        {$profile}
        PROMPT;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
sail test tests/Unit/Services/ChatPromptBuilderTest.php
```

Expected: All 7 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ChatPromptBuilder.php tests/Unit/Services/ChatPromptBuilderTest.php
git commit -m "feat: add ChatPromptBuilder with per-type system prompts"
```

---

### Task 6: Rewrite chat page with type selection and tool UI

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php` — full rewrite

- [ ] **Step 1: Rewrite the chat page component**

Replace the entire content of `resources/views/pages/teams/⚡create-chat.blade.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\ChatPromptBuilder;
use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public Conversation $conversation;

    public string $prompt = '';

    public bool $isStreaming = false;

    public array $messages = [];

    public array $toolActivity = [];

    public int $webSearchRequests = 0;

    public int $lastTokens = 0;

    public float $lastCost = 0;

    public function mount(Team $current_team, Conversation $conversation): void
    {
        $this->teamModel = $current_team;
        $this->conversation = $conversation;
        $this->loadMessages();
    }

    public function selectType(string $type): void
    {
        $this->conversation->update(['type' => $type]);
        $this->conversation->refresh();
    }

    public function submitPrompt(): void
    {
        $content = trim($this->prompt);

        if ($content === '' || $this->isStreaming || ! $this->conversation->type) {
            return;
        }

        if (! $this->teamModel->openrouter_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('OpenRouter API key required. Add it in Team Settings.'));
            return;
        }

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => $content,
        ]);

        if ($this->conversation->title === __('New conversation')) {
            $this->conversation->update(['title' => mb_substr($content, 0, 80)]);
        }

        $this->messages[] = ['role' => 'user', 'content' => $content];
        $this->prompt = '';
        $this->isStreaming = true;
        $this->toolActivity = [];
        $this->webSearchRequests = 0;
        $this->lastTokens = 0;
        $this->lastCost = 0;

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        $type = $this->conversation->type;
        $systemPrompt = ChatPromptBuilder::build($type, $this->teamModel);
        $tools = ChatPromptBuilder::tools($type);

        $apiMessages = collect($this->messages)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->toArray();

        $client = new OpenRouterClient(
            apiKey: $this->teamModel->openrouter_api_key,
            model: $this->teamModel->fast_model,
            urlFetcher: new UrlFetcher,
        );

        $brandHandler = new BrandIntelligenceToolHandler;
        $team = $this->teamModel;

        $toolExecutor = function (string $name, array $args) use ($brandHandler, $team): string {
            if ($name === 'update_brand_intelligence') {
                return $brandHandler->execute($team, $args);
            }
            if ($name === 'fetch_url') {
                return (new UrlFetcher)->fetch($args['url'] ?? '');
            }
            return "Unknown tool: {$name}";
        };

        $fullContent = '';
        $streamResult = null;

        try {
            foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor) as $item) {
                if ($item instanceof ToolEvent) {
                    $this->toolActivity[] = [
                        'name' => $item->name,
                        'args' => $item->arguments,
                        'status' => $item->status,
                        'result' => $item->result,
                    ];
                    // Stream a tool status update to the UI
                    $statusHtml = $this->renderToolStatus($item);
                    $this->stream(to: 'tool-activity', content: $statusHtml, replace: true);
                } elseif ($item instanceof StreamResult) {
                    $streamResult = $item;
                } else {
                    $fullContent .= $item;
                    $this->stream(to: 'streamed-response', content: $fullContent, replace: true);
                }
            }
        } catch (\Throwable $e) {
            $fullContent = 'Sorry, something went wrong. Please try again.';
            $this->stream(to: 'streamed-response', content: $fullContent, replace: true);
        }

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => $fullContent,
            'model' => $this->teamModel->fast_model,
            'input_tokens' => $streamResult?->inputTokens ?? 0,
            'output_tokens' => $streamResult?->outputTokens ?? 0,
            'cost' => $streamResult?->cost ?? 0,
        ]);

        $this->messages[] = ['role' => 'assistant', 'content' => $fullContent];
        $this->webSearchRequests = $streamResult?->webSearchRequests ?? 0;
        $this->lastTokens = ($streamResult?->inputTokens ?? 0) + ($streamResult?->outputTokens ?? 0);
        $this->lastCost = $streamResult?->cost ?? 0;
        $this->isStreaming = false;
    }

    public function render()
    {
        return $this->view()->title($this->conversation->title);
    }

    private function loadMessages(): void
    {
        $this->messages = $this->conversation->messages
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }

    private function renderToolStatus(ToolEvent $event): string
    {
        $items = '';
        foreach ($this->toolActivity as $activity) {
            if ($activity['name'] === 'fetch_url') {
                $url = $activity['args']['url'] ?? '';
                $icon = $activity['status'] === 'completed' ? '✓' : '⟳';
                $items .= "<div class=\"text-xs text-zinc-400\">{$icon} Reading {$url}</div>";
            } elseif ($activity['name'] === 'update_brand_intelligence') {
                if ($activity['status'] === 'completed') {
                    $result = json_decode($activity['result'] ?? '{}', true);
                    $sections = implode(', ', $result['sections'] ?? []);
                    $items .= "<div class=\"text-xs text-zinc-400\">✓ Updated brand profile: {$sections}</div>";
                } else {
                    $items .= "<div class=\"text-xs text-zinc-400\">⟳ Updating brand profile...</div>";
                }
            }
        }
        return $items;
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('create')" wire:navigate />
            <flux:heading size="lg">{{ $conversation->title }}</flux:heading>
            @if ($conversation->type)
                <flux:badge variant="pill" size="sm">{{ match($conversation->type) {
                    'brand' => __('Brand Knowledge'),
                    'topics' => __('Brainstorm'),
                    'write' => __('Write'),
                    default => $conversation->type,
                } }}</flux:badge>
            @endif
        </div>
    </div>

    {{-- Messages --}}
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="mb-6">
                    <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                    {{-- Tool activity --}}
                    <div class="mb-2 space-y-1" wire:stream="tool-activity"></div>
                    {{-- Streamed text --}}
                    <div class="text-sm whitespace-pre-wrap" wire:stream="streamed-response">
                        <span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span>
                    </div>
                </div>
            @endif

            {{-- Message history (reversed for flex-col-reverse) --}}
            @foreach (array_reverse($messages) as $index => $message)
                @if ($message['role'] === 'user')
                    <div class="mb-6 flex justify-end">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-2.5 dark:bg-zinc-700">
                            <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                @else
                    <div class="mb-6">
                        <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                        <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>
                        {{-- Metadata on last assistant message --}}
                        @if ($index === 0 && !$isStreaming && ($webSearchRequests > 0 || $lastTokens > 0))
                            <div class="mt-2 flex items-center gap-2">
                                @if ($webSearchRequests > 0)
                                    <flux:text class="text-xs text-zinc-500">{{ $webSearchRequests }} {{ __('web searches') }}</flux:text>
                                    <flux:text class="text-xs text-zinc-500">&middot;</flux:text>
                                @endif
                                @if ($lastTokens > 0)
                                    <flux:text class="text-xs text-zinc-500">{{ number_format($lastTokens) }} {{ __('tokens') }}</flux:text>
                                @endif
                                @if ($lastCost > 0)
                                    <flux:text class="text-xs text-zinc-500">&middot; ${{ number_format($lastCost, 4) }}</flux:text>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach

            {{-- Type selection (no type yet, no messages) --}}
            @if (!$conversation->type && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose a mode to get started.') }}</flux:subheading>

                    <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-3">
                        <button wire:click="selectType('brand')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="building-storefront" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Build brand knowledge') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Improve copywriting performance with deep brand understanding') }}</flux:text>
                        </button>

                        <button wire:click="selectType('topics')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="light-bulb" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Brainstorm topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Generate fresh content ideas for your audience') }}</flux:text>
                        </button>

                        <button wire:click="selectType('write')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="pencil-square" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Write content') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Draft blog posts, social copy, emails, and more') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Input (only shown after type is selected) --}}
    @if ($conversation->type)
        <div class="mx-auto w-full max-w-3xl px-6 pb-4 pt-2">
            <form wire:submit="submitPrompt">
                <flux:composer
                    wire:model="prompt"
                    submit="enter"
                    label="Message"
                    label:sr-only
                    placeholder="{{ __('Type your message...') }}"
                    rows="1"
                    max-rows="6"
                >
                    <x-slot name="actionsTrailing">
                        <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" />
                    </x-slot>
                </flux:composer>
            </form>
        </div>
    @endif
</div>
```

- [ ] **Step 2: Test in browser**

1. Create a new conversation
2. Verify 3 type cards appear, NO composer input
3. Click "Build brand knowledge" → type badge appears in header, composer appears
4. Send a message → AI responds with brand-focused questions
5. Verify tool activity cards appear when AI fetches URLs or updates profile
6. Verify metadata line shows after response completes

- [ ] **Step 3: Commit**

```bash
git add "resources/views/pages/teams/⚡create-chat.blade.php"
git commit -m "feat: rewrite chat page with type selection, tool UI, and metadata"
```

---

### Task 7: Update conversation list to show type

**Files:**
- Modify: `resources/views/pages/teams/⚡create.blade.php`

- [ ] **Step 1: Add type badge to conversation list items**

In `resources/views/pages/teams/⚡create.blade.php`, find the conversation card section and update the heading area to include the type:

Replace:
```blade
<flux:heading class="truncate">{{ $conversation->title }}</flux:heading>
```

With:
```blade
<div class="flex items-center gap-2">
    <flux:heading class="truncate">{{ $conversation->title }}</flux:heading>
    @if ($conversation->type)
        <flux:badge variant="pill" size="sm" class="shrink-0">{{ match($conversation->type) {
            'brand' => __('Brand'),
            'topics' => __('Topics'),
            'write' => __('Write'),
            default => $conversation->type,
        } }}</flux:badge>
    @endif
</div>
```

- [ ] **Step 2: Commit**

```bash
git add "resources/views/pages/teams/⚡create.blade.php"
git commit -m "feat: show conversation type badge in list"
```

---

### Task 8: Merge Brand Setup into Brand Intelligence page

**Files:**
- Modify: `resources/views/pages/teams/⚡brand-intelligence.blade.php` — absorb brand setup fields
- Delete: `resources/views/pages/teams/⚡brand-setup.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php` — remove Brand Setup item
- Modify: `routes/web.php` — remove brand.setup route

- [ ] **Step 1: Add brand setup fields to the Brand Intelligence component PHP**

In the PHP section of `⚡brand-intelligence.blade.php`, add the brand setup form fields (homepageUrl, blogUrl, brandDescription, productUrls, etc.) to the component class. Copy the properties and methods from `⚡brand-setup.blade.php`:

Add these properties after the existing ones:

```php
public string $homepageUrl = '';
public string $blogUrl = '';
public string $brandDescription = '';
public array $productUrls = [];
public array $competitorUrls = [];
public array $styleReferenceUrls = [];
public string $targetAudience = '';
public string $toneKeywords = '';
public string $contentLanguage = 'English';
public bool $editingSetup = false;
```

In `mount()`, add after existing setup:

```php
$this->homepageUrl = $current_team->homepage_url ?? '';
$this->blogUrl = $current_team->blog_url ?? '';
$this->brandDescription = $current_team->brand_description ?? '';
$this->productUrls = $current_team->product_urls ?? [];
$this->competitorUrls = $current_team->competitor_urls ?? [];
$this->styleReferenceUrls = $current_team->style_reference_urls ?? [];
$this->targetAudience = $current_team->target_audience ?? '';
$this->toneKeywords = $current_team->tone_keywords ?? '';
$this->contentLanguage = $current_team->content_language ?? 'English';
```

Add `saveSetup()` method:

```php
public function saveSetup(): void
{
    Gate::authorize('update', $this->teamModel);

    $validated = $this->validate([
        'homepageUrl' => ['required', 'url', 'max:255'],
        'blogUrl' => ['nullable', 'url', 'max:255'],
        'brandDescription' => ['nullable', 'string', 'max:5000'],
        'targetAudience' => ['nullable', 'string', 'max:5000'],
        'toneKeywords' => ['nullable', 'string', 'max:255'],
        'contentLanguage' => ['nullable', 'string', 'max:50'],
    ]);

    $this->teamModel->update([
        'homepage_url' => $validated['homepageUrl'],
        'blog_url' => $validated['blogUrl'] ?: null,
        'brand_description' => $validated['brandDescription'] ?: null,
        'target_audience' => $validated['targetAudience'] ?: null,
        'tone_keywords' => $validated['toneKeywords'] ?: null,
        'content_language' => $validated['contentLanguage'] ?: 'English',
    ]);

    $this->editingSetup = false;
    Flux::toast(variant: 'success', text: __('Company info saved.'));
}

public function startEditingSetup(): void
{
    $this->editingSetup = true;
}

public function cancelEditingSetup(): void
{
    $this->editingSetup = false;
}
```

- [ ] **Step 2: Add the Company section to the Blade template**

In the template section, add a "Company" section as the FIRST section (before Positioning). Place it right after the page heading/subheading:

```blade
{{-- Section 1: Company --}}
<flux:separator variant="subtle" class="my-8" />

<div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
    <div class="w-80">
        <flux:heading size="lg">{{ __('Company') }}</flux:heading>
        <flux:subheading>{{ __('Your company\'s online presence and identity.') }}</flux:subheading>
    </div>

    <div class="flex-1 space-y-6">
        @if ($editingSetup)
            <flux:input wire:model="homepageUrl" label="Homepage URL" type="url" placeholder="https://yourcompany.com" required />
            <flux:input wire:model="blogUrl" label="Blog URL" type="url" placeholder="https://yourcompany.com/blog" />
            <flux:textarea wire:model="brandDescription" label="Brand Description" rows="3" placeholder="What your company does..." />
            <flux:textarea wire:model="targetAudience" label="Target Audience" rows="2" placeholder="Who you serve..." />
            <flux:input wire:model="toneKeywords" label="Tone Keywords" placeholder="Professional, approachable, concise" />
            <flux:input wire:model="contentLanguage" label="Content Language" placeholder="English" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelEditingSetup">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveSetup">{{ __('Save') }}</flux:button>
            </div>
        @else
            @foreach ([
                'homepage_url' => ['Homepage URL', $teamModel->homepage_url],
                'blog_url' => ['Blog URL', $teamModel->blog_url],
                'brand_description' => ['Brand Description', $teamModel->brand_description],
                'target_audience' => ['Target Audience', $teamModel->target_audience],
                'tone_keywords' => ['Tone Keywords', $teamModel->tone_keywords],
                'content_language' => ['Content Language', $teamModel->content_language],
            ] as $field => [$label, $value])
                <div>
                    <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                    <flux:text class="mt-1">{{ $value ?: '—' }}</flux:text>
                </div>
            @endforeach

            @if ($this->permissions->canUpdateTeam)
                <div class="flex justify-end">
                    <flux:button variant="subtle" size="sm" wire:click="startEditingSetup">{{ __('Edit') }}</flux:button>
                </div>
            @endif
        @endif
    </div>
</div>
```

- [ ] **Step 3: Remove the "Generate" button and prerequisite warnings**

Remove the prerequisite warnings section and the generate button card from the template. Replace with a subtle CTA linking to the chat:

```blade
{{-- CTA to build via chat --}}
@if (! $hasPositioning && ! $hasPersonas && ! $hasVoiceProfile)
    <flux:separator variant="subtle" class="my-8" />

    <flux:card class="text-center">
        <div class="space-y-3 py-4">
            <flux:text>{{ __('Use the AI chat to analyze your brand and populate your positioning, personas, and voice profile.') }}</flux:text>
            <flux:button variant="primary" icon="chat-bubble-left-right" :href="route('create')" wire:navigate>
                {{ __('Start building brand knowledge') }}
            </flux:button>
        </div>
    </flux:card>
@endif
```

- [ ] **Step 4: Make all sections always visible**

Change the `@if` conditions on Positioning, Personas, and Voice sections from conditional to always showing with empty states:

For each section, change `@if ($hasPositioning || $editingPositioning)` to just always render, and add an empty state when no data:

```blade
@if (! $hasPositioning && ! $editingPositioning)
    <flux:text class="text-sm text-zinc-500">{{ __('No positioning data yet.') }}</flux:text>
    @if ($this->permissions->canUpdateTeam)
        <flux:button variant="subtle" size="sm" wire:click="startEditingPositioning">{{ __('Add manually') }}</flux:button>
    @endif
@else
    {{-- existing edit/view content --}}
@endif
```

- [ ] **Step 5: Remove the startGeneration method**

Remove the `startGeneration()` method and the `$missingPrerequisites`, `$missingItems` properties and `checkPrerequisites()` method from the PHP class. The generate flow is now handled by the chat.

- [ ] **Step 6: Delete Brand Setup page**

```bash
rm "resources/views/pages/teams/⚡brand-setup.blade.php"
```

- [ ] **Step 7: Remove Brand Setup sidebar item**

In `resources/views/layouts/app/sidebar.blade.php`, remove:

```blade
<flux:sidebar.item icon="building-storefront" :href="route('brand.setup')" :current="request()->routeIs('brand.setup')" wire:navigate>
    {{ __('Brand Setup') }}
</flux:sidebar.item>
```

- [ ] **Step 8: Remove brand.setup route**

In `routes/web.php`, remove:

```php
Route::livewire('brand', 'pages::teams.brand-setup')->name('brand.setup');
```

- [ ] **Step 9: Run tests**

```bash
sail test
```

Expected: All tests pass. If any tests reference `brand.setup` route, update them.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: merge Brand Setup into Brand Intelligence, remove Brand Setup page"
```

---

### Task 9: Final verification

- [ ] **Step 1: Run full test suite**

```bash
sail test
```

Expected: All tests pass.

- [ ] **Step 2: Browser testing checklist**

Verify:
- [ ] Brand Intelligence page shows all sections (Company, Positioning, Personas, Voice)
- [ ] Empty sections show empty state with "Add manually" button
- [ ] All inline editing works (Company, Positioning, Voice, Personas)
- [ ] Brand Setup sidebar item is gone
- [ ] Create → New conversation → 3 type cards, no composer
- [ ] Selecting "Build brand knowledge" → badge appears, composer appears
- [ ] Sending a message → AI responds with brand-focused questions
- [ ] AI fetches URLs → tool activity card shows "Reading https://..."
- [ ] AI updates brand profile → tool activity card shows "Updated brand profile: positioning"
- [ ] After response: metadata line shows web searches, tokens, cost
- [ ] Selecting "Brainstorm topics" with empty profile → AI nudges toward brand knowledge first
- [ ] Selecting "Write content" with empty profile → same nudge
- [ ] Conversation list shows type badges

- [ ] **Step 3: Commit any fixes**

```bash
git add -A
git commit -m "fix: polish brand intelligence consolidation"
```
