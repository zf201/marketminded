# AI Bootstrap Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the "Generate Brand Intelligence" button to three sequential AI agents that call OpenRouter, populate positioning/personas/voice profile, with per-agent progress via Livewire polling.

**Architecture:** OpenRouterClient service handles HTTP + tool-use loop + retries. UrlFetcher service crawls URLs. Three Agent service classes (Positioning, Persona, VoiceProfile) define prompts and parse results. A queued Laravel job orchestrates the pipeline. Livewire polls every 5s for progress.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, Pest, PostgreSQL, Laravel Http client, database queue

**Working directory:** `marketminded-laravel/` — all paths relative to this. Run commands via `docker exec -w /var/www/html marketminded-laravel-laravel.test-1`.

**CRITICAL RULES FOR ALL WORKERS:**
1. **Read Flux UI docs** (fluxui.dev/components) before writing any template code.
2. **Use artisan commands** to generate files where possible.
3. **NEVER run destructive database commands** (`migrate:fresh`, `db:wipe`, etc.). Only `php artisan migrate`.

---

## File Structure

### New Files

```
database/migrations/XXXX_add_intelligence_status_to_teams_table.php
app/Services/OpenRouterClient.php          — HTTP client for OpenRouter API with tool-use loop + retries
app/Services/UrlFetcher.php                — Crawls URLs, strips HTML to clean text
app/Services/Agents/PositioningAgent.php   — Positioning system prompt + submit tool schema
app/Services/Agents/PersonaAgent.php       — Persona system prompt + submit tool schema
app/Services/Agents/VoiceProfileAgent.php  — Voice profile system prompt + submit tool schema
app/Jobs/GenerateBrandIntelligenceJob.php  — Queued job orchestrating the pipeline
tests/Unit/Services/OpenRouterClientTest.php
tests/Unit/Services/UrlFetcherTest.php
tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php
```

### Modified Files

```
app/Models/Team.php                                              — Add intelligence_status + intelligence_error
resources/views/pages/teams/⚡brand-intelligence.blade.php       — Add polling, progress UI, Generate button
```

---

## Task 1: Migration for intelligence_status

**Files:**
- Create: `database/migrations/XXXX_add_intelligence_status_to_teams_table.php`
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Generate migration**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration add_intelligence_status_to_teams_table
```

- [ ] **Step 2: Write the migration**

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
            $table->string('intelligence_status', 50)->nullable()->after('content_language');
            $table->text('intelligence_error')->nullable()->after('intelligence_status');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['intelligence_status', 'intelligence_error']);
        });
    }
};
```

- [ ] **Step 3: Update Team model fillable**

In `app/Models/Team.php` line 17, add `intelligence_status` and `intelligence_error` to the Fillable attribute:

```php
#[Fillable(['name', 'slug', 'is_personal', 'openrouter_api_key', 'fast_model', 'powerful_model', 'homepage_url', 'blog_url', 'brand_description', 'product_urls', 'competitor_urls', 'style_reference_urls', 'target_audience', 'tone_keywords', 'content_language', 'intelligence_status', 'intelligence_error'])]
```

- [ ] **Step 4: Run migration**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/ app/Models/Team.php
git commit -m "feat: add intelligence_status and intelligence_error to teams table"
```

---

## Task 2: UrlFetcher Service

**Files:**
- Create: `app/Services/UrlFetcher.php`
- Create: `tests/Unit/Services/UrlFetcherTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Unit/Services/UrlFetcherTest.php`:

```php
<?php

use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('fetches and cleans html content', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><title>Example</title><script>alert("hi")</script></head><body><nav>Menu</nav><main><h1>Hello</h1><p>World</p></main><footer>Footer</footer></body></html>'),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect($result)->toContain('Title: Example');
    expect($result)->toContain('Hello');
    expect($result)->toContain('World');
    expect($result)->not->toContain('alert');
    expect($result)->not->toContain('Menu');
    expect($result)->not->toContain('Footer');
});

test('truncates content to 12kb', function () {
    $longContent = str_repeat('<p>Lorem ipsum dolor sit amet. </p>', 1000);
    Http::fake([
        'https://example.com' => Http::response("<html><head><title>Long</title></head><body>{$longContent}</body></html>"),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect(strlen($result))->toBeLessThanOrEqual(12288 + 100); // 12KB + title header
});

test('returns error message on http failure', function () {
    Http::fake([
        'https://example.com' => Http::response('Not Found', 404),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect($result)->toContain('Error fetching');
});

test('returns error message on connection failure', function () {
    Http::fake([
        'https://example.com' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect($result)->toContain('Error fetching');
});

test('fetches many urls', function () {
    Http::fake([
        'https://a.com' => Http::response('<html><head><title>A</title></head><body><p>Content A</p></body></html>'),
        'https://b.com' => Http::response('<html><head><title>B</title></head><body><p>Content B</p></body></html>'),
    ]);

    $fetcher = new UrlFetcher;
    $results = $fetcher->fetchMany(['https://a.com', 'https://b.com']);

    expect($results)->toHaveCount(2);
    expect($results['https://a.com'])->toContain('Content A');
    expect($results['https://b.com'])->toContain('Content B');
});

test('skips empty urls in fetchMany', function () {
    Http::fake([
        'https://a.com' => Http::response('<html><head><title>A</title></head><body><p>A</p></body></html>'),
    ]);

    $fetcher = new UrlFetcher;
    $results = $fetcher->fetchMany(['https://a.com', '', null]);

    expect($results)->toHaveCount(1);
});
```

- [ ] **Step 2: Write the service**

Create `app/Services/UrlFetcher.php`:

```php
<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class UrlFetcher
{
    private const MAX_CONTENT_LENGTH = 12288; // 12KB

    public function fetch(string $url): string
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->get($url);

            if ($response->failed()) {
                return "Error fetching {$url}: HTTP {$response->status()}";
            }

            return $this->cleanHtml($response->body(), $url);
        } catch (ConnectionException $e) {
            return "Error fetching {$url}: {$e->getMessage()}";
        } catch (\Throwable $e) {
            return "Error fetching {$url}: {$e->getMessage()}";
        }
    }

    public function fetchMany(array $urls): array
    {
        $results = [];

        foreach ($urls as $url) {
            if (empty($url)) {
                continue;
            }

            $results[$url] = $this->fetch($url);
        }

        return $results;
    }

    private function cleanHtml(string $html, string $url): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);

        // Remove unwanted elements
        $tagsToRemove = ['head', 'script', 'style', 'nav', 'footer', 'img', 'video', 'svg', 'noscript', 'iframe'];

        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];

            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }

            foreach ($toRemove as $element) {
                $element->parentNode->removeChild($element);
            }
        }

        // Extract title from original HTML
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1]));
        }

        // Get text content
        $text = trim($dom->textContent ?? '');

        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $content = "Title: {$title}\n\n{$text}";

        // Truncate to max length
        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            $content = substr($content, 0, self::MAX_CONTENT_LENGTH);
        }

        return $content;
    }
}
```

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Unit/Services/UrlFetcherTest.php
```

Expected: All 6 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/UrlFetcher.php tests/Unit/Services/UrlFetcherTest.php
git commit -m "feat: add UrlFetcher service for crawling and cleaning URLs"
```

---

## Task 3: OpenRouterClient Service

**Files:**
- Create: `app/Services/OpenRouterClient.php`
- Create: `tests/Unit/Services/OpenRouterClientTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Unit/Services/OpenRouterClientTest.php`:

```php
<?php

use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('sends chat completion request', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Hello world']],
            ],
        ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([
        ['role' => 'user', 'content' => 'Hi'],
    ]);

    expect($result)->toBe('Hello world');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->header('Authorization')[0] === 'Bearer sk-test'
            && $request['model'] === 'test-model'
            && $request['stream'] === false;
    });
});

test('handles tool call and executes submit tool', function () {
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
                                'name' => 'submit_positioning',
                                'arguments' => json_encode(['value_proposition' => 'We rock']),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat(
        [['role' => 'user', 'content' => 'Analyze this brand']],
        [['type' => 'function', 'function' => ['name' => 'submit_positioning', 'parameters' => []]]],
    );

    expect($result)->toBe(['value_proposition' => 'We rock']);
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

    expect($result)->toBe(['value_proposition' => 'From URL']);
});

test('retries on 5xx errors with backoff', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('Server Error', 500)
            ->push('Server Error', 500)
            ->push([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Recovered']],
                ],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result)->toBe('Recovered');
    Http::assertSentCount(3);
});

test('retries on 429 rate limit', function () {
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('Rate limited', 429)
            ->push([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'OK']],
                ],
            ]),
    ]);

    $client = new OpenRouterClient('sk-test', 'test-model', new UrlFetcher);
    $result = $client->chat([['role' => 'user', 'content' => 'Hi']]);

    expect($result)->toBe('OK');
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

- [ ] **Step 2: Write the service**

Create `app/Services/OpenRouterClient.php`:

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

    /**
     * Send a chat completion request with tool-use loop.
     *
     * Returns the parsed submit_* tool arguments, or the assistant content if no submit tool is called.
     */
    public function chat(array $messages, array $tools = [], ?string $toolChoice = null, float $temperature = 0.3): mixed
    {
        $allTools = array_merge(self::SERVER_TOOLS, $tools);
        $iteration = 0;

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

            // Add assistant message to conversation
            $messages[] = $choice;

            // No tool calls — return content
            if (empty($choice['tool_calls'])) {
                return $choice['content'] ?? '';
            }

            // Process tool calls
            foreach ($choice['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $arguments = json_decode($toolCall['function']['arguments'], true) ?? [];

                // Submit tools — return the parsed arguments immediately
                if (str_starts_with($functionName, 'submit_')) {
                    return $arguments;
                }

                // Client-side tool: fetch_url
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

    private function sendWithRetry(array $body): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = pow(2, $attempt - 1); // 1s, 2s, 4s
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

                // Don't retry client errors (except 429)
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw new \RuntimeException("OpenRouter error {$status}: {$response->body()}");
                }

                // 429 or 5xx — retry
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

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Unit/Services/OpenRouterClientTest.php
```

Expected: All 9 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/OpenRouterClient.php tests/Unit/Services/OpenRouterClientTest.php
git commit -m "feat: add OpenRouterClient service with tool-use loop and retries"
```

---

## Task 4: Agent Services

**Files:**
- Create: `app/Services/Agents/PositioningAgent.php`
- Create: `app/Services/Agents/PersonaAgent.php`
- Create: `app/Services/Agents/VoiceProfileAgent.php`

- [ ] **Step 1: Create PositioningAgent**

Create `app/Services/Agents/PositioningAgent.php`:

```php
<?php

namespace App\Services\Agents;

use App\Models\BrandPositioning;
use App\Models\Team;
use App\Services\OpenRouterClient;

class PositioningAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, array $fetchedContent): BrandPositioning
    {
        $systemPrompt = $this->buildSystemPrompt($team, $fetchedContent);

        $tools = [
            $this->fetchUrlTool(),
            $this->submitTool(),
        ];

        $result = $this->client->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Analyze the brand and produce structured positioning.'],
            ],
            $tools,
        );

        return $team->brandPositioning()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'value_proposition' => $result['value_proposition'] ?? null,
                'target_market' => $result['target_market'] ?? null,
                'differentiators' => $result['differentiators'] ?? null,
                'core_problems' => $result['core_problems'] ?? null,
                'products_services' => $result['products_services'] ?? null,
                'primary_cta' => $result['primary_cta'] ?? null,
            ],
        );
    }

    private function buildSystemPrompt(Team $team, array $fetchedContent): string
    {
        $prompt = <<<PROMPT
You are an expert content marketing strategist. Analyze the brand and produce structured positioning for "{$team->name}".

## What to produce
For each field, write specific prose about THIS client. If it could apply to any company, it's too generic.

1. **Value Proposition** — What the company does and why it matters. One clear statement.
2. **Target Market** — Who they serve. Industry, company size, role, pain level.
3. **Key Differentiators** — What sets them apart from alternatives. Be specific.
4. **Core Problems Solved** — What pain points the product addresses. Why existing solutions fail.
5. **Products & Services** — What they actually sell. Key features.
6. **Primary CTA** — What action they want readers to take (book a call, sign up, buy, etc.).

PROMPT;

        if ($team->brand_description) {
            $prompt .= "\n## Brand Description (from the team)\n{$team->brand_description}\n";
        }

        if ($team->target_audience) {
            $prompt .= "\n## Target Audience Hint\n{$team->target_audience}\n";
        }

        if ($team->tone_keywords) {
            $prompt .= "\n## Tone Keywords\n{$team->tone_keywords}\n";
        }

        if (! empty($fetchedContent)) {
            $prompt .= "\n## Source Material (fetched from client URLs)\n";
            foreach ($fetchedContent as $url => $content) {
                $prompt .= "\n### {$url}\n{$content}\n";
            }
        }

        $prompt .= <<<PROMPT

## Rules
- NEVER fabricate or assume details. Base everything on the source material.
- Write specific prose about THIS client.
- Be thorough and comprehensive.
- Write in English.
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure.
- Zero emojis.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "at the end of the day", "it's worth noting".
- Short, direct sentences. Vary length.

Call submit_positioning with your results.
PROMPT;

        return $prompt;
    }

    private function submitTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_positioning',
                'description' => 'Submit the structured brand positioning.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'value_proposition' => ['type' => 'string', 'description' => 'What the company does and why it matters'],
                        'target_market' => ['type' => 'string', 'description' => 'Who they serve'],
                        'differentiators' => ['type' => 'string', 'description' => 'What sets them apart'],
                        'core_problems' => ['type' => 'string', 'description' => 'Pain points the product addresses'],
                        'products_services' => ['type' => 'string', 'description' => 'What they sell'],
                        'primary_cta' => ['type' => 'string', 'description' => 'Desired reader action'],
                    ],
                    'required' => ['value_proposition', 'target_market', 'differentiators', 'core_problems', 'products_services', 'primary_cta'],
                ],
            ],
        ];
    }

    private function fetchUrlTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Fetch and extract text content from a URL.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 2: Create PersonaAgent**

Create `app/Services/Agents/PersonaAgent.php`:

```php
<?php

namespace App\Services\Agents;

use App\Models\BrandPositioning;
use App\Models\Team;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;

class PersonaAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, BrandPositioning $positioning, array $fetchedContent): Collection
    {
        $systemPrompt = $this->buildSystemPrompt($team, $positioning, $fetchedContent);

        $tools = [
            $this->fetchUrlTool(),
            $this->submitTool(),
        ];

        $result = $this->client->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Research and define 3-5 detailed audience personas for this business.'],
            ],
            $tools,
        );

        // Delete existing personas and create new ones
        $team->audiencePersonas()->delete();

        $personas = collect();
        foreach (($result['personas'] ?? []) as $index => $personaData) {
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

        return $personas;
    }

    private function buildSystemPrompt(Team $team, BrandPositioning $positioning, array $fetchedContent): string
    {
        $prompt = <<<PROMPT
You are an expert content marketing strategist building audience personas for "{$team->name}".

## Product & Positioning
- Value Proposition: {$positioning->value_proposition}
- Target Market: {$positioning->target_market}
- Key Differentiators: {$positioning->differentiators}
- Core Problems: {$positioning->core_problems}
- Products & Services: {$positioning->products_services}

PROMPT;

        if ($team->target_audience) {
            $prompt .= "\n## Target Audience Hint\n{$team->target_audience}\n";
        }

        if ($team->brand_description) {
            $prompt .= "\n## Brand Description\n{$team->brand_description}\n";
        }

        $prompt .= <<<PROMPT

## Your Task
Research and define 3-5 detailed audience personas for this business. Use web search to research the market, competitors, and target audience.

For each persona, provide:
- **label**: A short memorable name (e.g. "The Overwhelmed Engineering Lead")
- **description**: 2-3 sentences describing who they are
- **pain_points**: Their specific frustrations and problems
- **push**: What's driving them to seek a solution NOW
- **pull**: What attracts them to THIS specific solution
- **anxiety**: Concerns that might stop them from acting
- **role**: Their job title/role

## Rules
- Write in English.
- NEVER fabricate details. Use web search to research real market data.
- Be specific to THIS business. Generic personas are useless.
- Write in plain language, not marketing jargon.
- Each persona should be distinct and non-overlapping.
- NEVER use em dashes. Use commas, periods, or restructure.
- Short, direct sentences.

Call submit_personas with your results.
PROMPT;

        return $prompt;
    }

    private function submitTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_personas',
                'description' => 'Submit the final set of audience personas.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'personas' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'label' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'pain_points' => ['type' => 'string'],
                                    'push' => ['type' => 'string'],
                                    'pull' => ['type' => 'string'],
                                    'anxiety' => ['type' => 'string'],
                                    'role' => ['type' => 'string'],
                                ],
                                'required' => ['label', 'description', 'pain_points', 'push', 'pull', 'anxiety'],
                            ],
                        ],
                    ],
                    'required' => ['personas'],
                ],
            ],
        ];
    }

    private function fetchUrlTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Fetch and extract text content from a URL.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 3: Create VoiceProfileAgent**

Create `app/Services/Agents/VoiceProfileAgent.php`:

```php
<?php

namespace App\Services\Agents;

use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\VoiceProfile;
use App\Services\OpenRouterClient;

class VoiceProfileAgent
{
    public function __construct(private OpenRouterClient $client) {}

    public function generate(Team $team, BrandPositioning $positioning, array $fetchedContent): VoiceProfile
    {
        $systemPrompt = $this->buildSystemPrompt($team, $positioning, $fetchedContent);

        $tools = [
            $this->fetchUrlTool(),
            $this->submitTool(),
        ];

        $result = $this->client->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => 'Analyze the writing style and produce a structured voice & tone profile.'],
            ],
            $tools,
        );

        return $team->voiceProfile()->updateOrCreate(
            ['team_id' => $team->id],
            [
                'voice_analysis' => $result['voice_analysis'] ?? null,
                'content_types' => $result['content_types'] ?? null,
                'should_avoid' => $result['should_avoid'] ?? null,
                'should_use' => $result['should_use'] ?? null,
                'style_inspiration' => $result['style_inspiration'] ?? null,
                'preferred_length' => $result['preferred_length'] ?? 1500,
            ],
        );
    }

    private function buildSystemPrompt(Team $team, BrandPositioning $positioning, array $fetchedContent): string
    {
        $prompt = <<<PROMPT
You are an expert brand voice analyst building a structured voice & tone profile for "{$team->name}".

## Product & Positioning
- Value Proposition: {$positioning->value_proposition}
- Target Market: {$positioning->target_market}
- Key Differentiators: {$positioning->differentiators}

PROMPT;

        if ($team->tone_keywords) {
            $prompt .= "\n## Tone Keywords (from the team)\n{$team->tone_keywords}\n";
        }

        if (! empty($fetchedContent)) {
            $prompt .= "\n## Source Material (fetched from client URLs — blog posts, style references)\n";
            foreach ($fetchedContent as $url => $content) {
                $prompt .= "\n### {$url}\n{$content}\n";
            }
        }

        $prompt .= <<<PROMPT

## Your Task

### Step 1: Analyze writing patterns
If blog posts or style reference content is provided above, analyze the writing patterns. Focus on STYLE, not content:
- Voice and personality (formal/informal, warm/cold, peer/authority)
- Sentence structure, length, and rhythm
- Vocabulary level and recurring phrases
- How they address the reader
- Formatting patterns (headings, lists, CTAs)

If no blog/style content is available, use the positioning and tone keywords to infer an appropriate voice.

### Step 2: Use fetch_url if needed
If the source material above includes blog listing pages, use fetch_url to find and read 2-3 individual blog posts for deeper style analysis.

### Step 3: Produce structured output
Call submit_voice_profile with these fields:
1. **voice_analysis** — Brand personality, formality level, warmth, how they relate to the reader
2. **content_types** — What content approaches the brand uses (educational, promotional, storytelling, opinion, how-to, case study, etc.)
3. **should_avoid** — Words, phrases, patterns, and tones to never use
4. **should_use** — Characteristic vocabulary, phrases, sentence patterns, formatting conventions
5. **style_inspiration** — Writing style patterns observed from the source material
6. **preferred_length** — Target word count as an integer. Infer from blog posts if possible, default 1500.

## Rules
- Write in English.
- Analyze STYLE, not content. Focus on HOW they write, not WHAT they write about.
- Be specific to THIS brand. Generic voice guidelines are useless.
- NEVER use em dashes. Use commas, periods, or restructure.
- Short, direct sentences.

Call submit_voice_profile with your results.
PROMPT;

        return $prompt;
    }

    private function submitTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_voice_profile',
                'description' => 'Submit the structured voice & tone profile.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'voice_analysis' => ['type' => 'string', 'description' => 'Brand personality, formality, warmth'],
                        'content_types' => ['type' => 'string', 'description' => 'Content approaches the brand uses'],
                        'should_avoid' => ['type' => 'string', 'description' => 'Words, phrases, tones to never use'],
                        'should_use' => ['type' => 'string', 'description' => 'Characteristic vocabulary and patterns'],
                        'style_inspiration' => ['type' => 'string', 'description' => 'Writing style patterns from references'],
                        'preferred_length' => ['type' => 'integer', 'description' => 'Target word count, default 1500'],
                    ],
                    'required' => ['voice_analysis', 'content_types', 'should_avoid', 'should_use', 'style_inspiration', 'preferred_length'],
                ],
            ],
        ];
    }

    private function fetchUrlTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'fetch_url',
                'description' => 'Fetch and extract text content from a URL.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Agents/
git commit -m "feat: add PositioningAgent, PersonaAgent, VoiceProfileAgent services"
```

---

## Task 5: GenerateBrandIntelligenceJob

**Files:**
- Create: `app/Jobs/GenerateBrandIntelligenceJob.php`
- Create: `tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Jobs\GenerateBrandIntelligenceJob;
use App\Models\AudiencePersona;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\UrlFetcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('generate button dispatches job', function () {
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

    Queue::assertPushed(GenerateBrandIntelligenceJob::class, function ($job) use ($team) {
        return $job->team->id === $team->id;
    });
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

test('job updates status through each phase', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $positioning = BrandPositioning::create([
        'team_id' => $team->id,
        'value_proposition' => 'Test',
    ]);

    // Mock all agents
    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive('fetchMany')->andReturn(['https://example.com' => 'Content']);

    $mockPositioningAgent = Mockery::mock(PositioningAgent::class);
    $mockPositioningAgent->shouldReceive('generate')->andReturn($positioning);

    $mockPersonaAgent = Mockery::mock(PersonaAgent::class);
    $mockPersonaAgent->shouldReceive('generate')->andReturn(collect());

    $mockVoiceAgent = Mockery::mock(VoiceProfileAgent::class);
    $mockVoiceAgent->shouldReceive('generate')->andReturn(
        VoiceProfile::create(['team_id' => $team->id, 'voice_analysis' => 'Test']),
    );

    app()->instance(UrlFetcher::class, $mockUrlFetcher);
    app()->instance(PositioningAgent::class, $mockPositioningAgent);
    app()->instance(PersonaAgent::class, $mockPersonaAgent);
    app()->instance(VoiceProfileAgent::class, $mockVoiceAgent);

    $job = new GenerateBrandIntelligenceJob($team);
    $job->handle(
        $mockUrlFetcher,
        $mockPositioningAgent,
        $mockPersonaAgent,
        $mockVoiceAgent,
    );

    expect($team->fresh()->intelligence_status)->toBe('completed');
    expect($team->fresh()->intelligence_error)->toBeNull();
});

test('job sets failed status on error', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive('fetchMany')->andThrow(new \RuntimeException('API Error'));

    $job = new GenerateBrandIntelligenceJob($team);

    try {
        $job->handle(
            $mockUrlFetcher,
            Mockery::mock(PositioningAgent::class),
            Mockery::mock(PersonaAgent::class),
            Mockery::mock(VoiceProfileAgent::class),
        );
    } catch (\RuntimeException $e) {
        // Expected
    }

    $job->failed(new \RuntimeException('API Error'));

    expect($team->fresh()->intelligence_status)->toBe('failed');
    expect($team->fresh()->intelligence_error)->toBe('API Error');
});

test('job deletes existing data before regenerating', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    // Create existing data
    BrandPositioning::create(['team_id' => $team->id, 'value_proposition' => 'Old']);
    AudiencePersona::create(['team_id' => $team->id, 'label' => 'Old Persona']);
    VoiceProfile::create(['team_id' => $team->id, 'voice_analysis' => 'Old']);

    $newPositioning = BrandPositioning::make([
        'team_id' => $team->id,
        'value_proposition' => 'New',
    ]);

    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive('fetchMany')->andReturn([]);

    $mockPositioningAgent = Mockery::mock(PositioningAgent::class);
    $mockPositioningAgent->shouldReceive('generate')->andReturnUsing(function ($team) {
        return $team->brandPositioning()->updateOrCreate(
            ['team_id' => $team->id],
            ['value_proposition' => 'New'],
        );
    });

    $mockPersonaAgent = Mockery::mock(PersonaAgent::class);
    $mockPersonaAgent->shouldReceive('generate')->andReturnUsing(function ($team) {
        $team->audiencePersonas()->delete();

        return collect([$team->audiencePersonas()->create(['label' => 'New Persona', 'sort_order' => 0])]);
    });

    $mockVoiceAgent = Mockery::mock(VoiceProfileAgent::class);
    $mockVoiceAgent->shouldReceive('generate')->andReturnUsing(function ($team) {
        return $team->voiceProfile()->updateOrCreate(
            ['team_id' => $team->id],
            ['voice_analysis' => 'New'],
        );
    });

    $job = new GenerateBrandIntelligenceJob($team);
    $job->handle($mockUrlFetcher, $mockPositioningAgent, $mockPersonaAgent, $mockVoiceAgent);

    expect($team->fresh()->brandPositioning->value_proposition)->toBe('New');
    expect($team->audiencePersonas()->count())->toBe(1);
    expect($team->audiencePersonas()->first()->label)->toBe('New Persona');
    expect($team->fresh()->voiceProfile->voice_analysis)->toBe('New');
});
```

- [ ] **Step 2: Write the job**

Generate via artisan first:

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:job GenerateBrandIntelligenceJob
```

Then replace `app/Jobs/GenerateBrandIntelligenceJob.php` with:

```php
<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\UrlFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBrandIntelligenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public Team $team) {}

    public function uniqueId(): string
    {
        return "team:{$this->team->id}";
    }

    public function handle(
        UrlFetcher $urlFetcher,
        PositioningAgent $positioningAgent,
        PersonaAgent $personaAgent,
        VoiceProfileAgent $voiceProfileAgent,
    ): void {
        $team = $this->team;

        // Step 1: Fetch URLs
        $team->update(['intelligence_status' => 'fetching', 'intelligence_error' => null]);

        $urlsToFetch = array_merge(
            [$team->homepage_url],
            $team->product_urls ?? [],
            array_filter([$team->blog_url]),
            $team->style_reference_urls ?? [],
        );

        $fetchedContent = $urlFetcher->fetchMany($urlsToFetch);

        // Step 2: Positioning
        $team->update(['intelligence_status' => 'positioning']);
        $positioning = $positioningAgent->generate($team, $fetchedContent);

        // Step 3: Personas
        $team->update(['intelligence_status' => 'personas']);
        $personaAgent->generate($team, $positioning, $fetchedContent);

        // Step 4: Voice Profile
        $team->update(['intelligence_status' => 'voice_profile']);
        $voiceProfileAgent->generate($team, $positioning, $fetchedContent);

        // Done
        $team->update(['intelligence_status' => 'completed']);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->team->update([
            'intelligence_status' => 'failed',
            'intelligence_error' => $exception?->getMessage(),
        ]);
    }
}
```

- [ ] **Step 3: Register agent bindings**

The agents need the OpenRouterClient injected. Create service bindings in `app/Providers/AppServiceProvider.php`. First, read the file to see its current state, then add bindings in the `register()` method:

```php
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;

// In register() method:
$this->app->bind(OpenRouterClient::class, function ($app) {
    $team = $app->bound('current_team') ? $app->make('current_team') : null;

    return new OpenRouterClient(
        apiKey: $team?->openrouter_api_key ?? '',
        model: $team?->powerful_model ?? 'deepseek/deepseek-v3.2:nitro',
        urlFetcher: $app->make(UrlFetcher::class),
    );
});

$this->app->bind(PositioningAgent::class, function ($app) {
    return new PositioningAgent($app->make(OpenRouterClient::class));
});

$this->app->bind(PersonaAgent::class, function ($app) {
    return new PersonaAgent($app->make(OpenRouterClient::class));
});

$this->app->bind(VoiceProfileAgent::class, function ($app) {
    return new VoiceProfileAgent($app->make(OpenRouterClient::class));
});
```

Note: The job constructs agents directly via handle() parameter injection — Laravel's container resolves them. But since the team's API key is needed at construction time, the job should pass the team context. Update the job's handle method to construct the client explicitly:

Actually, it's simpler to construct the client in the job since we have the team. Update the `handle()` method:

```php
public function handle(UrlFetcher $urlFetcher): void
{
    $team = $this->team;

    $client = new OpenRouterClient(
        apiKey: $team->openrouter_api_key,
        model: $team->powerful_model,
        urlFetcher: $urlFetcher,
    );

    $positioningAgent = new PositioningAgent($client);
    $personaAgent = new PersonaAgent($client);
    $voiceProfileAgent = new VoiceProfileAgent($client);

    // Step 1: Fetch URLs
    $team->update(['intelligence_status' => 'fetching', 'intelligence_error' => null]);

    $urlsToFetch = array_merge(
        [$team->homepage_url],
        $team->product_urls ?? [],
        array_filter([$team->blog_url]),
        $team->style_reference_urls ?? [],
    );

    $fetchedContent = $urlFetcher->fetchMany($urlsToFetch);

    // Step 2: Positioning
    $team->update(['intelligence_status' => 'positioning']);
    $positioning = $positioningAgent->generate($team, $fetchedContent);

    // Step 3: Personas
    $team->update(['intelligence_status' => 'personas']);
    $personaAgent->generate($team, $positioning, $fetchedContent);

    // Step 4: Voice Profile
    $team->update(['intelligence_status' => 'voice_profile']);
    $voiceProfileAgent->generate($team, $positioning, $fetchedContent);

    // Done
    $team->update(['intelligence_status' => 'completed']);
}
```

- [ ] **Step 4: Update tests to match simplified handle signature**

The tests that call `$job->handle(...)` need to pass only the UrlFetcher since agents are now constructed inside. But since the tests mock the agents, we need a different approach. Update the job to accept optional agent overrides for testing:

Update the job's `handle()` to:

```php
public function handle(
    ?UrlFetcher $urlFetcher = null,
    ?PositioningAgent $positioningAgent = null,
    ?PersonaAgent $personaAgent = null,
    ?VoiceProfileAgent $voiceProfileAgent = null,
): void {
    $team = $this->team;
    $urlFetcher ??= app(UrlFetcher::class);

    if (! $positioningAgent || ! $personaAgent || ! $voiceProfileAgent) {
        $client = new OpenRouterClient(
            apiKey: $team->openrouter_api_key,
            model: $team->powerful_model,
            urlFetcher: $urlFetcher,
        );

        $positioningAgent ??= new PositioningAgent($client);
        $personaAgent ??= new PersonaAgent($client);
        $voiceProfileAgent ??= new VoiceProfileAgent($client);
    }

    // Step 1: Fetch URLs
    $team->update(['intelligence_status' => 'fetching', 'intelligence_error' => null]);

    $urlsToFetch = array_merge(
        [$team->homepage_url],
        $team->product_urls ?? [],
        array_filter([$team->blog_url]),
        $team->style_reference_urls ?? [],
    );

    $fetchedContent = $urlFetcher->fetchMany($urlsToFetch);

    // Step 2: Positioning
    $team->update(['intelligence_status' => 'positioning']);
    $positioning = $positioningAgent->generate($team, $fetchedContent);

    // Step 3: Personas
    $team->update(['intelligence_status' => 'personas']);
    $personaAgent->generate($team, $positioning, $fetchedContent);

    // Step 4: Voice Profile
    $team->update(['intelligence_status' => 'voice_profile']);
    $voiceProfileAgent->generate($team, $positioning, $fetchedContent);

    // Done
    $team->update(['intelligence_status' => 'completed']);
}
```

- [ ] **Step 5: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/BrandIntelligence/GenerateBrandIntelligenceTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ tests/Feature/BrandIntelligence/
git commit -m "feat: add GenerateBrandIntelligenceJob with tests"
```

---

## Task 6: Wire UI — Livewire polling + progress

**Files:**
- Modify: `resources/views/pages/teams/⚡brand-intelligence.blade.php`

**IMPORTANT:** Before writing template changes, read Flux UI docs for components used: callout, button, card, separator, text, heading.

- [ ] **Step 1: Add startGeneration method to the PHP class**

In `resources/views/pages/teams/⚡brand-intelligence.blade.php`, add this method after `resetPersonaForm()`:

```php
    public function startGeneration(): void
    {
        Gate::authorize('update', $this->teamModel);

        $this->teamModel->update(['intelligence_status' => 'pending', 'intelligence_error' => null]);

        \App\Jobs\GenerateBrandIntelligenceJob::dispatch($this->teamModel);
    }
```

Add this import at the top of the PHP block (after the existing `use` statements):

```php
use App\Jobs\GenerateBrandIntelligenceJob;
```

- [ ] **Step 2: Add status properties and update render/loadData**

Add these properties after the existing `public ?array $voiceProfile = null;`:

```php
    public ?string $intelligenceStatus = null;

    public ?string $intelligenceError = null;

    public bool $isGenerating = false;
```

In the `mount()` method, after `$this->loadData();`, add:

```php
        $this->loadGenerationStatus();
```

In the `render()` method, add `$this->loadGenerationStatus()` and reload data when completed:

```php
    public function render()
    {
        $this->checkPrerequisites();
        $this->loadGenerationStatus();

        // Auto-reload data when generation completes
        if ($this->intelligenceStatus === 'completed') {
            $this->teamModel->update(['intelligence_status' => null]);
            $this->intelligenceStatus = null;
            $this->isGenerating = false;
            $this->loadData();
        }

        return $this->view()->title(__('Brand Intelligence'));
    }
```

Add the `loadGenerationStatus` method:

```php
    private function loadGenerationStatus(): void
    {
        $team = $this->teamModel->fresh();
        $this->intelligenceStatus = $team->intelligence_status;
        $this->intelligenceError = $team->intelligence_error;
        $this->isGenerating = in_array($this->intelligenceStatus, ['pending', 'fetching', 'positioning', 'personas', 'voice_profile']);
    }
```

- [ ] **Step 3: Update the Blade template**

In the template section, replace the Bootstrap CTA block:

```blade
        {{-- Bootstrap CTA (when prerequisites met but no data) --}}
        @if (! $missingPrerequisites && ! $hasPositioning && ! $hasPersonas && ! $hasVoiceProfile)
            <flux:card class="mt-8 text-center">
                <div class="space-y-4 py-4">
                    <flux:text>{{ __('Ready to analyze your brand. This will crawl your URLs and generate positioning, audience personas, and voice profile.') }}</flux:text>
                    <flux:button variant="primary" disabled>
                        {{ __('Generate Brand Intelligence') }}
                    </flux:button>
                    <flux:text class="text-xs">{{ __('Coming soon — AI generation will be available in a future update.') }}</flux:text>
                </div>
            </flux:card>
        @endif
```

With:

```blade
        {{-- Generation progress --}}
        @if ($isGenerating)
            <div wire:poll.5s class="mt-8">
                <flux:card class="space-y-4 py-2">
                    <flux:heading size="lg">{{ __('Generating Brand Intelligence...') }}</flux:heading>

                    <div class="space-y-3">
                        @php
                            $steps = [
                                'fetching' => 'Fetching URLs',
                                'positioning' => 'Analyzing positioning',
                                'personas' => 'Building personas',
                                'voice_profile' => 'Defining voice profile',
                            ];
                            $stepKeys = array_keys($steps);
                            $currentIndex = array_search($intelligenceStatus, $stepKeys);
                        @endphp

                        @foreach ($steps as $key => $label)
                            @php
                                $stepIndex = array_search($key, $stepKeys);
                                $isDone = $currentIndex !== false && $stepIndex < $currentIndex;
                                $isCurrent = $key === $intelligenceStatus;
                            @endphp
                            <div class="flex items-center gap-3">
                                @if ($isDone)
                                    <flux:icon name="check-circle" class="text-green-500" variant="solid" />
                                @elseif ($isCurrent)
                                    <flux:icon name="arrow-path" class="animate-spin text-indigo-400" />
                                @else
                                    <flux:icon name="ellipsis-horizontal-circle" class="text-zinc-500" />
                                @endif
                                <flux:text class="{{ $isCurrent ? 'text-white font-medium' : ($isDone ? 'text-zinc-400' : 'text-zinc-500') }}">{{ $label }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            </div>
        @endif

        {{-- Error state --}}
        @if ($intelligenceStatus === 'failed')
            <flux:callout variant="danger" icon="exclamation-circle" class="mt-6">
                <flux:callout.heading>{{ __('Generation failed') }}</flux:callout.heading>
                <flux:callout.text>{{ $intelligenceError }}</flux:callout.text>
            </flux:callout>
            @if ($this->permissions->canUpdateTeam)
                <div class="mt-4">
                    <flux:button variant="primary" wire:click="startGeneration">{{ __('Retry') }}</flux:button>
                </div>
            @endif
        @endif

        {{-- Generate button (when prerequisites met, no data, not generating) --}}
        @if (! $missingPrerequisites && ! $hasPositioning && ! $hasPersonas && ! $hasVoiceProfile && ! $isGenerating && $intelligenceStatus !== 'failed')
            <flux:card class="mt-8 text-center">
                <div class="space-y-4 py-4">
                    <flux:text>{{ __('Ready to analyze your brand. This will crawl your URLs and generate positioning, audience personas, and voice profile.') }}</flux:text>
                    @if ($this->permissions->canUpdateTeam)
                        <flux:button variant="primary" wire:click="startGeneration">
                            {{ __('Generate Brand Intelligence') }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @endif
```

Also update the Regenerate buttons (in positioning, personas, and voice sections) to call `startGeneration` instead of being disabled. Find each:

```blade
<flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate') }}</flux:button>
```

And replace with:

```blade
<flux:button variant="subtle" size="sm" wire:click="startGeneration">{{ __('Regenerate') }}</flux:button>
```

And for personas:

```blade
<flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate all') }}</flux:button>
```

Replace with:

```blade
<flux:button variant="subtle" size="sm" wire:click="startGeneration">{{ __('Regenerate all') }}</flux:button>
```

- [ ] **Step 4: Run all tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/ tests/Feature/BrandIntelligence/ tests/Unit/Services/
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/teams/⚡brand-intelligence.blade.php"
git commit -m "feat: wire Generate button with Livewire polling and progress UI"
```

---

## Task 7: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test
```

Expected: All tests pass.

- [ ] **Step 2: Start the queue worker**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan queue:work --timeout=300 &
```

- [ ] **Step 3: Verify in browser**

Open http://localhost, login, then:

1. Go to Brand Setup — add a homepage URL (e.g., https://example.com) and save
2. Go to Team Settings — add an OpenRouter API key and save
3. Go to Brand Intelligence — confirm prerequisite warnings are gone
4. Click "Generate Brand Intelligence"
5. Watch the progress card — should show steps completing with checkmarks
6. When done, verify positioning, personas, and voice profile sections appear with data
7. Click "Regenerate" — should show progress again and replace data
8. Test error handling: set an invalid API key, try to generate, confirm error callout appears with Retry button
