# Custom AI Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a provider picker (OpenRouter | Custom) to team AI settings so MarketMinded can work with any OpenAI-compatible endpoint, and fix usage tracking to capture reasoning/cache tokens.

**Architecture:** Rename `openrouter_api_key` → `ai_api_key` and add `ai_provider`/`ai_api_url` columns. The `OpenRouterClient` accepts a `$baseUrl` and `$provider` parameter instead of the hardcoded constant; it sends `reasoning_effort: 'medium'` on every request and tracks reasoning/cache tokens from usage responses. The UI exposes a segmented radio picker — the URL field only appears when Custom is selected.

**Tech Stack:** Laravel 13, Livewire/Volt, Flux UI, Pest, SQLite (dev), Sail for all commands

---

## File Map

| File | Change |
|---|---|
| `database/migrations/YYYY_MM_DD_HHMMSS_refactor_ai_settings_on_teams_table.php` | Create — rename + add columns |
| `app/Models/Team.php` | Modify — fillable, hidden, casts, defaults |
| `app/Services/ChatResult.php` | Modify — add reasoning/cache token fields |
| `app/Services/StreamResult.php` | Modify — add reasoning/cache token fields |
| `app/Services/OpenRouterClient.php` | Modify — baseUrl/provider params, request defaults, usage parsing |
| `app/Services/Writer/BaseAgent.php` | Modify — pass baseUrl/provider to client, gate server tools |
| `resources/views/pages/teams/⚡create-chat.blade.php` | Modify — update ai_api_key ref, pass provider, gate server tools |
| `resources/views/pages/teams/⚡edit.blade.php` | Modify — new properties, provider picker, URL field, updated validation |
| `tests/Feature/Teams/TeamAiSettingsTest.php` | Modify — update to new column names, add provider/URL tests |

---

### Task 1: Create feature branch

- [ ] **Step 1: Create and switch to feature branch**

```bash
git checkout -b feature/custom-ai-provider
```

Expected: `Switched to a new branch 'feature/custom-ai-provider'`

---

### Task 2: Database migration

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_refactor_ai_settings_on_teams_table.php`

- [ ] **Step 1: Generate the migration**

Run: `./vendor/bin/sail artisan make:migration refactor_ai_settings_on_teams_table`

Expected: `Created Migration: database/migrations/YYYY_MM_DD_HHMMSS_refactor_ai_settings_on_teams_table.php`

- [ ] **Step 2: Write the migration**

Open the generated file and replace its contents with:

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
            $table->renameColumn('openrouter_api_key', 'ai_api_key');
            $table->string('ai_provider')->default('openrouter')->after('ai_api_key');
            $table->string('ai_api_url')->nullable()->after('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_api_url']);
            $table->renameColumn('ai_api_key', 'openrouter_api_key');
        });
    }
};
```

- [ ] **Step 3: Run the migration**

Run: `./vendor/bin/sail artisan migrate`

Expected: `Running migrations... DONE`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: rename openrouter_api_key to ai_api_key, add ai_provider and ai_api_url"
```

---

### Task 3: Update Team model

**Files:**
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Update fillable, hidden, casts, and defaults**

In `app/Models/Team.php`, replace the entire top section of the class (attributes through `$attributes`) with:

```php
#[Fillable(['name', 'slug', 'is_personal', 'ai_api_key', 'ai_provider', 'ai_api_url', 'fast_model', 'powerful_model', 'homepage_url', 'blog_url', 'brand_description', 'product_urls', 'competitor_urls', 'style_reference_urls', 'target_audience', 'tone_keywords', 'content_language'])]
#[Hidden(['ai_api_key'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ai_provider'    => 'openrouter',
        'fast_model'     => 'deepseek/deepseek-v3.2:nitro',
        'powerful_model' => 'deepseek/deepseek-v3.2:nitro',
        'product_urls'   => '[]',
        'competitor_urls'        => '[]',
        'style_reference_urls'   => '[]',
        'content_language'       => 'English',
    ];
```

Also update `casts()` at the bottom of the class:

```php
protected function casts(): array
{
    return [
        'is_personal'  => 'boolean',
        'ai_api_key'   => 'encrypted',
        'product_urls' => 'array',
        'competitor_urls'      => 'array',
        'style_reference_urls' => 'array',
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Team.php
git commit -m "feat: update Team model for ai_api_key, ai_provider, ai_api_url"
```

---

### Task 4: Add reasoning/cache token fields to ChatResult and StreamResult

**Files:**
- Modify: `app/Services/ChatResult.php`
- Modify: `app/Services/StreamResult.php`

- [ ] **Step 1: Update ChatResult**

Replace `app/Services/ChatResult.php` with:

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
        public readonly array $messages = [],
        // Future: surface in AI log for cost breakdown.
        public readonly int $reasoningTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {}

    public function usage(): array
    {
        return [
            'input_tokens'       => $this->inputTokens,
            'output_tokens'      => $this->outputTokens,
            'cost'               => $this->cost,
            'iterations'         => $this->iterations,
            'reasoning_tokens'   => $this->reasoningTokens,
            'cache_read_tokens'  => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }
}
```

- [ ] **Step 2: Update StreamResult**

Replace `app/Services/StreamResult.php` with:

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
        // Future: surface in AI log for cost breakdown.
        public readonly int $reasoningTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {}
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/ChatResult.php app/Services/StreamResult.php
git commit -m "feat: add reasoning and cache token fields to ChatResult and StreamResult"
```

---

### Task 5: Update OpenRouterClient

**Files:**
- Modify: `app/Services/OpenRouterClient.php`

This task has four sub-changes: constructor, request body defaults, usage parsing, and URL references. Make them all in one edit pass then commit.

- [ ] **Step 1: Replace the constructor and remove the API_URL constant**

Find and remove:
```php
private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';
```

Replace the constructor:
```php
public function __construct(
    private string $apiKey,
    private string $model,
    private UrlFetcher $urlFetcher,
    private int $maxIterations = 20,
    private string $baseUrl = 'https://openrouter.ai/api/v1',
    private string $provider = 'openrouter',
) {}
```

- [ ] **Step 2: Add request body defaults to the `chat()` method**

In the `chat()` method, find the body construction:

```php
$body = [
    'model' => $this->model,
    'messages' => $messages,
    'temperature' => $temperature,
    'stream' => false,
];
```

Replace with:

```php
$body = [
    'model'       => $this->model,
    'messages'    => $messages,
    'temperature' => $temperature,
    'stream'      => false,
    // reasoning_effort: controls thinking depth on reasoning models (o1, o3, DeepSeek-R1, etc.)
    // Ignored by non-reasoning models. 'medium' is the safe balanced default.
    // Future: make this configurable per team or per call.
    'reasoning_effort' => 'medium',
];

if ($this->provider === 'openrouter') {
    // verbosity: OpenRouter-specific; controls response detail level.
    // Future: make this configurable per team.
    $body['verbosity'] = 'medium';
}
```

- [ ] **Step 3: Add request body defaults to the `streamChat()` method**

In `streamChat()`, find:

```php
$body = [
    'model' => $this->model,
    'messages' => $allMessages,
    'temperature' => $temperature,
    'stream' => true,
];
```

Replace with:

```php
$body = [
    'model'       => $this->model,
    'messages'    => $allMessages,
    'temperature' => $temperature,
    'stream'      => true,
    // reasoning_effort: see chat() method comment.
    'reasoning_effort' => 'medium',
];

if ($this->provider === 'openrouter') {
    $body['verbosity'] = 'medium';
}
```

- [ ] **Step 4: Add request body defaults to the `streamChatWithTools()` method**

In `streamChatWithTools()`, find:

```php
$body = [
    'model' => $this->model,
    'messages' => $allMessages,
    'temperature' => $temperature,
    'stream' => true,
];
```

Replace with:

```php
$body = [
    'model'       => $this->model,
    'messages'    => $allMessages,
    'temperature' => $temperature,
    'stream'      => true,
    // reasoning_effort: see chat() method comment.
    'reasoning_effort' => 'medium',
];

if ($this->provider === 'openrouter') {
    $body['verbosity'] = 'medium';
}
```

- [ ] **Step 5: Update usage parsing in `chat()`**

In the `chat()` method, find the usage accumulation block:

```php
$usage = $response['usage'] ?? [];
$totalInputTokens += $usage['prompt_tokens'] ?? 0;
$totalOutputTokens += $usage['completion_tokens'] ?? 0;
$totalCost += (float) ($usage['cost'] ?? 0);
```

Replace with:

```php
$usage = $response['usage'] ?? [];
$totalInputTokens    += $usage['prompt_tokens'] ?? 0;
$totalOutputTokens   += $usage['completion_tokens'] ?? 0;
$totalCost           += (float) ($usage['cost'] ?? 0);
$totalReasoningTokens  += $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
$totalCacheReadTokens  += $usage['prompt_tokens_details']['cached_tokens'] ?? 0;
$totalCacheWriteTokens += $usage['prompt_tokens_details']['cache_write_tokens'] ?? 0;
```

Also add the accumulator variables at the top of the `while` loop (next to the existing `$totalInputTokens = 0;` declarations):

```php
$totalReasoningTokens = 0;
$totalCacheReadTokens = 0;
$totalCacheWriteTokens = 0;
```

- [ ] **Step 6: Pass new tokens to ChatResult in `chat()`**

Find the `return new ChatResult(` call inside the `while` loop and update it:

```php
return new ChatResult(
    data: $this->normalizeContent($choice['content'] ?? ''),
    inputTokens: $totalInputTokens,
    outputTokens: $totalOutputTokens,
    cost: $totalCost,
    iterations: $iteration,
    messages: $messages,
    reasoningTokens: $totalReasoningTokens,
    cacheReadTokens: $totalCacheReadTokens,
    cacheWriteTokens: $totalCacheWriteTokens,
);
```

Also update the submit-tool early-return `ChatResult` (the one that returns `$arguments`):

```php
return new ChatResult(
    data: $arguments,
    inputTokens: $totalInputTokens,
    outputTokens: $totalOutputTokens,
    cost: $totalCost,
    iterations: $iteration,
    messages: $messages,
    reasoningTokens: $totalReasoningTokens,
    cacheReadTokens: $totalCacheReadTokens,
    cacheWriteTokens: $totalCacheWriteTokens,
);
```

- [ ] **Step 7: Update usage parsing in `streamChat()`**

Add variable declarations after `$cost = 0.0;`:

```php
$reasoningTokens = 0;
$cacheReadTokens = 0;
$cacheWriteTokens = 0;
```

Find the usage capture block:

```php
if (isset($json['usage'])) {
    $inputTokens = $json['usage']['prompt_tokens'] ?? 0;
    $outputTokens = $json['usage']['completion_tokens'] ?? 0;
    $cost = (float) ($json['usage']['cost'] ?? 0);
}
```

Replace with:

```php
if (isset($json['usage'])) {
    $inputTokens      = $json['usage']['prompt_tokens'] ?? 0;
    $outputTokens     = $json['usage']['completion_tokens'] ?? 0;
    $cost             = (float) ($json['usage']['cost'] ?? 0);
    $reasoningTokens  = $json['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0;
    $cacheReadTokens  = $json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0;
    $cacheWriteTokens = $json['usage']['prompt_tokens_details']['cache_write_tokens'] ?? 0;
}
```

Update the final `yield new StreamResult(`:

```php
yield new StreamResult(
    content: $fullContent,
    inputTokens: $inputTokens,
    outputTokens: $outputTokens,
    cost: $cost,
    reasoningTokens: $reasoningTokens,
    cacheReadTokens: $cacheReadTokens,
    cacheWriteTokens: $cacheWriteTokens,
);
```

- [ ] **Step 8: Update usage parsing in `streamChatWithTools()`**

Add accumulator declarations after `$totalCost = 0.0;`:

```php
$totalReasoningTokens = 0;
$totalCacheReadTokens = 0;
$totalCacheWriteTokens = 0;
```

There are two usage-parsing blocks in `streamChatWithTools`. Update both.

**Non-streaming path** — find:

```php
$usage = $json['usage'] ?? [];
$totalInputTokens += $usage['prompt_tokens'] ?? 0;
$totalOutputTokens += $usage['completion_tokens'] ?? 0;
$totalCost += (float) ($usage['cost'] ?? 0);
$webSearchRequests += $usage['server_tool_use']['web_search_requests'] ?? 0;
```

Replace with:

```php
$usage = $json['usage'] ?? [];
$totalInputTokens      += $usage['prompt_tokens'] ?? 0;
$totalOutputTokens     += $usage['completion_tokens'] ?? 0;
$totalCost             += (float) ($usage['cost'] ?? 0);
$webSearchRequests     += $usage['server_tool_use']['web_search_requests'] ?? 0;
$totalReasoningTokens  += $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
$totalCacheReadTokens  += $usage['prompt_tokens_details']['cached_tokens'] ?? 0;
$totalCacheWriteTokens += $usage['prompt_tokens_details']['cache_write_tokens'] ?? 0;
```

**Streaming SSE path** — find:

```php
if (isset($json['usage'])) {
    $totalInputTokens += $json['usage']['prompt_tokens'] ?? 0;
    $totalOutputTokens += $json['usage']['completion_tokens'] ?? 0;
    $totalCost += (float) ($json['usage']['cost'] ?? 0);
    $webSearchRequests += $json['usage']['server_tool_use']['web_search_requests'] ?? 0;
}
```

Replace with:

```php
if (isset($json['usage'])) {
    $totalInputTokens      += $json['usage']['prompt_tokens'] ?? 0;
    $totalOutputTokens     += $json['usage']['completion_tokens'] ?? 0;
    $totalCost             += (float) ($json['usage']['cost'] ?? 0);
    $webSearchRequests     += $json['usage']['server_tool_use']['web_search_requests'] ?? 0;
    $totalReasoningTokens  += $json['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0;
    $totalCacheReadTokens  += $json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0;
    $totalCacheWriteTokens += $json['usage']['prompt_tokens_details']['cache_write_tokens'] ?? 0;
}
```

Update the final `yield new StreamResult(` at the end of `streamChatWithTools()`:

```php
yield new StreamResult(
    content: $fullContent,
    inputTokens: $totalInputTokens,
    outputTokens: $totalOutputTokens,
    cost: $totalCost,
    webSearchRequests: $webSearchRequests,
    reasoningTokens: $totalReasoningTokens,
    cacheReadTokens: $totalCacheReadTokens,
    cacheWriteTokens: $totalCacheWriteTokens,
);
```

- [ ] **Step 9: Update all URL references to use `$this->baseUrl`**

Replace every occurrence of `self::API_URL` with `$this->baseUrl . '/chat/completions'`. There are three occurrences:

1. In `streamChat()`: `->post(self::API_URL, $body)` → `->post($this->baseUrl . '/chat/completions', $body)`
2. In `streamChatWithTools()`: `->post(self::API_URL, $body)` → `->post($this->baseUrl . '/chat/completions', $body)`
3. In `sendWithRetry()`: `->post(self::API_URL, $body)` → `->post($this->baseUrl . '/chat/completions', $body)`

- [ ] **Step 10: Commit**

```bash
git add app/Services/OpenRouterClient.php
git commit -m "feat: OpenRouterClient accepts baseUrl/provider, adds reasoning defaults and token tracking"
```

---

### Task 6: Update BaseAgent to pass provider context

**Files:**
- Modify: `app/Services/Writer/BaseAgent.php`

- [ ] **Step 1: Add baseUrl and provider parameters to `llmCall()`**

Find the `llmCall()` signature:

```php
protected function llmCall(
    string $systemPrompt,
    array $tools,
    string $model,
    float $temperature,
    bool $useServerTools,
    ?string $apiKey,
    int $timeout = 120,
): ?array {
```

Replace with:

```php
protected function llmCall(
    string $systemPrompt,
    array $tools,
    string $model,
    float $temperature,
    bool $useServerTools,
    ?string $apiKey,
    int $timeout = 120,
    string $baseUrl = 'https://openrouter.ai/api/v1',
    string $provider = 'openrouter',
): ?array {
```

- [ ] **Step 2: Pass baseUrl and provider to the OpenRouterClient constructor inside `llmCall()`**

Find:

```php
$client = new OpenRouterClient(
    apiKey: $apiKey,
    model: $model,
    urlFetcher: new UrlFetcher,
    maxIterations: 10,
);
```

Replace with:

```php
$client = new OpenRouterClient(
    apiKey: $apiKey,
    model: $model,
    urlFetcher: new UrlFetcher,
    maxIterations: 10,
    baseUrl: $baseUrl,
    provider: $provider,
);
```

- [ ] **Step 3: Update the `execute()` call to `llmCall()` to pass team provider context and gate server tools**

Find:

```php
$payload = $this->llmCall(
    $systemPrompt,
    array_merge([$this->submitToolSchema()], $this->additionalTools()),
    $model,
    $this->temperature(),
    $this->useServerTools(),
    $team->openrouter_api_key,
    $this->timeout(),
);
```

Replace with:

```php
$payload = $this->llmCall(
    $systemPrompt,
    array_merge([$this->submitToolSchema()], $this->additionalTools()),
    $model,
    $this->temperature(),
    $this->useServerTools() && $team->ai_provider !== 'custom',
    $team->ai_api_key,
    $this->timeout(),
    $team->ai_api_url ?? 'https://openrouter.ai/api/v1',
    $team->ai_provider ?? 'openrouter',
);
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Writer/BaseAgent.php
git commit -m "feat: BaseAgent passes provider context and gates server tools for custom providers"
```

---

### Task 7: Update create-chat Livewire component

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php`

- [ ] **Step 1: Update the API key guard check and error message**

Find:

```php
if (! $this->teamModel->openrouter_api_key) {
    \Flux\Flux::toast(variant: 'danger', text: __('OpenRouter API key required. Add it in Team Settings.'));
    return;
}
```

Replace with:

```php
if (! $this->teamModel->ai_api_key) {
    \Flux\Flux::toast(variant: 'danger', text: __('API key required. Add it in Team Settings.'));
    return;
}
```

- [ ] **Step 2: Update OpenRouterClient instantiation**

Find:

```php
$client = new OpenRouterClient(
    apiKey: $this->teamModel->openrouter_api_key,
    model: $this->teamModel->fast_model,
    urlFetcher: new UrlFetcher,
    maxIterations: 8,
);
```

Replace with:

```php
$client = new OpenRouterClient(
    apiKey: $this->teamModel->ai_api_key,
    model: $this->teamModel->fast_model,
    urlFetcher: new UrlFetcher,
    maxIterations: 8,
    baseUrl: $this->teamModel->ai_api_url ?? 'https://openrouter.ai/api/v1',
    provider: $this->teamModel->ai_provider ?? 'openrouter',
);
```

- [ ] **Step 3: Gate server tools on provider in the streamChatWithTools call**

Find:

```php
foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor) as $item) {
```

Replace with:

```php
$useServerTools = $this->teamModel->ai_provider !== 'custom';
foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor, temperature: 0.7, useServerTools: $useServerTools) as $item) {
```

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/teams/⚡create-chat.blade.php"
git commit -m "feat: create-chat uses ai_api_key, passes provider context to OpenRouterClient"
```

---

### Task 8: Update AI Settings UI

**Files:**
- Modify: `resources/views/pages/teams/⚡edit.blade.php`

- [ ] **Step 1: Add new Livewire properties and update mount()**

In the PHP section at the top of `⚡edit.blade.php`, find the existing property declarations:

```php
public string $openrouterApiKey = '';

public string $fastModel = '';

public string $powerfulModel = '';
```

Replace with:

```php
public string $aiProvider = 'openrouter';

public string $aiApiKey = '';

public string $aiApiUrl = '';

public string $fastModel = '';

public string $powerfulModel = '';
```

In `mount()`, find:

```php
$this->openrouterApiKey = $team->openrouter_api_key ?? '';
$this->fastModel = $team->fast_model;
$this->powerfulModel = $team->powerful_model;
```

Replace with:

```php
$this->aiProvider = $team->ai_provider ?? 'openrouter';
$this->aiApiKey = $team->ai_api_key ?? '';
$this->aiApiUrl = $team->ai_api_url ?? '';
$this->fastModel = $team->fast_model;
$this->powerfulModel = $team->powerful_model;
```

- [ ] **Step 2: Update `updateAiSettings()` method**

Find the entire `updateAiSettings()` method:

```php
public function updateAiSettings(): void
{
    Gate::authorize('update', $this->teamModel);

    $validated = $this->validate([
        'openrouterApiKey' => ['nullable', 'string', 'max:255'],
        'fastModel' => ['required', 'string', 'max:255'],
        'powerfulModel' => ['required', 'string', 'max:255'],
    ]);

    $this->teamModel->update([
        'openrouter_api_key' => $validated['openrouterApiKey'] ?: null,
        'fast_model' => $validated['fastModel'],
        'powerful_model' => $validated['powerfulModel'],
    ]);

    Flux::toast(variant: 'success', text: __('AI settings updated.'));
}
```

Replace with:

```php
public function updateAiSettings(): void
{
    Gate::authorize('update', $this->teamModel);

    $validated = $this->validate([
        'aiProvider'    => ['required', 'in:openrouter,custom'],
        'aiApiKey'      => ['nullable', 'string', 'max:255'],
        'aiApiUrl'      => ['required_if:aiProvider,custom', 'nullable', 'url', 'max:500'],
        'fastModel'     => ['required', 'string', 'max:255'],
        'powerfulModel' => ['required', 'string', 'max:255'],
    ]);

    $this->teamModel->update([
        'ai_provider'   => $validated['aiProvider'],
        'ai_api_key'    => $validated['aiApiKey'] ?: null,
        'ai_api_url'    => $validated['aiApiUrl'] ?: null,
        'fast_model'    => $validated['fastModel'],
        'powerful_model' => $validated['powerfulModel'],
    ]);

    Flux::toast(variant: 'success', text: __('AI settings updated.'));
}
```

- [ ] **Step 3: Replace the AI settings form in the template**

Find the entire AI settings `<form>` block:

```html
<form wire:submit="updateAiSettings" class="space-y-6">
    <flux:input
        wire:model="openrouterApiKey"
        :label="__('OpenRouter API Key')"
        :description="__('Your team\'s API key for AI features.')"
        type="password"
        viewable
        placeholder="sk-or-..."
    />

    <flux:input
        wire:model="fastModel"
        :label="__('Fast Model')"
        :description="__('Used for research, ideation, and verification. e.g. x-ai/grok-4.1-fast, anthropic/claude-sonnet-4.6, deepseek/deepseek-v3.2:nitro')"
        placeholder="deepseek/deepseek-v3.2:nitro"
        required
    />

    <flux:input
        wire:model="powerfulModel"
        :label="__('Powerful Model')"
        :description="__('Used for writing and editing. e.g. x-ai/grok-4.1-fast, anthropic/claude-sonnet-4.6, deepseek/deepseek-v3.2:nitro')"
        placeholder="deepseek/deepseek-v3.2:nitro"
        required
    />

    <flux:button variant="primary" type="submit">
        {{ __('Save AI settings') }}
    </flux:button>
</form>
```

Replace with:

```html
<form wire:submit="updateAiSettings" class="space-y-6">
    <flux:radio.group wire:model="aiProvider" :label="__('Provider')" variant="segmented">
        <flux:radio value="openrouter">{{ __('OpenRouter') }}</flux:radio>
        <flux:radio value="custom">{{ __('Custom') }}</flux:radio>
    </flux:radio.group>

    <flux:input
        wire:model="aiApiKey"
        :label="$aiProvider === 'openrouter' ? __('OpenRouter API Key') : __('API Key')"
        :description="__('Your team\'s API key for AI features.')"
        type="password"
        viewable
        :placeholder="$aiProvider === 'openrouter' ? 'sk-or-...' : ''"
    />

    @if ($aiProvider === 'custom')
        <flux:input
            wire:model="aiApiUrl"
            :label="__('API Base URL')"
            :description="__('Use MarketMinded with any OpenAI-compatible provider — Claude, GPT, Kimi K2.6, GLM 5.1, Ollama Cloud, OpenCode Go, and more.')"
            placeholder="https://api.moonshot.ai/v1"
            type="url"
        />
    @endif

    <flux:input
        wire:model="fastModel"
        :label="__('Fast Model')"
        :description="__('Used for research, ideation, and verification. e.g. deepseek/deepseek-v3.2:nitro, gpt-4o-mini, kimi-k2.6')"
        placeholder="deepseek/deepseek-v3.2:nitro"
        required
    />

    <flux:input
        wire:model="powerfulModel"
        :label="__('Powerful Model')"
        :description="__('Used for writing and editing. e.g. deepseek/deepseek-v3.2:nitro, anthropic/claude-sonnet-4.6, kimi-k2.6')"
        placeholder="deepseek/deepseek-v3.2:nitro"
        required
    />

    <flux:button variant="primary" type="submit">
        {{ __('Save AI settings') }}
    </flux:button>
</form>
```

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/teams/⚡edit.blade.php"
git commit -m "feat: AI settings form with provider picker and custom URL field"
```

---

### Task 9: Update tests

**Files:**
- Modify: `tests/Feature/Teams/TeamAiSettingsTest.php`

- [ ] **Step 1: Write the updated and expanded test file**

Replace the entire contents of `tests/Feature/Teams/TeamAiSettingsTest.php` with:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('owner can update ai settings with openrouter provider', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiProvider', 'openrouter')
        ->set('aiApiKey', 'sk-or-test-key-123')
        ->set('fastModel', 'x-ai/grok-4.1-fast')
        ->set('powerfulModel', 'anthropic/claude-sonnet-4.6')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->ai_provider)->toBe('openrouter');
    expect($team->ai_api_key)->toBe('sk-or-test-key-123');
    expect($team->ai_api_url)->toBeNull();
    expect($team->fast_model)->toBe('x-ai/grok-4.1-fast');
    expect($team->powerful_model)->toBe('anthropic/claude-sonnet-4.6');
});

test('owner can update ai settings with custom provider', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiProvider', 'custom')
        ->set('aiApiKey', 'my-api-key')
        ->set('aiApiUrl', 'https://api.moonshot.ai/v1')
        ->set('fastModel', 'kimi-k2.6')
        ->set('powerfulModel', 'kimi-k2.6')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->ai_provider)->toBe('custom');
    expect($team->ai_api_key)->toBe('my-api-key');
    expect($team->ai_api_url)->toBe('https://api.moonshot.ai/v1');
});

test('custom provider requires a valid url', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiProvider', 'custom')
        ->set('aiApiUrl', '')
        ->call('updateAiSettings')
        ->assertHasErrors(['aiApiUrl']);
});

test('custom provider url must be a valid url format', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiProvider', 'custom')
        ->set('aiApiUrl', 'not-a-url')
        ->call('updateAiSettings')
        ->assertHasErrors(['aiApiUrl']);
});

test('openrouter provider does not require url', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiProvider', 'openrouter')
        ->set('aiApiUrl', '')
        ->set('fastModel', 'deepseek/deepseek-v3.2:nitro')
        ->set('powerfulModel', 'deepseek/deepseek-v3.2:nitro')
        ->call('updateAiSettings')
        ->assertHasNoErrors();
});

test('admin can update ai settings', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('fastModel', 'x-ai/grok-4.1-fast')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    expect($team->fresh()->fast_model)->toBe('x-ai/grok-4.1-fast');
});

test('member cannot update ai settings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('fastModel', 'x-ai/grok-4.1-fast')
        ->call('updateAiSettings')
        ->assertForbidden();
});

test('ai settings have correct defaults', function () {
    $team = Team::factory()->create();

    expect($team->ai_provider)->toBe('openrouter');
    expect($team->fast_model)->toBe('deepseek/deepseek-v3.2:nitro');
    expect($team->powerful_model)->toBe('deepseek/deepseek-v3.2:nitro');
    expect($team->ai_api_key)->toBeNull();
    expect($team->ai_api_url)->toBeNull();
});

test('api key can be cleared', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'old-key']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiApiKey', '')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    expect($team->fresh()->ai_api_key)->toBeNull();
});

test('model fields are required', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('fastModel', '')
        ->set('powerfulModel', '')
        ->call('updateAiSettings')
        ->assertHasErrors(['fastModel', 'powerfulModel']);
});
```

- [ ] **Step 2: Run the AI settings tests**

Run: `./vendor/bin/sail test tests/Feature/Teams/TeamAiSettingsTest.php`

Expected: All tests pass. Implementation is already complete by this step.

- [ ] **Step 3: Run the full test suite to confirm nothing else broke**

Run: `./vendor/bin/sail test`

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Teams/TeamAiSettingsTest.php
git commit -m "test: update AI settings tests for provider picker and custom URL"
```

---

### Task 10: Final verification and branch ready

- [ ] **Step 1: Run the full test suite one more time clean**

Run: `./vendor/bin/sail test`

Expected: All tests pass, no failures.

- [ ] **Step 2: Confirm you are on the feature branch (not main)**

Run: `git branch --show-current`

Expected: `feature/custom-ai-provider`

- [ ] **Step 3: Push the feature branch**

Run: `git push -u origin feature/custom-ai-provider`

Expected: Branch pushed, ready for review and merge to main.
