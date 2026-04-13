# Topic Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add AI-powered topic generation to the chat system with a `save_topics` tool and a dedicated Topics management page.

**Architecture:** Topics are generated conversationally inside the existing "Brainstorm topics" chat type. The AI proposes topics, the user approves, and a `save_topics` tool persists them. A new Topics page shows all saved topics with scoring, delete, and conversation links.

**Tech Stack:** Laravel 13, Livewire/Volt inline components, Flux UI Pro, Pest (tests), OpenRouter API with `streamChatWithTools()` generator

---

## File Structure

### Create

| File | Responsibility |
|------|---------------|
| `marketminded-laravel/database/migrations/2026_04_13_000001_create_topics_table.php` | Topics table schema |
| `marketminded-laravel/app/Models/Topic.php` | Topic Eloquent model |
| `marketminded-laravel/app/Services/TopicToolHandler.php` | `save_topics` tool schema + execution |
| `marketminded-laravel/resources/views/pages/teams/⚡topics.blade.php` | Topics management page |
| `marketminded-laravel/tests/Unit/Services/TopicToolHandlerTest.php` | Tool handler tests |
| `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTopicsTest.php` | Topics prompt + tools tests |

### Modify

| File | Change |
|------|--------|
| `marketminded-laravel/app/Models/Team.php` | Add `topics()` relationship |
| `marketminded-laravel/app/Services/ChatPromptBuilder.php` | Rewrite topics prompt, add tools for topics type, add existing backlog context |
| `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php` | Add sub-card selection, `topicsMode` property, `save_topics` in tool executor, saved topic card rendering |
| `marketminded-laravel/routes/web.php` | Add topics route |
| `marketminded-laravel/resources/views/layouts/app/sidebar.blade.php` | Add Topics nav item |

---

### Task 1: Migration + Topic Model

**Files:**
- Create: `marketminded-laravel/database/migrations/2026_04_13_000001_create_topics_table.php`
- Create: `marketminded-laravel/app/Models/Topic.php`
- Modify: `marketminded-laravel/app/Models/Team.php:146-149`

- [ ] **Step 1: Create the migration**

Run:
```bash
cd marketminded-laravel && sail artisan make:migration create_topics_table
```

Then replace the generated file content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('angle');
            $table->json('sources')->default('[]');
            $table->string('status', 20)->default('available');
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
```

- [ ] **Step 2: Create the Topic model**

Create `marketminded-laravel/app/Models/Topic.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'conversation_id', 'title', 'angle', 'sources', 'status', 'score'])]
class Topic extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'score' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Topic $topic) {
            $topic->created_at ??= now();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

- [ ] **Step 3: Add `topics()` relationship to Team model**

In `marketminded-laravel/app/Models/Team.php`, add after the `conversations()` method (after line 149):

```php
    /**
     * Get all topics for this team.
     *
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderByDesc('created_at');
    }
```

- [ ] **Step 4: Run the migration**

Run:
```bash
cd marketminded-laravel && sail artisan migrate
```

Expected: Migration runs successfully, `topics` table created.

- [ ] **Step 5: Verify in tinker**

Run:
```bash
cd marketminded-laravel && sail artisan tinker --execute="Schema::hasTable('topics') ? 'OK' : 'FAIL'"
```

Expected: Output contains `OK`.

- [ ] **Step 6: Commit**

```bash
git add marketminded-laravel/database/migrations/*create_topics_table.php marketminded-laravel/app/Models/Topic.php marketminded-laravel/app/Models/Team.php
git commit -m "feat: add Topic model and migration"
```

---

### Task 2: TopicToolHandler

**Files:**
- Create: `marketminded-laravel/app/Services/TopicToolHandler.php`
- Create: `marketminded-laravel/tests/Unit/Services/TopicToolHandlerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `marketminded-laravel/tests/Unit/Services/TopicToolHandlerTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\TopicToolHandler;

test('saves topics to the database', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test',
        'type' => 'topics',
    ]);

    $handler = new TopicToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'topics' => [
            [
                'title' => 'Why Zero-Party Data Matters',
                'angle' => 'Privacy-first positioning advantage',
                'sources' => ['Reuters article', 'HubSpot study'],
            ],
            [
                'title' => 'The Hidden Cost of Free Analytics',
                'angle' => 'Connects to privacy messaging',
            ],
        ],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('saved');
    expect($decoded['count'])->toBe(2);
    expect($decoded['titles'])->toContain('Why Zero-Party Data Matters');
    expect($decoded['titles'])->toContain('The Hidden Cost of Free Analytics');

    $topics = $team->topics()->get();
    expect($topics)->toHaveCount(2);
    expect($topics[0]->status)->toBe('available');
    expect($topics[0]->conversation_id)->toBe($conversation->id);
    expect($topics[0]->sources)->toBe(['Reuters article', 'HubSpot study']);
    expect($topics[0]->score)->toBeNull();
});

test('saves topics without sources', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test',
        'type' => 'topics',
    ]);

    $handler = new TopicToolHandler;
    $handler->execute($team, $conversation->id, [
        'topics' => [
            ['title' => 'A Topic', 'angle' => 'An angle'],
        ],
    ]);

    $topic = $team->topics()->first();
    expect($topic->sources)->toBe([]);
});

test('toolSchema returns valid schema', function () {
    $schema = TopicToolHandler::toolSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('save_topics');
    expect($schema['function']['parameters']['properties']['topics'])->not->toBeEmpty();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
cd marketminded-laravel && sail artisan test --filter=TopicToolHandlerTest
```

Expected: FAIL — class `TopicToolHandler` does not exist.

- [ ] **Step 3: Create TopicToolHandler**

Create `marketminded-laravel/app/Services/TopicToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Topic;

class TopicToolHandler
{
    public function execute(Team $team, int $conversationId, array $data): string
    {
        $savedTitles = [];

        foreach ($data['topics'] ?? [] as $topicData) {
            Topic::create([
                'team_id' => $team->id,
                'conversation_id' => $conversationId,
                'title' => $topicData['title'],
                'angle' => $topicData['angle'] ?? '',
                'sources' => $topicData['sources'] ?? [],
                'status' => 'available',
            ]);

            $savedTitles[] = $topicData['title'];
        }

        return json_encode([
            'status' => 'saved',
            'count' => count($savedTitles),
            'titles' => $savedTitles,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'save_topics',
                'description' => 'Save approved content topics to the team\'s topic backlog. Only call this when the user has approved specific topics.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['topics'],
                    'properties' => [
                        'topics' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['title', 'angle'],
                                'properties' => [
                                    'title' => [
                                        'type' => 'string',
                                        'description' => 'The topic title -- specific and compelling',
                                    ],
                                    'angle' => [
                                        'type' => 'string',
                                        'description' => 'Why this topic fits the brand and what angle to take, 1-2 sentences',
                                    ],
                                    'sources' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                        'description' => 'Research evidence supporting this topic',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```bash
cd marketminded-laravel && sail artisan test --filter=TopicToolHandlerTest
```

Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/TopicToolHandler.php marketminded-laravel/tests/Unit/Services/TopicToolHandlerTest.php
git commit -m "feat: add TopicToolHandler with save_topics tool"
```

---

### Task 3: Update ChatPromptBuilder for Topics

**Files:**
- Modify: `marketminded-laravel/app/Services/ChatPromptBuilder.php:22-31` (tools method) and `128-161` (topicsPrompt method)
- Create: `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTopicsTest.php`

- [ ] **Step 1: Write the failing tests**

Create `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTopicsTest.php`:

```php
<?php

use App\Models\Topic;
use App\Models\User;
use App\Services\ChatPromptBuilder;

test('topics type returns save_topics and fetch_url tools', function () {
    $tools = ChatPromptBuilder::tools('topics');

    $names = collect($tools)->map(fn ($t) => $t['function']['name'])->toArray();
    expect($names)->toContain('save_topics');
    expect($names)->toContain('fetch_url');
});

test('topics prompt includes tool instructions', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('save_topics');
    expect($prompt)->toContain('fetch_url');
    expect($prompt)->toContain('web search');
});

test('topics prompt includes existing backlog titles', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Existing Topic About Privacy',
        'angle' => 'An angle',
        'status' => 'available',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Existing Topic About Privacy');
    expect($prompt)->toContain('existing-topics');
});

test('topics prompt excludes deleted topics from backlog', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Available Topic',
        'angle' => 'Angle',
        'status' => 'available',
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Deleted Topic',
        'angle' => 'Angle',
        'status' => 'deleted',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Available Topic');
    expect($prompt)->not->toContain('Deleted Topic');
});

test('topics prompt still nudges when profile is thin', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('brand knowledge');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
cd marketminded-laravel && sail artisan test --filter=ChatPromptBuilderTopicsTest
```

Expected: First test fails — topics type still returns empty tools array.

- [ ] **Step 3: Update the tools method**

In `marketminded-laravel/app/Services/ChatPromptBuilder.php`, replace the `tools()` method (lines 22-31):

```php
    public static function tools(string $type): array
    {
        return match ($type) {
            'brand' => [
                BrandIntelligenceToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
            'topics' => [
                TopicToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
            default => [],
        };
    }
```

Add the import at the top of the file (after the existing `use App\Models\Team;` line):

```php
use App\Models\Topic;
```

- [ ] **Step 4: Rewrite the topicsPrompt method**

In `marketminded-laravel/app/Services/ChatPromptBuilder.php`, replace the entire `topicsPrompt()` method (lines 128-161):

```php
    private static function topicsPrompt(string $profile, bool $hasProfile, Team $team): string
    {
        $prompt = <<<'PROMPT'
You are a content strategist helping a business owner discover and refine content topics. Be creative, specific, and conversational.

## How to respond
Talk naturally in plain text. Use markdown for readability. Never output raw data structures, JSON, or code.

## Your tools
- save_topics -- save approved topics to the team's backlog. Only call this AFTER the user confirms which topics to save.
- fetch_url -- read a web page to research content ideas
- You also have web search available -- use it to find current trends, news, and content gaps.

## How to work
1. Use web search to research current trends, news, and gaps in the brand's space
2. Propose 3-5 topics as a numbered list. For each topic include:
   - A specific, compelling title
   - Why this topic fits the brand (the angle)
   - What research evidence supports it
3. Wait for the user to tell you which topics to save
4. Call save_topics only with the topics the user approved
5. After saving, ask if they want to explore more or refine saved topics

Topics should be timely, specific, and connected to the brand's positioning. Think like a journalist: what's the story? What's the hook? Avoid generic "Ultimate Guide" filler.

Keep responses conversational. Suggest a few ideas, get feedback, iterate.
PROMPT;

        if (! $hasProfile) {
            $prompt .= <<<'NUDGE'


The brand profile is mostly empty. Before brainstorming topics, suggest the user starts with Build brand knowledge to establish their positioning and audience first. You can still brainstorm if they insist, but the results will be more generic without brand context.
NUDGE;
        }

        // Add existing backlog to avoid duplicates
        $existingTopics = Topic::where('team_id', $team->id)
            ->whereIn('status', ['available', 'used'])
            ->pluck('title')
            ->toArray();

        if (! empty($existingTopics)) {
            $topicList = implode("\n", array_map(fn ($t) => "- {$t}", $existingTopics));
            $prompt .= <<<BACKLOG


## Existing topics in backlog (do not propose duplicates)
<existing-topics>
{$topicList}
</existing-topics>
BACKLOG;
        }

        $prompt .= <<<'PROMPT'


## Brand context (reference data -- do not echo this back)
<brand-profile>
PROMPT;

        $prompt .= $profile;

        $prompt .= <<<'PROMPT'

</brand-profile>
PROMPT;

        return $prompt;
    }
```

- [ ] **Step 5: Update the build method to pass Team to topicsPrompt**

In `marketminded-laravel/app/Services/ChatPromptBuilder.php`, update the `build()` method (lines 9-20). Change line 15 from:

```php
            'topics' => self::topicsPrompt($profile, $hasProfile),
```

to:

```php
            'topics' => self::topicsPrompt($profile, $hasProfile, $team),
```

- [ ] **Step 6: Update the existing ChatPromptBuilderTest**

In `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTest.php`, the test `'returns no custom tools for topics type'` (lines 60-63) now needs to expect tools. Replace it:

```php
test('returns save_topics and fetch_url tools for topics type', function () {
    $tools = ChatPromptBuilder::tools('topics');

    $names = collect($tools)->map(fn ($t) => $t['function']['name'])->toArray();
    expect($names)->toContain('save_topics');
    expect($names)->toContain('fetch_url');
});
```

Also update `'topics type prompt includes brand profile'` test (lines 19-28) since the prompt text changed. Replace it:

```php
test('topics type prompt includes brand profile and tool instructions', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com', 'brand_description' => 'We do stuff']);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('We do stuff');
    expect($prompt)->toContain('save_topics');
});
```

- [ ] **Step 7: Run all prompt builder tests**

Run:
```bash
cd marketminded-laravel && sail artisan test --filter=ChatPromptBuilder
```

Expected: All tests pass (both `ChatPromptBuilderTest` and `ChatPromptBuilderTopicsTest`).

- [ ] **Step 8: Commit**

```bash
git add marketminded-laravel/app/Services/ChatPromptBuilder.php marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTopicsTest.php marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTest.php
git commit -m "feat: add topics tools and prompt to ChatPromptBuilder"
```

---

### Task 4: Chat UI — Sub-card Selection + Tool Executor

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php`

This task adds the `topicsMode` property, sub-card selection UI, and wires `save_topics` into the tool executor.

- [ ] **Step 1: Add the `topicsMode` property and `selectTopicsMode` method**

In `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php`, add after `public bool $isStreaming = false;` (line 23):

```php
    public ?string $topicsMode = null;
```

Add the `selectTopicsMode` method after `selectType()` (after line 38):

```php
    public function selectTopicsMode(string $mode): void
    {
        $this->topicsMode = $mode;

        if ($mode === 'discover') {
            $this->prompt = __('Research current trends and discover content topics for my brand.');
            $this->submitPrompt();
        }
    }
```

- [ ] **Step 2: Add TopicToolHandler to the tool executor**

In the same file, add the import at the top (after the `use App\Services\BrandIntelligenceToolHandler;` line):

```php
use App\Services\TopicToolHandler;
```

In the `ask()` method, add `$topicHandler` instantiation after the `$brandHandler` line (after line 95):

```php
        $topicHandler = new TopicToolHandler;
        $conversation = $this->conversation;
```

Update the `$toolExecutor` closure (lines 98-106) to include `$topicHandler` and `$conversation` in the `use` clause and add the `save_topics` case:

```php
        $toolExecutor = function (string $name, array $args) use ($brandHandler, $topicHandler, $team, $conversation): string {
            if ($name === 'update_brand_intelligence') {
                return $brandHandler->execute($team, $args);
            }
            if ($name === 'save_topics') {
                return $topicHandler->execute($team, $conversation->id, $args);
            }
            if ($name === 'fetch_url') {
                return (new UrlFetcher)->fetch($args['url'] ?? '');
            }
            return "Unknown tool: {$name}";
        };
```

- [ ] **Step 3: Add tool pill labels for save_topics**

In the `streamUI()` method, update the active tool label match (line 238-241). Replace:

```php
            $label = match ($activeTool->name) {
                'fetch_url' => 'Reading ' . ($activeTool->arguments['url'] ?? ''),
                'update_brand_intelligence' => 'Updating brand profile',
                default => $activeTool->name,
            };
```

with:

```php
            $label = match ($activeTool->name) {
                'fetch_url' => 'Reading ' . ($activeTool->arguments['url'] ?? ''),
                'update_brand_intelligence' => 'Updating brand profile',
                'save_topics' => 'Saving topics...',
                default => $activeTool->name,
            };
```

In the `toolPill()` method, update the label match (line 267-270). Replace:

```php
        $label = match ($tool->name) {
            'fetch_url' => 'Read ' . ($tool->arguments['url'] ?? ''),
            'update_brand_intelligence' => 'Updated profile: ' . implode(', ', json_decode($tool->result ?? '{}', true)['sections'] ?? []),
            default => $tool->name,
        };
```

with:

```php
        $label = match ($tool->name) {
            'fetch_url' => 'Read ' . ($tool->arguments['url'] ?? ''),
            'update_brand_intelligence' => 'Updated profile: ' . implode(', ', json_decode($tool->result ?? '{}', true)['sections'] ?? []),
            'save_topics' => 'Saved ' . (json_decode($tool->result ?? '{}', true)['count'] ?? 0) . ' topics',
            default => $tool->name,
        };
```

- [ ] **Step 4: Add saved topic card rendering in streamUI**

In the `streamUI()` method, add a helper call after the tool pills section. After the closing `</div>` of the completed tools block (around line 256), add rendering for saved topic cards.

Add a new private method after `toolPill()` (after line 281):

```php
    private function savedTopicCards(array $completedTools): string
    {
        $html = '';
        foreach ($completedTools as $tool) {
            if ($tool->name !== 'save_topics') {
                continue;
            }
            $result = json_decode($tool->result ?? '{}', true);
            $topics = $tool->arguments['topics'] ?? [];
            foreach ($topics as $topic) {
                $html .= '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">';
                $html .= '<div class="mb-1"><span class="text-xs text-purple-400">&#10003; Saved</span></div>';
                $html .= '<div class="text-sm font-semibold text-zinc-200">' . e($topic['title'] ?? '') . '</div>';
                $html .= '<div class="mt-1 text-xs text-zinc-400">' . e($topic['angle'] ?? '') . '</div>';
                $html .= '</div>';
            }
        }
        return $html;
    }
```

Then update `streamUI()` to call it. In the method body, after writing the completed tool pills (both the `if ($activeTool)` branch and the `elseif (! empty($completedTools))` branch), append the cards. The simplest way: add right before the final `$this->stream(...)` call (line 262):

```php
        // Saved topic cards
        $html .= $this->savedTopicCards($completedTools);
```

- [ ] **Step 5: Add saved topic card rendering in message history**

In the Blade template section, find the tool pills rendering in message history (lines 344-362). After the closing `</div>` of the metadata section but before the closing `</div>` of the assistant message block, add topic card rendering for historical messages.

After the `@endif` for `(!empty($message['metadata']['tools']) || ...)` (line 363), add:

```php
                        {{-- Saved topic cards from history --}}
                        @foreach ($message['metadata']['tools'] ?? [] as $tool)
                            @if ($tool['name'] === 'save_topics')
                                @foreach ($tool['args']['topics'] ?? [] as $topic)
                                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                        <div class="mb-1"><span class="text-xs text-purple-400">&#10003; {{ __('Saved') }}</span></div>
                                        <div class="text-sm font-semibold text-zinc-200">{{ $topic['title'] ?? '' }}</div>
                                        <div class="mt-1 text-xs text-zinc-400">{{ $topic['angle'] ?? '' }}</div>
                                    </div>
                                @endforeach
                            @endif
                        @endforeach
```

- [ ] **Step 6: Also add save_topics pill in message history tool pills**

In the message history tool pills section (lines 346-352), add a case for `save_topics` after the `update_brand_intelligence` elseif:

```php
                                    @elseif ($tool['name'] === 'save_topics')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Saved {{ count($tool['args']['topics'] ?? []) }} topics</span>
```

- [ ] **Step 7: Add the sub-card selection UI**

In the Blade template, find the type selection section (lines 369-394). After this `@if` block, add a new condition for topics sub-cards. Insert right before the closing `</div>` of the messages scroll container (`</div>` at line 395):

```php
            {{-- Topics sub-card selection --}}
            @if ($conversation->type === 'topics' && !$topicsMode && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('How would you like to brainstorm?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose how to discover topics.') }}</flux:subheading>

                    <div class="grid w-full max-w-xl gap-3 sm:grid-cols-2">
                        <button wire:click="selectTopicsMode('discover')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="magnifying-glass" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Auto-discover topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Research trends and discover topics for your brand automatically') }}</flux:text>
                        </button>

                        <button wire:click="selectTopicsMode('conversation')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="chat-bubble-left" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Start a conversation') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Guide the brainstorming with your own direction') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif
```

- [ ] **Step 8: Update composer visibility**

The input/composer section (lines 399-425) currently shows whenever `$conversation->type` is set. It needs to also hide when topics sub-cards are showing. Replace the outer condition (line 399):

```php
    @if ($conversation->type)
```

with:

```php
    @if ($conversation->type && !($conversation->type === 'topics' && !$topicsMode && empty($messages)))
```

- [ ] **Step 9: Test manually in browser**

Run the dev server if not running:
```bash
cd marketminded-laravel && sail npm run dev
```

1. Open the app, go to Create, start a new conversation
2. Select "Brainstorm topics" — should see the two sub-cards (Auto-discover / Start a conversation)
3. Select "Start a conversation" — composer should appear
4. Select "Auto-discover topics" in a new conversation — should auto-send the pre-filled message and start streaming

- [ ] **Step 10: Commit**

```bash
git add marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php
git commit -m "feat: add topics sub-cards, save_topics tool executor, and topic card rendering"
```

---

### Task 5: Topics Page + Route + Sidebar

**Files:**
- Create: `marketminded-laravel/resources/views/pages/teams/⚡topics.blade.php`
- Modify: `marketminded-laravel/routes/web.php:18`
- Modify: `marketminded-laravel/resources/views/layouts/app/sidebar.blade.php:23-28`

- [ ] **Step 1: Create the Topics page**

Create `marketminded-laravel/resources/views/pages/teams/⚡topics.blade.php`:

```php
<?php

use App\Models\Team;
use App\Models\Topic;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function updateScore(int $topicId, int $score): void
    {
        Topic::where('team_id', $this->teamModel->id)
            ->findOrFail($topicId)
            ->update(['score' => max(1, min(10, $score))]);
    }

    public function deleteTopic(int $topicId): void
    {
        Topic::where('team_id', $this->teamModel->id)
            ->findOrFail($topicId)
            ->update(['status' => 'deleted']);

        \Flux\Flux::modal('delete-topic-'.$topicId)->close();
    }

    public function getTopicsProperty()
    {
        return Topic::where('team_id', $this->teamModel->id)
            ->where('status', '!=', 'deleted')
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Topics'));
    }
}; ?>

<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Topics') }}</flux:heading>
            @if ($this->topics->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->topics->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create')" wire:navigate>
            {{ __('New brainstorm') }}
        </flux:button>
    </div>

    <div class="mx-auto max-w-3xl px-6 py-4">
        @if ($this->topics->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="light-bulb" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No topics yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Start a Brainstorm topics conversation to discover content ideas.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create')" wire:navigate>
                        {{ __('New brainstorm') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($this->topics as $topic)
                    <flux:card class="p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <flux:heading class="truncate">{{ $topic->title }}</flux:heading>
                                <flux:text class="mt-1 text-sm text-zinc-500">{{ $topic->angle }}</flux:text>

                                <div class="mt-3 flex items-center gap-4">
                                    {{-- Score slider --}}
                                    <div class="flex items-center gap-2">
                                        <flux:text class="text-xs text-zinc-500">{{ __('Score') }}</flux:text>
                                        <input
                                            type="range"
                                            min="1"
                                            max="10"
                                            value="{{ $topic->score ?? 5 }}"
                                            wire:change="updateScore({{ $topic->id }}, $event.target.value)"
                                            class="h-1.5 w-24 cursor-pointer accent-indigo-500"
                                        />
                                        <flux:text class="w-5 text-xs font-medium text-zinc-400">{{ $topic->score ?? '-' }}</flux:text>
                                    </div>

                                    {{-- Conversation link --}}
                                    @if ($topic->conversation_id)
                                        <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $topic->conversation_id]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300">
                                            <flux:icon name="chat-bubble-left" variant="mini" class="size-3.5" />
                                            {{ __('Chat') }}
                                        </a>
                                    @endif

                                    {{-- Status badge --}}
                                    @if ($topic->status === 'used')
                                        <flux:badge variant="pill" size="sm" color="green">{{ __('Used') }}</flux:badge>
                                    @endif
                                </div>
                            </div>

                            <flux:modal.trigger :name="'delete-topic-'.$topic->id">
                                <flux:button variant="ghost" size="xs" icon="trash" />
                            </flux:modal.trigger>
                        </div>
                    </flux:card>

                    <flux:modal :name="'delete-topic-'.$topic->id" class="min-w-[22rem]">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Delete topic?') }}</flux:heading>
                                <flux:text class="mt-2">{{ __('This topic will be removed from your backlog.') }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:spacer />
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="deleteTopic({{ $topic->id }})">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endforeach
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 2: Add the route**

In `marketminded-laravel/routes/web.php`, add after the `create/{conversation}` route (after line 18):

```php
        Route::livewire('topics', 'pages::teams.topics')->name('topics');
```

- [ ] **Step 3: Add the sidebar item**

In `marketminded-laravel/resources/views/layouts/app/sidebar.blade.php`, add after the Brand Intelligence sidebar item (after line 22):

```php
                    <flux:sidebar.item icon="light-bulb" :href="route('topics')" :current="request()->routeIs('topics')" wire:navigate>
                        {{ __('Topics') }}
                    </flux:sidebar.item>
```

- [ ] **Step 4: Test manually in browser**

1. Open the app — "Topics" should appear in the sidebar between Brand Intelligence and AI Operations
2. Click Topics — should show empty state with "No topics yet" and a "New brainstorm" button
3. Go to Create → Brainstorm topics → Start a conversation → ask the AI to propose topics → tell it to save some
4. Go back to Topics page — saved topics should appear with title, angle, score slider, and chat link
5. Test the score slider — drag it, value should update
6. Test delete — click trash icon, confirm, topic should disappear

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/resources/views/pages/teams/⚡topics.blade.php marketminded-laravel/routes/web.php marketminded-laravel/resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add Topics page with scoring, delete, and conversation links"
```

---

### Task 6: Run Full Test Suite

**Files:** None (verification only)

- [ ] **Step 1: Run the full test suite**

Run:
```bash
cd marketminded-laravel && sail artisan test
```

Expected: All tests pass. If any pre-existing test broke (especially `ChatPromptBuilderTest` test `'returns no custom tools for topics type'`), it was already updated in Task 3 Step 6. Verify no other regressions.

- [ ] **Step 2: Fix any failures**

If any test fails, read the error and fix. The most likely failure is the old `ChatPromptBuilderTest` assertion that topics returns no tools — this was updated in Task 3.

- [ ] **Step 3: Commit any fixes**

Only if fixes were needed:
```bash
git add -A
git commit -m "fix: resolve test failures from topic generator changes"
```
