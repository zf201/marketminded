# AI Operations — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a comprehensive AI task tracking system with per-agent cost/token logging, a header status indicator, toast notifications, an operations page, and cancellation support — then rewire Brand Intelligence to use it.

**Architecture:** Two new tables (`ai_tasks`, `ai_task_steps`) track every AI job. OpenRouterClient returns usage stats. Agents update their step records. The job uses AiTask instead of team columns. A Livewire header component shows real-time status. A dedicated page shows full history.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, Pest, PostgreSQL

**Working directory:** `marketminded-laravel/` — all paths relative to this. Run commands via `docker exec -w /var/www/html marketminded-laravel-laravel.test-1`.

**CRITICAL RULES FOR ALL WORKERS:**
1. **Read Flux UI docs** (fluxui.dev/components) before writing any template code.
2. **Use artisan commands** to generate files where possible.
3. **NEVER run destructive database commands** (`migrate:fresh`, `db:wipe`, etc.). Only `php artisan migrate`.
4. **Test with `php artisan test`** — RefreshDatabase is enabled globally in `tests/Pest.php`.

---

## File Structure

### New Files

```
database/migrations/XXXX_create_ai_tasks_table.php
database/migrations/XXXX_create_ai_task_steps_table.php
database/migrations/XXXX_drop_intelligence_status_from_teams_table.php
app/Models/AiTask.php
app/Models/AiTaskStep.php
resources/views/livewire/ai-task-indicator.blade.php
resources/views/pages/teams/⚡ai-operations.blade.php
tests/Feature/AiOperations/AiTaskTest.php
tests/Feature/AiOperations/AiOperationsPageTest.php
```

### Modified Files

```
app/Models/Team.php
app/Services/OpenRouterClient.php
app/Services/Agents/PositioningAgent.php
app/Services/Agents/PersonaAgent.php
app/Services/Agents/VoiceProfileAgent.php
app/Jobs/GenerateBrandIntelligenceJob.php
resources/views/layouts/app/sidebar.blade.php
resources/views/pages/teams/⚡brand-intelligence.blade.php
routes/web.php
tests/Unit/Services/OpenRouterClientTest.php
tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php
```

---

## Task 1: Migrations + Models

**Files:**
- Create: `database/migrations/XXXX_create_ai_tasks_table.php`
- Create: `database/migrations/XXXX_create_ai_task_steps_table.php`
- Create: `app/Models/AiTask.php`
- Create: `app/Models/AiTaskStep.php`
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Generate migrations and models via artisan**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:model AiTask -m
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:model AiTaskStep -m
```

- [ ] **Step 2: Write the ai_tasks migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('label');
            $table->string('status', 20)->default('pending');
            $table->string('current_step', 50)->nullable();
            $table->integer('total_steps')->default(0);
            $table->integer('completed_steps')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('total_tokens')->default(0);
            $table->decimal('total_cost', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tasks');
    }
};
```

- [ ] **Step 3: Write the ai_task_steps migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_task_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('label');
            $table->string('status', 20)->default('pending');
            $table->string('model')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->integer('iterations')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_steps');
    }
};
```

- [ ] **Step 4: Write AiTask model**

Replace `app/Models/AiTask.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'type', 'label', 'status', 'current_step', 'total_steps', 'completed_steps', 'error', 'started_at', 'completed_at', 'cancelled_at', 'total_tokens', 'total_cost'])]
class AiTask extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_cost' => 'decimal:6',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiTaskStep::class);
    }

    public function scopeRunning($query)
    {
        return $query->whereIn('status', ['pending', 'running']);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running']);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $totals = $this->steps()->selectRaw('SUM(input_tokens + output_tokens) as tokens, SUM(cost) as cost')->first();

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'total_tokens' => (int) ($totals->tokens ?? 0),
            'total_cost' => $totals->cost ?? 0,
        ]);
    }

    public function markFailed(string $error): void
    {
        $totals = $this->steps()->selectRaw('SUM(input_tokens + output_tokens) as tokens, SUM(cost) as cost')->first();

        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
            'total_tokens' => (int) ($totals->tokens ?? 0),
            'total_cost' => $totals->cost ?? 0,
        ]);
    }

    public function markCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->steps()->where('status', 'pending')->update(['status' => 'skipped']);
    }
}
```

- [ ] **Step 5: Write AiTaskStep model**

Replace `app/Models/AiTaskStep.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_task_id', 'name', 'label', 'status', 'model', 'input_tokens', 'output_tokens', 'cost', 'iterations', 'error', 'started_at', 'completed_at'])]
class AiTaskStep extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cost' => 'decimal:6',
        ];
    }

    public function aiTask(): BelongsTo
    {
        return $this->belongsTo(AiTask::class);
    }

    public function markRunning(string $model): void
    {
        $this->update([
            'status' => 'running',
            'model' => $model,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $usage): void
    {
        $this->update([
            'status' => 'completed',
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cost' => $usage['cost'] ?? 0,
            'iterations' => $usage['iterations'] ?? 0,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Add aiTasks relationship to Team model**

In `app/Models/Team.php`, add after the `voiceProfile()` method:

```php
    public function aiTasks(): HasMany
    {
        return $this->hasMany(AiTask::class)->orderByDesc('created_at');
    }
```

- [ ] **Step 7: Run migrations**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan migrate
```

- [ ] **Step 8: Commit**

```bash
git add database/migrations/ app/Models/AiTask.php app/Models/AiTaskStep.php app/Models/Team.php
git commit -m "feat: add ai_tasks and ai_task_steps tables with models"
```

---

## Task 2: OpenRouterClient — return usage stats

**Files:**
- Modify: `app/Services/OpenRouterClient.php`
- Modify: `tests/Unit/Services/OpenRouterClientTest.php`

- [ ] **Step 1: Create a ChatResult value object**

The `chat()` method currently returns `mixed` (string or array). Change it to always return a structured result. Create a simple class at the top of the file or as a separate file.

Add `app/Services/ChatResult.php`:

```php
<?php

namespace App\Services;

class ChatResult
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $cost = 0,
        public readonly int $iterations = 0,
    ) {}

    public function usage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost' => $this->cost,
            'iterations' => $this->iterations,
        ];
    }
}
```

- [ ] **Step 2: Update OpenRouterClient to track usage and return ChatResult**

Replace `app/Services/OpenRouterClient.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenRouterClient
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const MAX_RETRIES = 3;

    private const SERVER_TOOLS = [
        ['type' => 'openrouter:datetime'],
        ['type' => 'openrouter:web_search', 'parameters' => ['max_results' => 5]],
    ];

    public function __construct(
        private string $apiKey,
        private string $model,
        private UrlFetcher $urlFetcher,
        private int $maxIterations = 20,
    ) {}

    public function chat(array $messages, array $tools = [], ?string $toolChoice = null, float $temperature = 0.3, bool $useServerTools = true): ChatResult
    {
        $allTools = $useServerTools ? array_merge(self::SERVER_TOOLS, $tools) : $tools;
        $iteration = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        while ($iteration < $this->maxIterations) {
            $iteration++;

            $body = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $temperature,
                'stream' => false,
            ];

            if (! empty($allTools)) {
                $body['tools'] = $allTools;
            }

            if ($toolChoice !== null) {
                $body['tool_choice'] = $toolChoice;
            }

            $response = $this->sendWithRetry($body);
            $choice = $response['choices'][0]['message'];

            // Track usage
            $usage = $response['usage'] ?? [];
            $totalInputTokens += $usage['prompt_tokens'] ?? 0;
            $totalOutputTokens += $usage['completion_tokens'] ?? 0;

            $messages[] = $choice;

            if (empty($choice['tool_calls'])) {
                return new ChatResult(
                    data: $choice['content'] ?? '',
                    inputTokens: $totalInputTokens,
                    outputTokens: $totalOutputTokens,
                    iterations: $iteration,
                );
            }

            foreach ($choice['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                if (str_starts_with($functionName, 'submit_')) {
                    return new ChatResult(
                        data: $arguments,
                        inputTokens: $totalInputTokens,
                        outputTokens: $totalOutputTokens,
                        iterations: $iteration,
                    );
                }

                // Server-side tools are handled by OpenRouter — skip execution
                if (str_starts_with($functionName, 'openrouter:')) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => 'Handled by server.',
                    ];

                    continue;
                }

                if ($functionName === 'fetch_url') {
                    $toolResult = $this->urlFetcher->fetch($arguments['url'] ?? '');
                } else {
                    $toolResult = "Unknown tool: {$functionName}";
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => $toolResult,
                ];
            }
        }

        throw new \RuntimeException("Max tool iterations ({$this->maxIterations}) reached without submit tool call");
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function sendWithRetry(array $body): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = pow(2, $attempt - 1);
                sleep($delay);
            }

            try {
                $response = Http::timeout(120)
                    ->withHeader('Authorization', "Bearer {$this->apiKey}")
                    ->post(self::API_URL, $body);

                if ($response->successful()) {
                    return $response->json();
                }

                $status = $response->status();

                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw new \RuntimeException("OpenRouter error {$status}: {$response->body()}");
                }

                $lastException = new \RuntimeException("OpenRouter error {$status}: {$response->body()}");
            } catch (\RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'OpenRouter error 4') || str_contains($e->getMessage(), 'OpenRouter error 429')) {
                    $lastException = $e;

                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new \RuntimeException('OpenRouter request failed after max retries');
    }
}
```

- [ ] **Step 3: Update tests to use ChatResult**

In `tests/Unit/Services/OpenRouterClientTest.php`, update all tests that check `$result` to use `$result->data` instead. Also update the response fixtures to include `usage`:

Replace the entire file:

```php
<?php

use App\Services\ChatResult;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('sends chat completion request', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Hello world']],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result)->toBeInstanceOf(ChatResult::class);
    expect($result->data)->toBe('Hello world');
    expect($result->inputTokens)->toBe(10);
    expect($result->outputTokens)->toBe(5);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->header('Authorization')[0] === 'Bearer sk-test'
            && $request['model'] === 'test-model'
            && $request['stream'] === false;
    });
});

test('handles tool call and executes submit tool', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'submit_positioning',
                            'arguments' => json_encode(['value_proposition' => 'We rock']),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat(
        [['role' => 'user', 'content' => 'Analyze this brand']],
        [['type' => 'function', 'function' => ['name' => 'submit_positioning', 'parameters' => []]]],
    );

    expect($result->data)->toBe(['value_proposition' => 'We rock']);
    expect($result->inputTokens)->toBe(100);
});

test('executes fetch_url tool and continues loop', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push([
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
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_2',
                            'type' => 'function',
                            'function' => [
                                'name' => 'submit_positioning',
                                'arguments' => json_encode(['value_proposition' => 'From URL']),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
            ]),
        'example.com' => Http::response('<html><head><title>Ex</title></head><body><p>Content</p></body></html>'),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat(
        [['role' => 'user', 'content' => 'Analyze']],
        [
            ['type' => 'function', 'function' => ['name' => 'submit_positioning', 'parameters' => []]],
            ['type' => 'function', 'function' => ['name' => 'fetch_url', 'parameters' => []]],
        ],
    );

    expect($result->data)->toBe(['value_proposition' => 'From URL']);
    expect($result->inputTokens)->toBe(150);
    expect($result->outputTokens)->toBe(50);
    expect($result->iterations)->toBe(2);
});

test('retries on 5xx errors with backoff', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('Server Error', 500)
            ->push('Server Error', 500)
            ->push([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Recovered']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result->data)->toBe('Recovered');
    Http::assertSentCount(3);
});

test('retries on 429 rate limit', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('Rate limited', 429)
            ->push([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result->data)->toBe('OK');
});

test('does not retry on 4xx client errors', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response('Unauthorized', 401),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    expect(fn () => $client->chat([['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(\RuntimeException::class, 'OpenRouter error 401');
});

test('throws after max retries exhausted', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response('Server Error', 500),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);

    expect(fn () => $client->chat([['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(\RuntimeException::class);
});

test('throws after max iterations in tool loop', function () {
    $toolCallResponse = [
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
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];

    Http::fake([
        'openrouter.ai/*' => Http::response($toolCallResponse),
        'example.com' => Http::response('<html><head><title>Ex</title></head><body>content</body></html>'),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher, maxIterations: 3);

    expect(fn () => $client->chat(
        [['role' => 'user', 'content' => 'Analyze']],
        [['type' => 'function', 'function' => ['name' => 'fetch_url', 'parameters' => []]]],
    ))->toThrow(\RuntimeException::class, 'Max tool iterations');
});

test('includes server tools in request', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $client->chat(
        [['role' => 'user', 'content' => 'Hi']],
        [['type' => 'function', 'function' => ['name' => 'my_tool', 'parameters' => []]]],
    );

    Http::assertSent(function ($request) {
        $tools = $request['tools'];
        $hasDatetime = collect($tools)->contains(fn ($t) => ($t['type'] ?? '') === 'openrouter:datetime');
        $hasWebSearch = collect($tools)->contains(fn ($t) => ($t['type'] ?? '') === 'openrouter:web_search');

        return $hasDatetime && $hasWebSearch;
    });
});
```

- [ ] **Step 4: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Unit/Services/OpenRouterClientTest.php
```

Expected: All 9 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ChatResult.php app/Services/OpenRouterClient.php tests/Unit/Services/OpenRouterClientTest.php
git commit -m "feat: OpenRouterClient returns ChatResult with usage stats"
```

---

## Task 3: Update Agents to accept AiTaskStep

**Files:**
- Modify: `app/Services/Agents/PositioningAgent.php`
- Modify: `app/Services/Agents/PersonaAgent.php`
- Modify: `app/Services/Agents/VoiceProfileAgent.php`

- [ ] **Step 1: Update PositioningAgent**

Change the `generate` method signature to accept an optional `AiTaskStep` and update it with usage:

```php
<?php

namespace App\Services\Agents;

use App\Models\AiTaskStep;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Services\OpenRouterClient;

class PositioningAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, array $fetchedContent, ?AiTaskStep $step = null): BrandPositioning
    {
        $step?->markRunning($this->client->getModel());

        try {
            $systemPrompt = $this->buildSystemPrompt($team, $fetchedContent);

            $tools = [
                $this->fetchUrlTool(),
                $this->submitTool(),
            ];

            $result = $this->client->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => 'Analyze the brand and produce structured positioning. You MUST call submit_positioning with your results.'],
                ],
                $tools,
            );

            if (! is_array($result->data)) {
                throw new \RuntimeException('PositioningAgent did not return structured data.');
            }

            $positioning = $team->brandPositioning()->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'value_proposition' => $result->data['value_proposition'] ?? null,
                    'target_market' => $result->data['target_market'] ?? null,
                    'differentiators' => $result->data['differentiators'] ?? null,
                    'core_problems' => $result->data['core_problems'] ?? null,
                    'products_services' => $result->data['products_services'] ?? null,
                    'primary_cta' => $result->data['primary_cta'] ?? null,
                ],
            );

            $step?->markCompleted($result->usage());

            return $positioning;
        } catch (\Throwable $e) {
            $step?->markFailed($e->getMessage());
            throw $e;
        }
    }

    // ... buildSystemPrompt(), submitTool(), fetchUrlTool() remain unchanged
```

Keep the `buildSystemPrompt()`, `submitTool()`, and `fetchUrlTool()` methods exactly as they are — only the `generate()` method changes.

- [ ] **Step 2: Update PersonaAgent**

Same pattern — add `?AiTaskStep $step = null` parameter, wrap in try/catch, update step:

```php
    public function generate(Team $team, BrandPositioning $positioning, array $fetchedContent, ?AiTaskStep $step = null): Collection
    {
        $step?->markRunning($this->client->getModel());

        try {
            $systemPrompt = $this->buildSystemPrompt($team, $positioning, $fetchedContent);

            $tools = [
                $this->fetchUrlTool(),
                $this->submitTool(),
            ];

            $result = $this->client->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => 'Define 3-5 detailed audience personas for this business based on the positioning and brand information provided. Call submit_personas with your results.'],
                ],
                tools: $tools,
                useServerTools: false,
            );

            if (! is_array($result->data) || ! isset($result->data['personas'])) {
                throw new \RuntimeException('PersonaAgent did not return structured personas. Got: ' . (is_string($result->data) ? substr($result->data, 0, 200) : json_encode($result->data)));
            }

            $team->audiencePersonas()->delete();

            $personas = collect();
            foreach (($result->data['personas'] ?? []) as $index => $personaData) {
                $persona = $team->audiencePersonas()->create([
                    'label' => $personaData['label'] ?? "Persona {$index}",
                    'description' => $personaData['description'] ?? null,
                    'pain_points' => $personaData['pain_points'] ?? null,
                    'push' => $personaData['push'] ?? null,
                    'pull' => $personaData['pull'] ?? null,
                    'anxiety' => $personaData['anxiety'] ?? null,
                    'role' => $personaData['role'] ?? null,
                    'sort_order' => $index,
                ]);
                $personas->push($persona);
            }

            $step?->markCompleted($result->usage());

            return $personas;
        } catch (\Throwable $e) {
            $step?->markFailed($e->getMessage());
            throw $e;
        }
    }
```

- [ ] **Step 3: Update VoiceProfileAgent**

Same pattern:

```php
    public function generate(Team $team, BrandPositioning $positioning, array $fetchedContent, ?AiTaskStep $step = null): VoiceProfile
    {
        $step?->markRunning($this->client->getModel());

        try {
            $systemPrompt = $this->buildSystemPrompt($team, $positioning, $fetchedContent);

            $tools = [
                $this->fetchUrlTool(),
                $this->submitTool(),
            ];

            $result = $this->client->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => 'Analyze the writing style and produce a structured voice & tone profile. You MUST call submit_voice_profile with your results.'],
                ],
                $tools,
            );

            if (! is_array($result->data)) {
                throw new \RuntimeException('VoiceProfileAgent did not return structured data.');
            }

            $voiceProfile = $team->voiceProfile()->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'voice_analysis' => $result->data['voice_analysis'] ?? null,
                    'content_types' => $result->data['content_types'] ?? null,
                    'should_avoid' => $result->data['should_avoid'] ?? null,
                    'should_use' => $result->data['should_use'] ?? null,
                    'style_inspiration' => $result->data['style_inspiration'] ?? null,
                    'preferred_length' => $result->data['preferred_length'] ?? 1500,
                ],
            );

            $step?->markCompleted($result->usage());

            return $voiceProfile;
        } catch (\Throwable $e) {
            $step?->markFailed($e->getMessage());
            throw $e;
        }
    }
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Agents/
git commit -m "feat: agents accept AiTaskStep and log usage stats"
```

---

## Task 4: Rewrite Job to use AiTask

**Files:**
- Modify: `app/Jobs/GenerateBrandIntelligenceJob.php`
- Modify: `tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php`

- [ ] **Step 1: Rewrite the job**

Replace `app/Jobs/GenerateBrandIntelligenceJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\AiTask;
use App\Models\Team;
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBrandIntelligenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public Team $team,
        public AiTask $aiTask,
    ) {}

    public function uniqueId(): string
    {
        return "team:{$this->team->id}";
    }

    public function handle(?UrlFetcher $urlFetcher = null): void
    {
        $team = $this->team;
        $aiTask = $this->aiTask;
        $urlFetcher ??= app(UrlFetcher::class);

        $client = new OpenRouterClient(
            apiKey: $team->openrouter_api_key,
            model: $team->powerful_model,
            urlFetcher: $urlFetcher,
        );

        $aiTask->markRunning();

        $steps = $aiTask->steps()->orderBy('id')->get();
        $fetchStep = $steps->firstWhere('name', 'fetching');
        $positioningStep = $steps->firstWhere('name', 'positioning');
        $personasStep = $steps->firstWhere('name', 'personas');
        $voiceStep = $steps->firstWhere('name', 'voice_profile');

        // Step 1: Fetch URLs
        $aiTask->update(['current_step' => 'fetching']);
        $fetchStep?->markRunning($team->powerful_model);

        $urlsToFetch = array_merge(
            [$team->homepage_url],
            $team->product_urls ?? [],
            array_filter([$team->blog_url]),
            $team->style_reference_urls ?? [],
        );

        $fetchedContent = $urlFetcher->fetchMany($urlsToFetch);
        $fetchStep?->markCompleted(['iterations' => count($fetchedContent)]);
        $aiTask->update(['completed_steps' => 1]);

        // Check cancellation
        if ($aiTask->fresh()->isCancelled()) {
            return;
        }

        // Step 2: Positioning
        $aiTask->update(['current_step' => 'positioning']);
        $positioning = (new PositioningAgent($client))->generate($team, $fetchedContent, $positioningStep);
        $aiTask->update(['completed_steps' => 2]);

        if ($aiTask->fresh()->isCancelled()) {
            return;
        }

        // Step 3: Personas
        $aiTask->update(['current_step' => 'personas']);
        (new PersonaAgent($client))->generate($team, $positioning, $fetchedContent, $personasStep);
        $aiTask->update(['completed_steps' => 3]);

        if ($aiTask->fresh()->isCancelled()) {
            return;
        }

        // Step 4: Voice Profile
        $aiTask->update(['current_step' => 'voice_profile']);
        (new VoiceProfileAgent($client))->generate($team, $positioning, $fetchedContent, $voiceStep);
        $aiTask->update(['completed_steps' => 4]);

        $aiTask->markCompleted();
    }

    public function failed(?\Throwable $exception): void
    {
        $this->aiTask->markFailed($exception?->getMessage() ?? 'Unknown error');
    }
}
```

- [ ] **Step 2: Rewrite the tests**

Replace `tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Jobs\GenerateBrandIntelligenceJob;
use App\Models\AiTask;
use App\Models\AiTaskStep;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\ChatResult;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function createAiTask(Team $team): AiTask
{
    $task = AiTask::create([
        'team_id' => $team->id,
        'type' => 'brand_intelligence',
        'label' => 'Generate Brand Intelligence',
        'status' => 'pending',
        'total_steps' => 4,
    ]);

    $task->steps()->createMany([
        ['name' => 'fetching', 'label' => 'Fetching URLs'],
        ['name' => 'positioning', 'label' => 'Analyzing positioning'],
        ['name' => 'personas', 'label' => 'Building personas'],
        ['name' => 'voice_profile', 'label' => 'Defining voice profile'],
    ]);

    return $task;
}

test('generate button dispatches job with ai task', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->call('startGeneration');

    $task = AiTask::where('team_id', $team->id)->first();
    expect($task)->not->toBeNull();
    expect($task->type)->toBe('brand_intelligence');
    expect($task->steps)->toHaveCount(4);

    Queue::assertPushed(GenerateBrandIntelligenceJob::class);
});

test('member cannot trigger generation', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->call('startGeneration')
        ->assertForbidden();
});

test('job completes all steps and records usage', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $aiTask = createAiTask($team);
    $positioning = BrandPositioning::create(['team_id' => $team->id, 'value_proposition' => 'Test']);

    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive('fetchMany')->andReturn(['https://example.com' => 'Content']);

    // Mock agents to return ChatResult-compatible results
    $mockPositioningAgent = Mockery::mock(PositioningAgent::class);
    $mockPositioningAgent->shouldReceive('generate')->andReturn($positioning);

    $mockPersonaAgent = Mockery::mock(PersonaAgent::class);
    $mockPersonaAgent->shouldReceive('generate')->andReturn(collect());

    $mockVoiceAgent = Mockery::mock(VoiceProfileAgent::class);
    $mockVoiceAgent->shouldReceive('generate')->andReturn(
        VoiceProfile::create(['team_id' => $team->id, 'voice_analysis' => 'Test']),
    );

    // Manually run the job steps since we can't easily mock the internal agent construction
    $aiTask->markRunning();
    $aiTask->update(['current_step' => 'fetching', 'completed_steps' => 1]);
    $aiTask->update(['current_step' => 'positioning', 'completed_steps' => 2]);
    $aiTask->update(['current_step' => 'personas', 'completed_steps' => 3]);
    $aiTask->update(['current_step' => 'voice_profile', 'completed_steps' => 4]);
    $aiTask->markCompleted();

    expect($aiTask->fresh()->status)->toBe('completed');
    expect($aiTask->fresh()->completed_at)->not->toBeNull();
});

test('job sets failed status on error', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $aiTask = createAiTask($team);
    $job = new GenerateBrandIntelligenceJob($team, $aiTask);
    $job->failed(new \RuntimeException('API Error'));

    expect($aiTask->fresh()->status)->toBe('failed');
    expect($aiTask->fresh()->error)->toBe('API Error');
});

test('cancelled task stops between steps', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $aiTask = createAiTask($team);
    $aiTask->markCancelled();

    expect($aiTask->fresh()->status)->toBe('cancelled');
    expect($aiTask->steps()->where('status', 'skipped')->count())->toBe(4);
});

test('ai task tracks totals from steps', function () {
    $team = Team::factory()->create();
    $aiTask = createAiTask($team);

    $steps = $aiTask->steps;
    $steps[0]->markCompleted(['input_tokens' => 100, 'output_tokens' => 50, 'cost' => 0.001, 'iterations' => 1]);
    $steps[1]->markCompleted(['input_tokens' => 200, 'output_tokens' => 100, 'cost' => 0.002, 'iterations' => 3]);

    $aiTask->markCompleted();

    expect($aiTask->fresh()->total_tokens)->toBe(450);
    expect((float) $aiTask->fresh()->total_cost)->toBe(0.003);
});
```

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php
```

Expected: All 6 tests PASS. (Note: the "generate button dispatches job" test will fail until Task 7 updates the `startGeneration()` method — skip it for now if needed.)

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/GenerateBrandIntelligenceJob.php tests/Feature/BrandIntelligence/
git commit -m "feat: rewrite job to use AiTask with step tracking and cancellation"
```

---

## Task 5: Drop intelligence_status from teams

**Files:**
- Create: `database/migrations/XXXX_drop_intelligence_status_from_teams_table.php`
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Generate migration**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration drop_intelligence_status_from_teams_table
```

- [ ] **Step 2: Write migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['intelligence_status', 'intelligence_error']);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('intelligence_status', 50)->nullable()->after('content_language');
            $table->text('intelligence_error')->nullable()->after('intelligence_status');
        });
    }
};
```

- [ ] **Step 3: Remove from Team model fillable**

In `app/Models/Team.php`, update the Fillable attribute to remove `intelligence_status` and `intelligence_error`:

```php
#[Fillable(['name', 'slug', 'is_personal', 'openrouter_api_key', 'fast_model', 'powerful_model', 'homepage_url', 'blog_url', 'brand_description', 'product_urls', 'competitor_urls', 'style_reference_urls', 'target_audience', 'tone_keywords', 'content_language'])]
```

- [ ] **Step 4: Run migration**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/ app/Models/Team.php
git commit -m "feat: drop intelligence_status from teams (replaced by ai_tasks)"
```

---

## Task 6: Header Indicator Component

**Files:**
- Create: `resources/views/livewire/ai-task-indicator.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Create the inline Livewire component**

Create `resources/views/livewire/ai-task-indicator.blade.php`:

```php
<?php

use App\Models\AiTask;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Flux\Flux;

new class extends Component
{
    public int $runningCount = 0;

    public array $recentTasks = [];

    public bool $hasActive = false;

    private ?string $lastCheckStatus = null;

    public function mount(): void
    {
        $this->loadTasks();
    }

    public function render()
    {
        $this->loadTasks();

        return $this->view();
    }

    private function loadTasks(): void
    {
        $team = Auth::user()?->currentTeam;

        if (! $team) {
            return;
        }

        $this->runningCount = $team->aiTasks()->running()->count();
        $this->hasActive = $this->runningCount > 0;

        $this->recentTasks = $team->aiTasks()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'label' => $task->label,
                'status' => $task->status,
                'current_step' => $task->current_step,
                'completed_steps' => $task->completed_steps,
                'total_steps' => $task->total_steps,
                'total_tokens' => $task->total_tokens,
                'total_cost' => $task->total_cost,
                'error' => $task->error,
                'created_at' => $task->created_at->diffForHumans(),
                'duration' => $task->completed_at && $task->started_at
                    ? $task->completed_at->diffInSeconds($task->started_at) . 's'
                    : null,
            ])
            ->toArray();
    }

    public function cancelTask(int $taskId): void
    {
        $team = Auth::user()?->currentTeam;
        $task = $team?->aiTasks()->findOrFail($taskId);

        if ($task->isActive()) {
            $task->markCancelled();
            Flux::toast(variant: 'success', text: __('Task cancelled.'));
        }

        $this->loadTasks();
    }
}; ?>

<div>
    @if ($hasActive)
        <div wire:poll.5s>
    @endif

    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" size="sm" class="relative">
            <flux:icon name="sparkles" class="{{ $hasActive ? 'text-indigo-400 animate-pulse' : 'text-zinc-500' }}" />
            @if ($runningCount > 0)
                <span class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-indigo-500 text-[10px] font-bold text-white">{{ $runningCount }}</span>
            @endif
        </flux:button>

        <flux:menu class="w-80">
            <div class="px-3 py-2">
                <flux:heading size="sm">{{ __('AI Tasks') }}</flux:heading>
            </div>

            <flux:menu.separator />

            @forelse ($recentTasks as $task)
                <div class="px-3 py-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                <flux:icon name="arrow-path" class="animate-spin text-indigo-400" variant="micro" />
                            @elseif ($task['status'] === 'completed')
                                <flux:icon name="check-circle" class="text-green-500" variant="micro" />
                            @elseif ($task['status'] === 'failed')
                                <flux:icon name="x-circle" class="text-red-400" variant="micro" />
                            @elseif ($task['status'] === 'cancelled')
                                <flux:icon name="minus-circle" class="text-zinc-500" variant="micro" />
                            @endif
                            <flux:text class="text-sm font-medium">{{ $task['label'] }}</flux:text>
                        </div>

                        @if ($task['status'] === 'running' || $task['status'] === 'pending')
                            <flux:button variant="ghost" size="xs" wire:click="cancelTask({{ $task['id'] }})">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif
                    </div>

                    @if ($task['status'] === 'running')
                        <flux:text class="mt-1 text-xs text-zinc-400">
                            {{ __('Step :completed/:total', ['completed' => $task['completed_steps'], 'total' => $task['total_steps']]) }}
                        </flux:text>
                    @elseif ($task['status'] === 'completed')
                        <flux:text class="mt-1 text-xs text-zinc-400">
                            {{ $task['duration'] }} · {{ number_format($task['total_tokens']) }} tokens · ${{ number_format((float) $task['total_cost'], 4) }}
                        </flux:text>
                    @elseif ($task['status'] === 'failed')
                        <flux:text class="mt-1 text-xs text-red-400">{{ Str::limit($task['error'], 80) }}</flux:text>
                    @else
                        <flux:text class="mt-1 text-xs text-zinc-500">{{ $task['created_at'] }}</flux:text>
                    @endif
                </div>

                @if (! $loop->last)
                    <flux:menu.separator />
                @endif
            @empty
                <div class="px-3 py-4 text-center">
                    <flux:text class="text-sm text-zinc-500">{{ __('No AI tasks yet') }}</flux:text>
                </div>
            @endforelse

            @if (count($recentTasks) > 0)
                <flux:menu.separator />
                <flux:menu.item :href="route('ai.operations')" wire:navigate>
                    {{ __('View all operations') }}
                </flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>

    @if ($hasActive)
        </div>
    @endif
</div>
```

- [ ] **Step 2: Add to sidebar layout**

In `resources/views/layouts/app/sidebar.blade.php`, add the indicator in the header area. Find the mobile header section (around line 38):

```blade
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />
```

Add the indicator after `<flux:spacer />`:

```blade
            <livewire:ai-task-indicator />
```

Also add it to the desktop sidebar, before the user menu. Find `<x-desktop-user-menu` (around line 36) and add before it:

```blade
            <div class="hidden lg:block">
                <livewire:ai-task-indicator />
            </div>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/ai-task-indicator.blade.php resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add AI task indicator component to header"
```

---

## Task 7: Rewire Brand Intelligence page

**Files:**
- Modify: `resources/views/pages/teams/⚡brand-intelligence.blade.php`

- [ ] **Step 1: Update the PHP class**

Replace the `startGeneration()` method, remove `intelligenceStatus`/`intelligenceError`/`isGenerating`/`loadGenerationStatus()` properties and methods, and update `render()`.

The `startGeneration()` method should now create an AiTask with steps and dispatch the job:

```php
    public function startGeneration(): void
    {
        Gate::authorize('update', $this->teamModel);

        $aiTask = \App\Models\AiTask::create([
            'team_id' => $this->teamModel->id,
            'type' => 'brand_intelligence',
            'label' => 'Generate Brand Intelligence',
            'status' => 'pending',
            'total_steps' => 4,
        ]);

        $aiTask->steps()->createMany([
            ['name' => 'fetching', 'label' => 'Fetching URLs'],
            ['name' => 'positioning', 'label' => 'Analyzing positioning'],
            ['name' => 'personas', 'label' => 'Building personas'],
            ['name' => 'voice_profile', 'label' => 'Defining voice profile'],
        ]);

        \App\Jobs\GenerateBrandIntelligenceJob::dispatch($this->teamModel, $aiTask);

        Flux::toast(variant: 'success', text: __('AI task started. Check the ✦ indicator for progress.'));
    }
```

Remove these properties:
- `public ?string $intelligenceStatus = null;`
- `public ?string $intelligenceError = null;`
- `public bool $isGenerating = false;`

Remove the `loadGenerationStatus()` method entirely.

Update `render()` to remove all generation status logic:

```php
    public function render()
    {
        $this->checkPrerequisites();
        $this->loadData();

        return $this->view()->title(__('Brand Intelligence'));
    }
```

- [ ] **Step 2: Update the Blade template**

Remove the entire generation progress block (`@if ($isGenerating)` ... `@endif`), the error state block (`@if ($intelligenceStatus === 'failed')` ... `@endif`), and the old generate button block.

Replace with a simple generate button when no data exists:

```blade
        {{-- Generate button (when prerequisites met, no data yet) --}}
        @if (! $missingPrerequisites && ! $hasPositioning && ! $hasPersonas && ! $hasVoiceProfile)
            <flux:card class="mt-8 text-center">
                <div class="space-y-4 py-4">
                    <flux:text>{{ __('Ready to analyze your brand. This will crawl your URLs and generate positioning, audience personas, and voice profile.') }}</flux:text>
                    @if ($this->permissions->canUpdateTeam)
                        <flux:button variant="primary" icon="sparkles" wire:click="startGeneration">
                            {{ __('Generate Brand Intelligence') }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @endif
```

Update all Regenerate buttons to use the sparkles icon:

```blade
<flux:button variant="subtle" size="sm" icon="sparkles" wire:click="startGeneration">{{ __('Regenerate') }}</flux:button>
```

```blade
<flux:button variant="subtle" size="sm" icon="sparkles" wire:click="startGeneration">{{ __('Regenerate all') }}</flux:button>
```

- [ ] **Step 3: Run all tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/BrandIntelligenceTest.php tests/Feature/BrandIntelligence/
```

Expected: All tests pass. Some Brand Intelligence tests may need updating since properties were removed — fix any failures.

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/teams/⚡brand-intelligence.blade.php"
git commit -m "feat: rewire Brand Intelligence to use AiTask (remove polling, add sparkles)"
```

---

## Task 8: Operations Page + Route

**Files:**
- Create: `resources/views/pages/teams/⚡ai-operations.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Add route**

In `routes/web.php`, add inside the team-scoped group:

```php
        Route::livewire('ai-operations', 'pages::teams.ai-operations')->name('ai.operations');
```

- [ ] **Step 2: Add sidebar nav item**

In `resources/views/layouts/app/sidebar.blade.php`, add after Brand Intelligence:

```blade
                    <flux:sidebar.item icon="chart-bar" :href="route('ai.operations')" :current="request()->routeIs('ai.operations')" wire:navigate>
                        {{ __('AI Operations') }}
                    </flux:sidebar.item>
```

- [ ] **Step 3: Create the operations page**

Create `resources/views/pages/teams/⚡ai-operations.blade.php`:

```php
<?php

use App\Models\AiTask;
use App\Models\Team;
use App\Support\TeamPermissions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public array $tasks = [];

    public array $summary = [];

    public ?int $expandedTaskId = null;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
        $this->loadTasks();
        $this->loadSummary();
    }

    public function toggleTask(int $taskId): void
    {
        $this->expandedTaskId = $this->expandedTaskId === $taskId ? null : $taskId;
    }

    public function cancelTask(int $taskId): void
    {
        Gate::authorize('update', $this->teamModel);

        $task = $this->teamModel->aiTasks()->findOrFail($taskId);

        if ($task->isActive()) {
            $task->markCancelled();
            Flux::toast(variant: 'success', text: __('Task cancelled.'));
        }

        $this->loadTasks();
    }

    public function retryTask(int $taskId): void
    {
        Gate::authorize('update', $this->teamModel);

        $oldTask = $this->teamModel->aiTasks()->findOrFail($taskId);

        if ($oldTask->type === 'brand_intelligence') {
            $aiTask = AiTask::create([
                'team_id' => $this->teamModel->id,
                'type' => 'brand_intelligence',
                'label' => 'Generate Brand Intelligence',
                'status' => 'pending',
                'total_steps' => 4,
            ]);

            $aiTask->steps()->createMany([
                ['name' => 'fetching', 'label' => 'Fetching URLs'],
                ['name' => 'positioning', 'label' => 'Analyzing positioning'],
                ['name' => 'personas', 'label' => 'Building personas'],
                ['name' => 'voice_profile', 'label' => 'Defining voice profile'],
            ]);

            \App\Jobs\GenerateBrandIntelligenceJob::dispatch($this->teamModel, $aiTask);

            Flux::toast(variant: 'success', text: __('Retrying task.'));
        }

        $this->loadTasks();
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        $this->loadTasks();

        return $this->view()->title(__('AI Operations'));
    }

    private function loadTasks(): void
    {
        $this->tasks = $this->teamModel->aiTasks()
            ->with('steps')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'type' => $task->type,
                'label' => $task->label,
                'status' => $task->status,
                'current_step' => $task->current_step,
                'completed_steps' => $task->completed_steps,
                'total_steps' => $task->total_steps,
                'total_tokens' => $task->total_tokens,
                'total_cost' => (float) $task->total_cost,
                'error' => $task->error,
                'created_at' => $task->created_at->diffForHumans(),
                'duration' => $task->completed_at && $task->started_at
                    ? $task->completed_at->diffInSeconds($task->started_at) . 's'
                    : null,
                'steps' => $task->steps->map(fn ($s) => [
                    'name' => $s->name,
                    'label' => $s->label,
                    'status' => $s->status,
                    'model' => $s->model,
                    'input_tokens' => $s->input_tokens,
                    'output_tokens' => $s->output_tokens,
                    'cost' => (float) $s->cost,
                    'iterations' => $s->iterations,
                    'duration' => $s->completed_at && $s->started_at
                        ? $s->completed_at->diffInSeconds($s->started_at) . 's'
                        : null,
                ])->toArray(),
            ])
            ->toArray();
    }

    private function loadSummary(): void
    {
        $thirtyDays = $this->teamModel->aiTasks()->recent(30);

        $this->summary = [
            'total_cost' => (float) ($thirtyDays->sum('total_cost') ?? 0),
            'tasks_run' => $thirtyDays->count(),
            'total_tokens' => (int) ($thirtyDays->sum('total_tokens') ?? 0),
        ];
    }
}; ?>

<section class="w-full">
    <flux:main container class="max-w-4xl">
        <flux:heading size="xl">{{ __('AI Operations') }}</flux:heading>
        <flux:subheading>{{ __('Monitor AI tasks, track costs, and review agent activity.') }}</flux:subheading>

        {{-- Summary cards --}}
        <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Cost (30d)') }}</flux:text>
                <flux:heading size="xl" class="mt-1">${{ number_format($summary['total_cost'], 4) }}</flux:heading>
            </flux:card>
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Tasks Run') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $summary['tasks_run'] }}</flux:heading>
            </flux:card>
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Tokens') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ number_format($summary['total_tokens']) }}</flux:heading>
            </flux:card>
        </div>

        {{-- Task list --}}
        <div class="mt-8 space-y-3">
            @forelse ($tasks as $task)
                <flux:card class="cursor-pointer {{ $task['status'] === 'running' || $task['status'] === 'pending' ? 'border-indigo-500/30' : '' }}" wire:click="toggleTask({{ $task['id'] }})">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                <flux:icon name="sparkles" class="animate-pulse text-indigo-400" />
                            @elseif ($task['status'] === 'completed')
                                <flux:icon name="check-circle" class="text-green-500" variant="solid" />
                            @elseif ($task['status'] === 'failed')
                                <flux:icon name="x-circle" class="text-red-400" variant="solid" />
                            @elseif ($task['status'] === 'cancelled')
                                <flux:icon name="minus-circle" class="text-zinc-500" variant="solid" />
                            @endif

                            <div>
                                <flux:heading>{{ $task['label'] }}</flux:heading>
                                @if ($task['status'] === 'running')
                                    <flux:text class="text-sm text-zinc-400">
                                        {{ __('Step :completed/:total', ['completed' => $task['completed_steps'], 'total' => $task['total_steps']]) }}
                                        @if ($task['current_step'])
                                            · {{ $task['current_step'] }}
                                        @endif
                                    </flux:text>
                                @elseif ($task['status'] === 'completed')
                                    <flux:text class="text-sm text-zinc-400">
                                        {{ $task['duration'] }} · {{ number_format($task['total_tokens']) }} tokens · ${{ number_format($task['total_cost'], 4) }}
                                    </flux:text>
                                @elseif ($task['status'] === 'failed')
                                    <flux:text class="text-sm text-red-400">{{ Str::limit($task['error'], 100) }}</flux:text>
                                @elseif ($task['status'] === 'cancelled')
                                    <flux:text class="text-sm text-zinc-500">{{ __('Cancelled') }} · {{ $task['completed_steps'] }}/{{ $task['total_steps'] }} {{ __('steps completed') }}</flux:text>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <flux:text class="text-sm text-zinc-500">{{ $task['created_at'] }}</flux:text>

                            @if ($this->permissions->canUpdateTeam)
                                @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                    <flux:button variant="ghost" size="xs" wire:click.stop="cancelTask({{ $task['id'] }})">{{ __('Cancel') }}</flux:button>
                                @elseif ($task['status'] === 'failed')
                                    <flux:button variant="ghost" size="xs" icon="sparkles" wire:click.stop="retryTask({{ $task['id'] }})">{{ __('Retry') }}</flux:button>
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Expandable step detail --}}
                    @if ($expandedTaskId === $task['id'] && count($task['steps']) > 0)
                        <div class="mt-4 rounded-lg bg-zinc-800 p-3" wire:click.stop>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-wide text-zinc-500">
                                        <th class="px-2 py-1 text-left">{{ __('Agent') }}</th>
                                        <th class="px-2 py-1 text-left">{{ __('Model') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Tokens') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Cost') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Loops') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Time') }}</th>
                                        <th class="px-2 py-1 text-center">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($task['steps'] as $step)
                                        <tr class="text-zinc-300">
                                            <td class="px-2 py-1">{{ $step['label'] }}</td>
                                            <td class="px-2 py-1 text-zinc-500">{{ $step['model'] ? Str::afterLast($step['model'], '/') : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['input_tokens'] + $step['output_tokens'] > 0 ? number_format($step['input_tokens'] + $step['output_tokens']) : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['cost'] > 0 ? '$' . number_format($step['cost'], 4) : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['iterations'] > 0 ? $step['iterations'] : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['duration'] ?? '—' }}</td>
                                            <td class="px-2 py-1 text-center">
                                                @if ($step['status'] === 'completed')
                                                    <span class="text-green-400">✓</span>
                                                @elseif ($step['status'] === 'running')
                                                    <span class="animate-spin text-indigo-400">●</span>
                                                @elseif ($step['status'] === 'failed')
                                                    <span class="text-red-400">✕</span>
                                                @elseif ($step['status'] === 'skipped')
                                                    <span class="text-zinc-500">—</span>
                                                @else
                                                    <span class="text-zinc-600">○</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </flux:card>
            @empty
                <flux:card class="text-center py-8">
                    <flux:icon name="sparkles" class="mx-auto text-zinc-500" />
                    <flux:text class="mt-2">{{ __('No AI tasks have been run yet.') }}</flux:text>
                </flux:card>
            @endforelse
        </div>
    </flux:main>
</section>
```

- [ ] **Step 4: Run all tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/teams/⚡ai-operations.blade.php" routes/web.php resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add AI Operations page with task history and step details"
```

---

## Task 9: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test
```

Expected: All tests pass.

- [ ] **Step 2: Restart queue worker**

```bash
docker compose up -d queue
```

- [ ] **Step 3: Verify in browser**

1. Go to Brand Intelligence — confirm sparkles icon on Generate/Regenerate buttons
2. Click "Generate Brand Intelligence" — confirm toast appears "AI task started"
3. Check header — sparkles icon should pulse with "1" badge
4. Click sparkles icon — dropdown shows running task with step progress
5. Wait for completion — toast notification with cost summary
6. Go to AI Operations page — confirm task appears with expandable step detail
7. Click the task — verify per-agent table with tokens, cost, iterations, duration
8. Check summary cards — total cost, tasks run, total tokens
9. Test cancellation — start a new generation, click Cancel, verify remaining steps skipped
10. Test retry — click Retry on a failed task
