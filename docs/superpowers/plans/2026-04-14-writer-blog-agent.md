# Writer Blog Agent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a blog-writing chat agent to MarketMinded. The writer requires a linked Topic, runs `research_topic -> create_outline -> write_blog_post` in autopilot or checkpoint mode, produces a versioned `ContentPiece`, and revises via an `update_blog_post` tool.

**Architecture:** Tool-driven chat (no separate pipeline engine). New `writer` conversation type with four tools. `write_blog_post` hard-gates on the presence of prior tool outputs in the conversation. Versioning lives on a `content_piece_versions` table; `ContentPiece::saveSnapshot()` keeps the piece's current state and the latest version row in sync.

**Tech Stack:** Laravel 13, Livewire / Volt inline components, Flux UI Pro, OpenRouter (via existing `OpenRouterClient::streamChatWithTools()`), Pest for tests, SQLite in tests (`DB_DATABASE=testing`).

**Spec:** `docs/superpowers/specs/2026-04-14-writer-blog-agent-design.md`

**Working directory convention:** All Laravel paths in this plan are relative to the `marketminded-laravel/` subdirectory of the repo. When a step says "run: `php artisan ...`", run it from inside `marketminded-laravel/`.

---

## File Structure

### Create

- `marketminded-laravel/database/migrations/<ts>_create_content_pieces_table.php`
- `marketminded-laravel/database/migrations/<ts>_create_content_piece_versions_table.php`
- `marketminded-laravel/database/migrations/<ts>_add_writer_fields_to_conversations_table.php`
- `marketminded-laravel/app/Models/ContentPiece.php`
- `marketminded-laravel/app/Models/ContentPieceVersion.php`
- `marketminded-laravel/app/Services/ResearchTopicToolHandler.php`
- `marketminded-laravel/app/Services/CreateOutlineToolHandler.php`
- `marketminded-laravel/app/Services/WriteBlogPostToolHandler.php`
- `marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php`
- `marketminded-laravel/resources/views/pages/teams/⚡content-piece.blade.php`
- `marketminded-laravel/resources/views/pages/teams/⚡content.blade.php`
- `marketminded-laravel/tests/Unit/Models/ContentPieceTest.php`
- `marketminded-laravel/tests/Unit/Services/ResearchTopicToolHandlerTest.php`
- `marketminded-laravel/tests/Unit/Services/CreateOutlineToolHandlerTest.php`
- `marketminded-laravel/tests/Unit/Services/WriteBlogPostToolHandlerTest.php`
- `marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php`
- `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderWriterTest.php`

### Modify

- `marketminded-laravel/app/Models/Conversation.php` (add `topic()`, `contentPieces()`, fillable for `writer_mode`/`topic_id`)
- `marketminded-laravel/app/Models/Team.php` (add `contentPieces()`)
- `marketminded-laravel/app/Models/Topic.php` (add `contentPieces()`)
- `marketminded-laravel/app/Services/ChatPromptBuilder.php` (add `writer` case; remove `write`)
- `marketminded-laravel/resources/views/pages/teams/⚡create.blade.php` (swap Write card for "Write a blog post")
- `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php` (topic picker, mode sub-cards, command interception, new tool dispatch, content piece cards, mode badge)
- `marketminded-laravel/routes/web.php` (add content + content piece routes)
- `marketminded-laravel/resources/views/layouts/app/sidebar.blade.php` (add "Content" nav item)

---

## Task 1: Create `content_pieces` and `content_piece_versions` tables with models

**Files:**
- Create: `marketminded-laravel/database/migrations/<ts>_create_content_pieces_table.php`
- Create: `marketminded-laravel/database/migrations/<ts>_create_content_piece_versions_table.php`
- Create: `marketminded-laravel/app/Models/ContentPiece.php`
- Create: `marketminded-laravel/app/Models/ContentPieceVersion.php`
- Test: `marketminded-laravel/tests/Unit/Models/ContentPieceTest.php`

- [ ] **Step 1: Generate migrations**

Run (from `marketminded-laravel/`):
```bash
php artisan make:migration create_content_pieces_table
php artisan make:migration create_content_piece_versions_table
```

- [ ] **Step 2: Fill in `create_content_pieces_table`**

Replace generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->string('status', 20)->default('draft');
            $table->string('platform', 20)->default('blog');
            $table->string('format', 30)->default('pillar');
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index('topic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pieces');
    }
};
```

- [ ] **Step 3: Fill in `create_content_piece_versions_table`**

Replace generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_piece_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_piece_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->text('change_description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['content_piece_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_piece_versions');
    }
};
```

- [ ] **Step 4: Write `ContentPieceVersion` model**

Create `app/Models/ContentPieceVersion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_piece_id', 'version', 'title', 'body', 'change_description'])]
class ContentPieceVersion extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ContentPieceVersion $v) {
            $v->created_at ??= now();
        });
    }

    public function contentPiece(): BelongsTo
    {
        return $this->belongsTo(ContentPiece::class);
    }
}
```

- [ ] **Step 5: Write the failing test for `ContentPiece::saveSnapshot`**

Create `tests/Unit/Models/ContentPieceTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\User;

test('saveSnapshot creates v1 and syncs piece fields', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'status' => 'draft',
        'platform' => 'blog',
        'format' => 'pillar',
        'current_version' => 0,
    ]);

    $version = $piece->saveSnapshot('My Title', 'Heading and body text.', 'Initial draft');

    expect($piece->refresh()->current_version)->toBe(1);
    expect($piece->title)->toBe('My Title');
    expect($piece->body)->toBe('Heading and body text.');

    expect($version->version)->toBe(1);
    expect($version->title)->toBe('My Title');
    expect($version->body)->toBe('Heading and body text.');
    expect($version->change_description)->toBe('Initial draft');
    expect($version->content_piece_id)->toBe($piece->id);
});

test('saveSnapshot increments version and records change_description', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);

    $piece->saveSnapshot('First', 'Body 1', 'Initial draft');
    $piece->saveSnapshot('Second', 'Body 2', 'Punched up intro');
    $piece->saveSnapshot('Third', 'Body 3', 'Tightened section 3');

    expect($piece->refresh()->current_version)->toBe(3);
    expect($piece->title)->toBe('Third');
    expect($piece->body)->toBe('Body 3');

    $versions = $piece->versions()->orderBy('version')->get();
    expect($versions)->toHaveCount(3);
    expect($versions->pluck('change_description')->all())
        ->toBe(['Initial draft', 'Punched up intro', 'Tightened section 3']);
});

test('versions relationship orders newest first by default', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);

    $piece->saveSnapshot('v1', 'b1');
    $piece->saveSnapshot('v2', 'b2');
    $piece->saveSnapshot('v3', 'b3');

    $versions = $piece->versions()->get();
    expect($versions->pluck('version')->all())->toBe([3, 2, 1]);
});
```

- [ ] **Step 6: Run the test to verify it fails**

Run (from `marketminded-laravel/`):
```bash
php artisan migrate --database=testing
php artisan test --filter=ContentPieceTest
```
Expected: FAIL (`ContentPiece` class does not exist).

- [ ] **Step 7: Write `ContentPiece` model**

Create `app/Models/ContentPiece.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'team_id',
    'conversation_id',
    'topic_id',
    'title',
    'body',
    'status',
    'platform',
    'format',
    'current_version',
])]
class ContentPiece extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentPieceVersion::class)->orderByDesc('version');
    }

    /**
     * Atomically snapshot a new version and update the piece's current state.
     */
    public function saveSnapshot(string $title, string $body, ?string $changeDescription = null): ContentPieceVersion
    {
        return DB::transaction(function () use ($title, $body, $changeDescription) {
            $this->current_version = $this->current_version + 1;
            $this->title = $title;
            $this->body = $body;
            $this->save();

            return $this->versions()->create([
                'version' => $this->current_version,
                'title' => $title,
                'body' => $body,
                'change_description' => $changeDescription,
            ]);
        });
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run:
```bash
php artisan test --filter=ContentPieceTest
```
Expected: PASS, 3 tests.

- [ ] **Step 9: Run full test suite to ensure nothing broke**

Run:
```bash
php artisan test
```
Expected: all existing tests still pass.

- [ ] **Step 10: Commit**

```bash
git add marketminded-laravel/database/migrations/*_create_content_pieces_table.php \
        marketminded-laravel/database/migrations/*_create_content_piece_versions_table.php \
        marketminded-laravel/app/Models/ContentPiece.php \
        marketminded-laravel/app/Models/ContentPieceVersion.php \
        marketminded-laravel/tests/Unit/Models/ContentPieceTest.php
git commit -m "feat: add ContentPiece and ContentPieceVersion models with saveSnapshot"
```

---

## Task 2: Add `writer_mode` and `topic_id` to `conversations`, extend `Conversation` model

**Files:**
- Create: `marketminded-laravel/database/migrations/<ts>_add_writer_fields_to_conversations_table.php`
- Modify: `marketminded-laravel/app/Models/Conversation.php`
- Test: extend `marketminded-laravel/tests/Unit/Models/ConversationTest.php` (already exists -- see below)

- [ ] **Step 1: Generate migration**

Run (from `marketminded-laravel/`):
```bash
php artisan make:migration add_writer_fields_to_conversations_table
```

- [ ] **Step 2: Fill migration**

Replace generated file contents:

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
            $table->string('writer_mode', 20)->nullable()->after('type');
            $table->foreignId('topic_id')->nullable()->after('writer_mode')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topic_id');
            $table->dropColumn('writer_mode');
        });
    }
};
```

- [ ] **Step 3: Write failing tests for Conversation relationships and fillable**

Read the existing `tests/Unit/Models/ConversationTest.php` first (so you preserve its current tests), then append:

```php
test('Conversation topic() returns linked topic', function () {
    $user = \App\Models\User::factory()->create();
    $team = $user->currentTeam;

    $topic = \App\Models\Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy angle',
        'status' => 'available',
    ]);

    $conversation = \App\Models\Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'writer_mode' => 'autopilot',
    ]);

    expect($conversation->topic)->not->toBeNull();
    expect($conversation->topic->id)->toBe($topic->id);
    expect($conversation->writer_mode)->toBe('autopilot');
});

test('Conversation contentPieces() returns linked pieces', function () {
    $user = \App\Models\User::factory()->create();
    $team = $user->currentTeam;

    $conversation = \App\Models\Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
    ]);

    \App\Models\ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'title' => 'Piece',
        'body' => 'body',
    ]);

    expect($conversation->contentPieces)->toHaveCount(1);
});
```

- [ ] **Step 4: Run test to verify it fails**

Run:
```bash
php artisan migrate --database=testing
php artisan test --filter=ConversationTest
```
Expected: FAIL on the new tests (`topic` / `writer_mode` / `contentPieces` not available yet).

- [ ] **Step 5: Update `Conversation` model**

Replace `app/Models/Conversation.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'user_id', 'title', 'type', 'writer_mode', 'topic_id'])]
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

- [ ] **Step 6: Run test to verify it passes**

Run:
```bash
php artisan test --filter=ConversationTest
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add marketminded-laravel/database/migrations/*_add_writer_fields_to_conversations_table.php \
        marketminded-laravel/app/Models/Conversation.php \
        marketminded-laravel/tests/Unit/Models/ConversationTest.php
git commit -m "feat: add writer_mode and topic_id to conversations"
```

---

## Task 3: Add `contentPieces()` relationship to `Team` and `Topic`

**Files:**
- Modify: `marketminded-laravel/app/Models/Team.php`
- Modify: `marketminded-laravel/app/Models/Topic.php`

No dedicated test -- relationships are exercised by downstream tool handler tests.

- [ ] **Step 1: Add `contentPieces()` to `Team`**

In `app/Models/Team.php`, after the `topics()` relationship (around line 149), add:

```php
    /**
     * Get all content pieces for this team.
     *
     * @return HasMany<ContentPiece, $this>
     */
    public function contentPieces(): HasMany
    {
        return $this->hasMany(ContentPiece::class)->orderByDesc('updated_at');
    }
```

- [ ] **Step 2: Add `contentPieces()` to `Topic`**

Replace `app/Models/Topic.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function contentPieces(): HasMany
    {
        return $this->hasMany(ContentPiece::class);
    }
}
```

- [ ] **Step 3: Run full test suite**

Run:
```bash
php artisan test
```
Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add marketminded-laravel/app/Models/Team.php marketminded-laravel/app/Models/Topic.php
git commit -m "feat: add contentPieces relationship to Team and Topic"
```

---

## Task 4: `ResearchTopicToolHandler`

**Files:**
- Create: `marketminded-laravel/app/Services/ResearchTopicToolHandler.php`
- Test: `marketminded-laravel/tests/Unit/Services/ResearchTopicToolHandlerTest.php`

The handler is simple: the LLM submits a structured claims bundle. The handler validates shape and returns a JSON result. The actual persistence for retrieval is via the message `metadata.tools` bucket (already handled by `⚡create-chat.blade.php`'s `ask()` method -- tool args get stored on the message). The result includes a summary so other tools can validate claim IDs.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/ResearchTopicToolHandlerTest.php`:

```php
<?php

use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\ResearchTopicToolHandler;

test('execute returns ok with claim count', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy-first marketing',
        'status' => 'available',
    ]);

    $handler = new ResearchTopicToolHandler;
    $result = $handler->execute($team, 123, [
        'topic_summary' => 'How zero-party data reshapes marketing.',
        'claims' => [
            ['id' => 'c1', 'text' => 'Consumers trust brands that ask.', 'sources' => [
                ['url' => 'https://example.com/a', 'title' => 'Source A'],
            ]],
            ['id' => 'c2', 'text' => 'Third-party cookies are going away.', 'sources' => [
                ['url' => 'https://example.com/b', 'title' => 'Source B'],
            ]],
        ],
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['claim_count'])->toBe(2);
});

test('execute rejects claims missing id or text', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'T',
        'angle' => 'a',
        'status' => 'available',
    ]);

    $handler = new ResearchTopicToolHandler;
    $result = $handler->execute($team, 123, [
        'topic_summary' => 'summary',
        'claims' => [
            ['id' => 'c1'],
        ],
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('text');
});

test('toolSchema returns valid schema', function () {
    $schema = ResearchTopicToolHandler::toolSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('research_topic');
    expect($schema['function']['parameters']['required'])->toContain('claims');
    expect($schema['function']['parameters']['required'])->toContain('topic_summary');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=ResearchTopicToolHandlerTest
```
Expected: FAIL (`ResearchTopicToolHandler` not found).

- [ ] **Step 3: Write `ResearchTopicToolHandler`**

Create `app/Services/ResearchTopicToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Topic;

class ResearchTopicToolHandler
{
    public function execute(Team $team, int $conversationId, array $data, ?Topic $topic = null): string
    {
        $claims = $data['claims'] ?? [];

        foreach ($claims as $i => $claim) {
            if (empty($claim['id']) || empty($claim['text'])) {
                return json_encode([
                    'status' => 'error',
                    'message' => "Claim at index {$i} is missing required fields: id and text.",
                ]);
            }
        }

        return json_encode([
            'status' => 'ok',
            'claim_count' => count($claims),
            'topic_summary' => $data['topic_summary'] ?? '',
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'research_topic',
                'description' => 'Submit structured claims for the blog post, sourced from web search. Call this AFTER using web search and BEFORE create_outline.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['topic_summary', 'claims'],
                    'properties' => [
                        'topic_summary' => [
                            'type' => 'string',
                            'description' => '2-3 sentence summary of what this piece is about.',
                        ],
                        'queries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'The web search queries you ran (for audit).',
                        ],
                        'claims' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'text', 'sources'],
                                'properties' => [
                                    'id' => [
                                        'type' => 'string',
                                        'description' => 'Short slug like c1, c2, c3.',
                                    ],
                                    'text' => [
                                        'type' => 'string',
                                        'description' => 'The verified factual claim in a single sentence.',
                                    ],
                                    'sources' => [
                                        'type' => 'array',
                                        'minItems' => 1,
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['url', 'title'],
                                            'properties' => [
                                                'url' => ['type' => 'string'],
                                                'title' => ['type' => 'string'],
                                            ],
                                        ],
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

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php artisan test --filter=ResearchTopicToolHandlerTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/ResearchTopicToolHandler.php \
        marketminded-laravel/tests/Unit/Services/ResearchTopicToolHandlerTest.php
git commit -m "feat: add ResearchTopicToolHandler"
```

---

## Task 5: `CreateOutlineToolHandler` (validates claim IDs against prior research)

**Files:**
- Create: `marketminded-laravel/app/Services/CreateOutlineToolHandler.php`
- Test: `marketminded-laravel/tests/Unit/Services/CreateOutlineToolHandlerTest.php`

The handler walks the conversation's message history looking for the most recent `research_topic` tool call (stored in `message.metadata.tools`), extracts the claim IDs from its args, and confirms every `claim_ids` referenced in the outline exists there.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/CreateOutlineToolHandlerTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\CreateOutlineToolHandler;

function makeWriterConversation(): Conversation
{
    $user = User::factory()->create();
    return Conversation::create([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'title' => 'W',
        'type' => 'writer',
    ]);
}

function addResearchMessage(Conversation $c, array $claimIds): void
{
    Message::create([
        'conversation_id' => $c->id,
        'role' => 'assistant',
        'content' => '',
        'metadata' => [
            'tools' => [[
                'name' => 'research_topic',
                'args' => [
                    'topic_summary' => 's',
                    'claims' => array_map(fn($id) => [
                        'id' => $id,
                        'text' => 't',
                        'sources' => [['url' => 'u', 'title' => 't']],
                    ], $claimIds),
                ],
            ]],
        ],
    ]);
}

test('execute accepts outline when all claim_ids exist in prior research', function () {
    $c = makeWriterConversation();
    addResearchMessage($c, ['c1', 'c2', 'c3']);

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 'Intro to Zero Party Data',
        'angle' => 'Privacy-first wins long-term',
        'target_length_words' => 1500,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'Hook', 'claim_ids' => ['c1']],
            ['heading' => 'Body', 'purpose' => 'Evidence', 'claim_ids' => ['c2', 'c3']],
        ],
    ]);

    expect(json_decode($result, true)['status'])->toBe('ok');
});

test('execute rejects outline when claim_ids are missing', function () {
    $c = makeWriterConversation();
    addResearchMessage($c, ['c1']);

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 'x',
        'angle' => 'y',
        'target_length_words' => 1200,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1', 'c9']],
        ],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('c9');
});

test('execute errors when no research_topic output exists', function () {
    $c = makeWriterConversation();

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 'x',
        'angle' => 'y',
        'target_length_words' => 1200,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
        ],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research_topic');
});

test('toolSchema returns valid schema', function () {
    $schema = CreateOutlineToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('create_outline');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=CreateOutlineToolHandlerTest
```
Expected: FAIL (class not found).

- [ ] **Step 3: Write `CreateOutlineToolHandler`**

Create `app/Services/CreateOutlineToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Team;

class CreateOutlineToolHandler
{
    public function execute(Team $team, int $conversationId, array $data): string
    {
        $knownIds = $this->latestResearchClaimIds($conversationId);

        if ($knownIds === null) {
            return json_encode([
                'status' => 'error',
                'message' => 'No research_topic output found in this conversation. Call research_topic first.',
            ]);
        }

        $unknown = [];
        foreach ($data['sections'] ?? [] as $section) {
            foreach ($section['claim_ids'] ?? [] as $id) {
                if (! in_array($id, $knownIds, true) && ! in_array($id, $unknown, true)) {
                    $unknown[] = $id;
                }
            }
        }

        if (! empty($unknown)) {
            return json_encode([
                'status' => 'error',
                'message' => 'Unknown claim IDs: ' . implode(', ', $unknown),
            ]);
        }

        return json_encode([
            'status' => 'ok',
            'section_count' => count($data['sections'] ?? []),
        ]);
    }

    /**
     * @return array<string>|null  null when no research_topic output found
     */
    private function latestResearchClaimIds(int $conversationId): ?array
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->get();

        foreach ($messages as $m) {
            foreach ($m->metadata['tools'] ?? [] as $tool) {
                if (($tool['name'] ?? null) === 'research_topic') {
                    $claims = $tool['args']['claims'] ?? [];
                    return array_map(fn ($c) => (string) ($c['id'] ?? ''), $claims);
                }
            }
        }
        return null;
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_outline',
                'description' => 'Create the editorial outline. Each section must reference claim IDs from the research_topic output. Call this AFTER research_topic and BEFORE write_blog_post.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'angle', 'sections', 'target_length_words'],
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Working title'],
                        'angle' => ['type' => 'string', 'description' => 'Angle/positioning'],
                        'target_length_words' => ['type' => 'integer', 'description' => 'Target word count (1200-2000 typical).'],
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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php artisan test --filter=CreateOutlineToolHandlerTest
```
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/CreateOutlineToolHandler.php \
        marketminded-laravel/tests/Unit/Services/CreateOutlineToolHandlerTest.php
git commit -m "feat: add CreateOutlineToolHandler with claim ID validation"
```

---

## Task 6: `WriteBlogPostToolHandler` (with hard gate)

**Files:**
- Create: `marketminded-laravel/app/Services/WriteBlogPostToolHandler.php`
- Test: `marketminded-laravel/tests/Unit/Services/WriteBlogPostToolHandlerTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/WriteBlogPostToolHandlerTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Services\WriteBlogPostToolHandler;

function writerConversationWithTopic(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy angle',
        'status' => 'available',
    ]);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'W',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'writer_mode' => 'autopilot',
    ]);
    return [$team, $conversation, $topic];
}

function addToolMessage(Conversation $c, string $toolName, array $args = []): void
{
    Message::create([
        'conversation_id' => $c->id,
        'role' => 'assistant',
        'content' => '',
        'metadata' => [
            'tools' => [['name' => $toolName, 'args' => $args]],
        ],
    ]);
}

test('write_blog_post gates on missing research_topic', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'create_outline');

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'T',
        'body' => 'B',
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research_topic');
});

test('write_blog_post gates on missing create_outline', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'research_topic');

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'T',
        'body' => 'B',
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('create_outline');
});

test('write_blog_post creates content piece with v1 when prerequisites met', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'research_topic');
    addToolMessage($conversation, 'create_outline');

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'The Case for Zero Party Data',
        'body' => "# Intro\n\nBody.",
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['version'])->toBe(1);

    $piece = ContentPiece::findOrFail($decoded['content_piece_id']);
    expect($piece->title)->toBe('The Case for Zero Party Data');
    expect($piece->body)->toBe("# Intro\n\nBody.");
    expect($piece->current_version)->toBe(1);
    expect($piece->topic_id)->toBe($topic->id);
    expect($piece->conversation_id)->toBe($conversation->id);
    expect($piece->team_id)->toBe($team->id);
    expect($piece->status)->toBe('draft');
    expect($piece->platform)->toBe('blog');
    expect($piece->format)->toBe('pillar');
    expect($piece->versions()->count())->toBe(1);

    expect($topic->refresh()->status)->toBe('used');
});

test('write_blog_post refuses when piece already exists for conversation', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'research_topic');
    addToolMessage($conversation, 'create_outline');

    $handler = new WriteBlogPostToolHandler;
    $first = $handler->execute($team, $conversation->id, ['title' => 'A', 'body' => 'B'], $topic);
    expect(json_decode($first, true)['status'])->toBe('ok');

    $second = $handler->execute($team, $conversation->id, ['title' => 'A2', 'body' => 'B2'], $topic);
    $decoded = json_decode($second, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('update_blog_post');
});

test('toolSchema returns valid schema', function () {
    $schema = WriteBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('write_blog_post');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=WriteBlogPostToolHandlerTest
```
Expected: FAIL.

- [ ] **Step 3: Write `WriteBlogPostToolHandler`**

Create `app/Services/WriteBlogPostToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Message;
use App\Models\Team;
use App\Models\Topic;

class WriteBlogPostToolHandler
{
    public function execute(Team $team, int $conversationId, array $data, ?Topic $topic = null): string
    {
        $missing = $this->missingPrereqs($conversationId);
        if (! empty($missing)) {
            return json_encode([
                'status' => 'error',
                'message' => 'You must call ' . implode(' and ', $missing) . ' before write_blog_post.',
            ]);
        }

        if (ContentPiece::where('conversation_id', $conversationId)->exists()) {
            return json_encode([
                'status' => 'error',
                'message' => 'A blog post already exists for this conversation. Use update_blog_post to revise it.',
            ]);
        }

        $title = $data['title'] ?? '';
        $body = $data['body'] ?? '';

        if ($title === '' || $body === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'title and body are required.',
            ]);
        }

        $piece = ContentPiece::create([
            'team_id' => $team->id,
            'conversation_id' => $conversationId,
            'topic_id' => $topic?->id,
            'title' => '',
            'body' => '',
            'status' => 'draft',
            'platform' => 'blog',
            'format' => 'pillar',
            'current_version' => 0,
        ]);

        $piece->saveSnapshot($title, $body, 'Initial draft');

        if ($topic) {
            $topic->update(['status' => 'used']);
        }

        return json_encode([
            'status' => 'ok',
            'content_piece_id' => $piece->id,
            'title' => $piece->title,
            'version' => $piece->current_version,
        ]);
    }

    /**
     * Returns an array of prerequisite tool names that were not found in the conversation history.
     */
    private function missingPrereqs(int $conversationId): array
    {
        $needed = ['research_topic', 'create_outline'];
        $found = [];

        $messages = Message::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->get();

        foreach ($messages as $m) {
            foreach ($m->metadata['tools'] ?? [] as $tool) {
                $name = $tool['name'] ?? null;
                if (in_array($name, $needed, true)) {
                    $found[$name] = true;
                }
            }
        }

        return array_values(array_diff($needed, array_keys($found)));
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'write_blog_post',
                'description' => 'Produce the final blog post. Requires research_topic and create_outline tool calls earlier in this conversation.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => [
                            'type' => 'string',
                            'description' => 'Full blog post in markdown. 1200-2000 words. Every statistic, percentage, date, named entity, or quote must trace to a claim ID from research_topic.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php artisan test --filter=WriteBlogPostToolHandlerTest
```
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/WriteBlogPostToolHandler.php \
        marketminded-laravel/tests/Unit/Services/WriteBlogPostToolHandlerTest.php
git commit -m "feat: add WriteBlogPostToolHandler with hard gate on prerequisites"
```

---

## Task 7: `UpdateBlogPostToolHandler`

**Files:**
- Create: `marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php`
- Test: `marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/UpdateBlogPostToolHandlerTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\User;
use App\Services\UpdateBlogPostToolHandler;

test('update creates a new version and updates piece state', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('Original', 'Original body', 'Initial draft');

    $handler = new UpdateBlogPostToolHandler;
    $result = $handler->execute($team, 0, [
        'content_piece_id' => $piece->id,
        'title' => 'Original (revised)',
        'body' => 'Original body with a punchier intro.',
        'change_description' => 'Punched up intro',
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['version'])->toBe(2);

    $piece->refresh();
    expect($piece->current_version)->toBe(2);
    expect($piece->title)->toBe('Original (revised)');
    expect($piece->versions()->count())->toBe(2);

    $v2 = $piece->versions()->where('version', 2)->first();
    expect($v2->change_description)->toBe('Punched up intro');
    expect($v2->body)->toBe('Original body with a punchier intro.');
});

test('update rejects piece from another team', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $piece = ContentPiece::create([
        'team_id' => $userA->currentTeam->id,
        'title' => 't',
        'body' => 'b',
        'current_version' => 1,
    ]);

    $handler = new UpdateBlogPostToolHandler;
    $result = $handler->execute($userB->currentTeam, 0, [
        'content_piece_id' => $piece->id,
        'title' => 'hack',
        'body' => 'hack',
        'change_description' => 'x',
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
});

test('toolSchema returns valid schema', function () {
    $schema = UpdateBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('update_blog_post');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=UpdateBlogPostToolHandlerTest
```
Expected: FAIL.

- [ ] **Step 3: Write `UpdateBlogPostToolHandler`**

Create `app/Services/UpdateBlogPostToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Team;

class UpdateBlogPostToolHandler
{
    public function execute(Team $team, int $conversationId, array $data): string
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->find($data['content_piece_id'] ?? 0);

        if (! $piece) {
            return json_encode([
                'status' => 'error',
                'message' => 'Content piece not found.',
            ]);
        }

        $title = $data['title'] ?? '';
        $body = $data['body'] ?? '';

        if ($title === '' || $body === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'title and body are required.',
            ]);
        }

        $piece->saveSnapshot($title, $body, $data['change_description'] ?? null);

        return json_encode([
            'status' => 'ok',
            'content_piece_id' => $piece->id,
            'version' => $piece->current_version,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_blog_post',
                'description' => 'Revise the current blog post based on user feedback. Saves a new version snapshot.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['content_piece_id', 'title', 'body', 'change_description'],
                    'properties' => [
                        'content_piece_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string', 'description' => 'Full revised markdown body.'],
                        'change_description' => [
                            'type' => 'string',
                            'description' => 'Short summary of what changed, e.g. "Punched up intro".',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php artisan test --filter=UpdateBlogPostToolHandlerTest
```
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add marketminded-laravel/app/Services/UpdateBlogPostToolHandler.php \
        marketminded-laravel/tests/Unit/Services/UpdateBlogPostToolHandlerTest.php
git commit -m "feat: add UpdateBlogPostToolHandler"
```

---

## Task 8: Extend `ChatPromptBuilder` with `writer` type, remove generic `write`

**Files:**
- Modify: `marketminded-laravel/app/Services/ChatPromptBuilder.php`
- Test: `marketminded-laravel/tests/Unit/Services/ChatPromptBuilderWriterTest.php`

`ChatPromptBuilder::build()` and `tools()` currently take `(string $type, Team $team)`. For the writer type we also need the `Conversation` (to include the selected Topic, mode, and existing content piece in the prompt). Rather than breaking the signature for all callers, add optional `?Conversation $conversation = null` as a third parameter on `build()` and ignore it for non-writer types.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Services/ChatPromptBuilderWriterTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\ChatPromptBuilder;

function writerContext(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy-first positioning',
        'sources' => ['Source A'],
        'status' => 'available',
    ]);

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'writer_mode' => 'autopilot',
    ]);

    return [$team, $conversation, $topic];
}

test('writer prompt includes topic, mode, and tool-order rule', function () {
    [$team, $conversation] = writerContext();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('research_topic');
    expect($prompt)->toContain('create_outline');
    expect($prompt)->toContain('write_blog_post');
    expect($prompt)->toContain('<topic>');
    expect($prompt)->toContain('Zero Party Data');
    expect($prompt)->toContain('<mode>autopilot</mode>');
});

test('checkpoint mode is reflected in prompt', function () {
    [$team, $conversation] = writerContext();
    $conversation->update(['writer_mode' => 'checkpoint']);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation->refresh());

    expect($prompt)->toContain('<mode>checkpoint</mode>');
    expect($prompt)->toContain('Pause');
});

test('current content piece is included when present', function () {
    [$team, $conversation] = writerContext();

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'title' => 'Draft title',
        'body' => 'Draft body',
        'current_version' => 1,
    ]);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('<current-content-piece>');
    expect($prompt)->toContain('Draft title');
    expect($prompt)->toContain((string) $piece->id);
});

test('tools(writer) returns the four writer tools plus fetch_url', function () {
    $tools = ChatPromptBuilder::tools('writer');
    $names = collect($tools)->pluck('function.name')->all();

    expect($names)->toContain('research_topic');
    expect($names)->toContain('create_outline');
    expect($names)->toContain('write_blog_post');
    expect($names)->toContain('update_blog_post');
    expect($names)->toContain('fetch_url');
});

test('write type is removed', function () {
    $tools = ChatPromptBuilder::tools('write');
    expect($tools)->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=ChatPromptBuilderWriterTest
```
Expected: FAIL.

- [ ] **Step 3: Modify `ChatPromptBuilder`**

Open `app/Services/ChatPromptBuilder.php` and apply these edits:

1. Update the class imports and add `use App\Models\Conversation;` and `use App\Models\ContentPiece;`.
2. Replace the `build()` method signature:

```php
public static function build(string $type, Team $team, ?Conversation $conversation = null): string
```

3. Replace the `match` expression inside `build()`:

```php
return match ($type) {
    'brand' => self::brandPrompt($profile),
    'topics' => self::topicsPrompt($profile, $hasProfile, $team),
    'writer' => self::writerPrompt($profile, $hasProfile, $conversation),
    default => 'You are a helpful AI assistant.',
};
```

4. Replace the `tools()` method body:

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
        'writer' => [
            ResearchTopicToolHandler::toolSchema(),
            CreateOutlineToolHandler::toolSchema(),
            WriteBlogPostToolHandler::toolSchema(),
            UpdateBlogPostToolHandler::toolSchema(),
            BrandIntelligenceToolHandler::fetchUrlToolSchema(),
        ],
        default => [],
    };
}
```

5. Delete the existing `writePrompt()` method entirely (it is being replaced).

6. Add the new `writerPrompt()` method at the bottom of the class:

```php
private static function writerPrompt(string $profile, bool $hasProfile, ?Conversation $conversation): string
{
    $mode = $conversation?->writer_mode ?? 'autopilot';
    $topic = $conversation?->topic;

    $modeSection = $mode === 'checkpoint'
        ? "## Mode: Checkpoint\nAfter research_topic, pause and summarize the claims so the user can approve or redirect. After create_outline, pause and summarize the outline. Only call write_blog_post once the user approves the outline."
        : "## Mode: Autopilot\nRun research_topic -> create_outline -> write_blog_post sequentially without pausing. Brief status messages between steps are fine; do not ask for approval. After write_blog_post, report the result and invite the user to review.";

    $topicBlock = '';
    if ($topic) {
        $sources = is_array($topic->sources) ? implode("\n- ", $topic->sources) : '';
        $topicBlock = <<<TOPIC

## Topic (required context)
<topic>
Title: {$topic->title}
Angle: {$topic->angle}
Sources from brainstorm:
- {$sources}
</topic>

TOPIC;
    }

    $contentPieceBlock = '';
    if ($conversation) {
        $piece = ContentPiece::where('conversation_id', $conversation->id)->first();
        if ($piece) {
            $contentPieceBlock = <<<PIECE

## Current content piece
<current-content-piece>
id: {$piece->id}
title: {$piece->title}
version: v{$piece->current_version}

{$piece->body}
</current-content-piece>

When the user asks for changes, call update_blog_post with content_piece_id={$piece->id}. Never create a new piece -- update this one.

PIECE;
        }
    }

    $prompt = <<<PROMPT
You are a skilled blog writer producing cornerstone content. Your job is to research a topic, outline it, write it, and revise it based on user feedback. All factual claims in the final piece must come from the research step.

## Tool order (hard rule)
You MUST call tools in this order: research_topic -> create_outline -> write_blog_post. The write_blog_post tool will refuse to run otherwise. Use update_blog_post ONLY after write_blog_post has produced a piece.

## Your tools
- research_topic -- submit structured claims sourced via web search (REQUIRED first)
- create_outline -- produce an editorial outline referencing claim IDs (REQUIRED second)
- write_blog_post -- produce the final piece (REQUIRED third, gated)
- update_blog_post -- revise the piece after it exists
- fetch_url -- read a web page
- web search -- use this BEFORE research_topic to gather sources

<mode>{$mode}</mode>

{$modeSection}

## Writing rules
- 1200-2000 words for pillar blog posts
- EVERY statistic, percentage, date, named entity, or quote must come from a claim ID submitted via research_topic. No fabrication.
- Banned words/phrases: "leverage", "innovative", "streamline", "unlock", "empower", "revolutionize", "in today's fast-paced world". Avoid em-dashes used stylistically and passive voice as the default.
- Short paragraphs, scannable subheadings, benefit-focused structure.
- Headlines like "Achieve X without Y", "Stop Z. Start W.", "Never X again" work well.
- Match the brand voice from the brand profile below without copying it verbatim.

## Revision behaviour
When the user gives feedback on an existing piece, call update_blog_post with a clear change_description. Do not re-run research or re-outline.
{$topicBlock}
{$contentPieceBlock}
## Brand context (reference data -- do not echo this back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;

    if (! $hasProfile) {
        $prompt .= "\n\nThe brand profile is mostly empty. The piece will be generic without positioning, audience, and voice data. Suggest Build brand knowledge before writing if the user has not set up the profile.";
    }

    return $prompt;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
php artisan test --filter=ChatPromptBuilderWriterTest
```
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full suite**

Run:
```bash
php artisan test
```
Expected: all tests pass. (Existing `ChatPromptBuilderTest` may test the `write` type -- if so, update it to match the new behaviour or remove those specific assertions.)

- [ ] **Step 6: Commit**

```bash
git add marketminded-laravel/app/Services/ChatPromptBuilder.php \
        marketminded-laravel/tests/Unit/Services/ChatPromptBuilderWriterTest.php \
        marketminded-laravel/tests/Unit/Services/ChatPromptBuilderTest.php
git commit -m "feat: add writer prompt/tools to ChatPromptBuilder, remove generic write"
```

---

## Task 9: Chat component -- Topic picker and mode sub-cards for `writer` type

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php`

This task wires the two pre-chat screens for the writer type: first the Topic picker (mandatory), then the Autopilot/Checkpoint sub-cards. No tool calls yet. After the mode is chosen, the composer appears.

- [ ] **Step 1: Add component properties and methods**

In `⚡create-chat.blade.php`, inside the anonymous Component class, after `public ?string $topicsMode = null;` (line 26), add:

```php
    public ?int $topicId = null;

    public ?string $writerMode = null;
```

In the `mount()` method, after `$this->loadMessages();`, add:

```php
        $this->topicId = $this->conversation->topic_id;
        $this->writerMode = $this->conversation->writer_mode;
```

After the existing `selectTopicsMode()` method (around line 51), add:

```php
    public function selectWriterTopic(int $topicId): void
    {
        $topic = \App\Models\Topic::where('team_id', $this->teamModel->id)
            ->where('status', 'available')
            ->findOrFail($topicId);

        $this->conversation->update(['topic_id' => $topic->id]);
        $this->conversation->refresh();
        $this->topicId = $topic->id;
    }

    public function selectWriterMode(string $mode): void
    {
        if (! in_array($mode, ['autopilot', 'checkpoint'], true)) {
            return;
        }

        $this->conversation->update(['writer_mode' => $mode]);
        $this->conversation->refresh();
        $this->writerMode = $mode;

        $topic = $this->conversation->topic;
        if ($topic) {
            $this->prompt = __('Let\'s write a blog post about: :title', ['title' => $topic->title]);
        }
    }
```

- [ ] **Step 2: Add writer card to the type-selection screen**

Replace the existing "Write content" card (mapping to `write`) with the new "Write a blog post" card (mapping to `writer`). The grid stays at `sm:grid-cols-3` -- three cards: Brand, Topics, Writer.

```blade
{{-- Type selection (no type yet, no messages) --}}
@if (!$conversation->type && empty($messages))
    <div class="flex flex-col items-center justify-center py-16">
        <flux:heading size="xl" class="mb-2">{{ __('What would you like to create?') }}</flux:heading>
        <flux:subheading class="mb-8">{{ __('Choose a mode to get started.') }}</flux:subheading>

        <div class="grid w-full max-w-3xl gap-3 sm:grid-cols-3">
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

            <button wire:click="selectType('writer')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                <flux:icon name="document-text" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                <flux:heading size="sm">{{ __('Write a blog post') }}</flux:heading>
                <flux:text class="mt-1 text-xs">{{ __('Produce a cornerstone blog post grounded in one of your topics') }}</flux:text>
            </button>
        </div>
    </div>
@endif
```

Note: the generic `selectType('write')` card has been removed. The badge map at line 349 in the header also references the `'write'` case -- update it:

```blade
<flux:badge variant="pill" size="sm">{{ match($conversation->type) {
    'brand' => __('Brand Knowledge'),
    'topics' => __('Brainstorm'),
    'writer' => __('Writer'),
    default => $conversation->type,
} }}</flux:badge>
```

Also update `⚡create.blade.php`'s badge match at line 85 in the same way (see Task 13 which swaps the card on that page as well).

- [ ] **Step 3: Add Topic picker and mode sub-cards**

In `⚡create-chat.blade.php`, after the existing `topics` sub-card block that ends around line 495, add:

```blade
{{-- Writer: Topic picker (required before mode) --}}
@if ($conversation->type === 'writer' && !$topicId && empty($messages))
    @php
        $availableTopics = \App\Models\Topic::where('team_id', $teamModel->id)
            ->where('status', 'available')
            ->latest()
            ->get();
    @endphp

    <div class="flex flex-col items-center justify-center py-16">
        <flux:heading size="xl" class="mb-2">{{ __('Pick a topic for this blog post') }}</flux:heading>
        <flux:subheading class="mb-8">{{ __('The writer grounds the post in one of your topics.') }}</flux:subheading>

        @if ($availableTopics->isEmpty())
            <div class="w-full max-w-xl text-center">
                <flux:icon name="light-bulb" class="mx-auto size-10 text-zinc-400" />
                <flux:heading size="sm" class="mt-3">{{ __('No available topics') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Brainstorm topics first, then come back to write one.') }}</flux:subheading>
                <div class="mt-4">
                    <flux:button variant="primary" icon="light-bulb" :href="route('topics', ['current_team' => $teamModel])" wire:navigate>
                        {{ __('Go to Topics') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                @foreach ($availableTopics as $t)
                    <button wire:click="selectWriterTopic({{ $t->id }})" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                        <flux:heading size="sm">{{ $t->title }}</flux:heading>
                        <flux:text class="mt-1 text-xs">{{ $t->angle }}</flux:text>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@endif

{{-- Writer: Mode sub-cards (after topic, before first message) --}}
@if ($conversation->type === 'writer' && $topicId && !$writerMode && empty($messages))
    <div class="flex flex-col items-center justify-center py-16">
        <flux:heading size="xl" class="mb-2">{{ __('How should the writer work?') }}</flux:heading>
        <flux:subheading class="mb-8">{{ __('You can switch modes later with !autopilot or !checkpoint.') }}</flux:subheading>

        <div class="grid w-full max-w-xl gap-3 sm:grid-cols-2">
            <button wire:click="selectWriterMode('autopilot')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                <flux:icon name="bolt" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                <flux:heading size="sm">{{ __('Autopilot') }}</flux:heading>
                <flux:text class="mt-1 text-xs">{{ __('Research, outline, and write in one go') }}</flux:text>
            </button>

            <button wire:click="selectWriterMode('checkpoint')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                <flux:icon name="check-circle" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                <flux:heading size="sm">{{ __('Checkpoint mode') }}</flux:heading>
                <flux:text class="mt-1 text-xs">{{ __('Pause after research and after the outline so you can approve') }}</flux:text>
            </button>
        </div>
    </div>
@endif
```

- [ ] **Step 4: Guard the composer visibility for writer**

The existing composer visibility check (line 500) reads:
```blade
@if ($conversation->type && !($conversation->type === 'topics' && !$topicsMode && empty($messages)))
```

Replace it with:

```blade
@if ($conversation->type
    && !($conversation->type === 'topics' && !$topicsMode && empty($messages))
    && !($conversation->type === 'writer' && (!$topicId || !$writerMode) && empty($messages)))
```

- [ ] **Step 5: Manual verification**

Run:
```bash
php artisan serve
```

In the browser:
1. Go to Create, start a new conversation.
2. Pick "Write a blog post" -- the type should become `writer`.
3. Topic picker appears. If no topics exist: see the empty state; follow the link to Topics and create one; come back.
4. Pick a topic -- mode sub-cards appear.
5. Pick a mode -- composer appears with the pre-filled `Let's write a blog post about: {title}` message.

No LLM calls or tool dispatch yet -- that's the next task. At this point submitting the prompt will just fail gracefully because no tool executor cases are wired.

- [ ] **Step 6: Commit**

```bash
git add marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php
git commit -m "feat: add Topic picker and mode sub-cards for writer chat"
```

---

## Task 10: Chat component -- wire tool executor, command interception, mode badge

**Files:**
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php`

- [ ] **Step 1: Import new handlers and add to tool executor**

In `⚡create-chat.blade.php`, update the imports block at the top:

```php
use App\Services\BrandIntelligenceToolHandler;
use App\Services\ChatPromptBuilder;
use App\Services\CreateOutlineToolHandler;
use App\Services\OpenRouterClient;
use App\Services\ResearchTopicToolHandler;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\TopicToolHandler;
use App\Services\UpdateBlogPostToolHandler;
use App\Services\UrlFetcher;
use App\Services\WriteBlogPostToolHandler;
```

- [ ] **Step 2: Update `ask()` to build the writer system prompt correctly**

Find this line in `ask()` (around line 94):
```php
$systemPrompt = ChatPromptBuilder::build($type, $this->teamModel);
```
Replace it with:
```php
$this->conversation->load('topic');
$systemPrompt = ChatPromptBuilder::build($type, $this->teamModel, $this->conversation);
```

- [ ] **Step 3: Add the new tool handler dispatch**

Find the existing `$toolExecutor` closure (around line 113) and replace the whole closure with:

```php
$researchHandler = new ResearchTopicToolHandler;
$outlineHandler = new CreateOutlineToolHandler;
$writeHandler = new WriteBlogPostToolHandler;
$updateHandler = new UpdateBlogPostToolHandler;

$toolExecutor = function (string $name, array $args) use (
    $brandHandler, $topicHandler, $researchHandler, $outlineHandler,
    $writeHandler, $updateHandler, $team, $conversation
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
        return $researchHandler->execute($team, $conversation->id, $args, $conversation->topic);
    }
    if ($name === 'create_outline') {
        return $outlineHandler->execute($team, $conversation->id, $args);
    }
    if ($name === 'write_blog_post') {
        return $writeHandler->execute($team, $conversation->id, $args, $conversation->topic);
    }
    if ($name === 'update_blog_post') {
        return $updateHandler->execute($team, $conversation->id, $args);
    }
    return "Unknown tool: {$name}";
};
```

- [ ] **Step 4: Intercept `!autopilot` / `!checkpoint` commands in `submitPrompt()`**

At the top of `submitPrompt()`, after the early-return guard (line 57-59), add:

```php
// Writer mode commands intercept
if ($this->conversation->type === 'writer') {
    $lower = strtolower($content);
    if ($lower === '!autopilot' || $lower === '!checkpoint') {
        $mode = ltrim($lower, '!');
        $this->conversation->update(['writer_mode' => $mode]);
        $this->conversation->refresh();
        $this->writerMode = $mode;

        $notice = $mode === 'autopilot'
            ? __('Switched to autopilot mode. I\'ll run research, outline, and write in sequence.')
            : __('Switched to checkpoint mode. I\'ll pause after research and after the outline for your approval.');

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => $notice,
            'metadata' => ['command_result' => true],
        ]);
        $this->messages[] = [
            'role' => 'assistant',
            'content' => $notice,
            'metadata' => ['command_result' => true],
        ];
        $this->prompt = '';
        return;
    }
}
```

- [ ] **Step 5: Add tool pill labels**

Update the `streamUI()` method's active-tool label map (around line 268):

```php
$label = match ($activeTool->name) {
    'fetch_url' => 'Reading ' . ($activeTool->arguments['url'] ?? ''),
    'update_brand_intelligence' => 'Updating brand profile',
    'save_topics' => 'Saving topics...',
    'research_topic' => 'Researching topic...',
    'create_outline' => 'Building outline...',
    'write_blog_post' => 'Writing blog post...',
    'update_blog_post' => 'Revising...',
    default => $activeTool->name,
};
```

Update the `toolPill()` method's completed label map (around line 301):

```php
$label = match ($tool->name) {
    'fetch_url' => 'Read ' . ($tool->arguments['url'] ?? ''),
    'update_brand_intelligence' => 'Updated profile: ' . implode(', ', json_decode($tool->result ?? '{}', true)['sections'] ?? []),
    'save_topics' => 'Saved ' . (json_decode($tool->result ?? '{}', true)['count'] ?? 0) . ' topics',
    'research_topic' => 'Gathered ' . (json_decode($tool->result ?? '{}', true)['claim_count'] ?? 0) . ' claims',
    'create_outline' => 'Outline ready',
    'write_blog_post' => 'Draft created',
    'update_blog_post' => 'Revised (v' . (json_decode($tool->result ?? '{}', true)['version'] ?? '?') . ')',
    default => $tool->name,
};
```

- [ ] **Step 6: Add mode indicator badge in the chat header**

In the header block (around line 346), after the existing type badge:

```blade
@if ($conversation->type === 'writer' && $writerMode)
    <flux:badge variant="pill" size="sm" color="{{ $writerMode === 'autopilot' ? 'indigo' : 'amber' }}">
        {{ $writerMode === 'autopilot' ? __('Autopilot') : __('Checkpoint') }}
    </flux:badge>
@endif
```

- [ ] **Step 7: Manual verification**

Run:
```bash
php artisan serve
```

1. Open a `writer` conversation (created in Task 9).
2. Type `!checkpoint` and submit. The assistant should post "Switched to checkpoint mode..." without calling the LLM; header badge flips to amber "Checkpoint".
3. Type `!autopilot` and submit. Reverts to "Autopilot", header badge goes back to indigo.
4. Submit a real prompt. The AI should call `research_topic`, `create_outline`, `write_blog_post` in order. A content piece row should appear in `content_pieces`. Tool pills and labels render correctly.

- [ ] **Step 8: Commit**

```bash
git add marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php
git commit -m "feat: wire writer tool executor, mode commands, and pill labels"
```

---

## Task 11: Chat component -- inline content piece cards (streaming + history)

**Files:**
- Modify: `marketminded-laravel/routes/web.php`
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php`

Render compact cards in the message stream when `write_blog_post` or `update_blog_post` runs. Cards use `route('content.show', ...)` so we register that route first (the view referenced by the route doesn't need to exist yet -- the route just needs to resolve at render time). The content piece page view itself is created in Task 12.

- [ ] **Step 1: Register the content.show route**

In `marketminded-laravel/routes/web.php`, inside the team-scoped group (after the `topics` route on line 19), add:

```php
            Route::livewire('content/{contentPiece}', 'pages::teams.content-piece')->name('content.show');
```

(Task 13 will add the sibling `content.index` route and the sidebar nav. It's fine to add just `content.show` here.)

- [ ] **Step 2: Add streaming content-piece card renderer**

At the bottom of the anonymous Component class, after `savedTopicCards()`, add:

```php
    private function contentPieceCards(array $completedTools): string
    {
        $html = '';
        foreach ($completedTools as $tool) {
            if (! in_array($tool->name, ['write_blog_post', 'update_blog_post'], true)) {
                continue;
            }
            $result = json_decode($tool->result ?? '{}', true);
            if (($result['status'] ?? '') !== 'ok') {
                continue;
            }
            $piece = \App\Models\ContentPiece::where('team_id', $this->teamModel->id)
                ->find($result['content_piece_id'] ?? 0);
            if (! $piece) {
                continue;
            }
            $url = route('content.show', ['current_team' => $this->teamModel, 'contentPiece' => $piece->id]);
            $preview = trim(mb_substr(strip_tags($piece->body), 0, 200));
            $badge = $tool->name === 'write_blog_post' ? __('Draft created') : __('Revised');

            $html .= '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">';
            $html .= '<div class="flex items-center justify-between mb-1">';
            $html .= '<span class="text-xs text-green-400">&#10003; ' . e($badge) . ' &middot; v' . e($piece->current_version) . '</span>';
            $html .= '<a href="' . e($url) . '" class="text-xs text-indigo-400 hover:text-indigo-300">' . e(__('Open')) . ' &rarr;</a>';
            $html .= '</div>';
            $html .= '<div class="text-sm font-semibold text-zinc-200">' . e($piece->title) . '</div>';
            $html .= '<div class="mt-1 text-xs text-zinc-400 line-clamp-3">' . e($preview) . '</div>';
            $html .= '</div>';
        }
        return $html;
    }
```

- [ ] **Step 3: Call the new renderer from `streamUI()`**

Near the bottom of `streamUI()` (around line 294) replace:
```php
$html .= $this->savedTopicCards($completedTools);
```
with:
```php
$html .= $this->savedTopicCards($completedTools);
$html .= $this->contentPieceCards($completedTools);
```

- [ ] **Step 4: Render content piece cards in message history**

After the "Saved topic cards from history" block (around line 442), add:

```blade
{{-- Content piece cards from history --}}
@foreach ($message['metadata']['tools'] ?? [] as $tool)
    @if ($tool['name'] === 'write_blog_post' || $tool['name'] === 'update_blog_post')
        @php
            // Each writer conversation has at most one piece; history cards reflect its latest state.
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

Note: the history renderer always shows the CURRENT piece state, not a point-in-time version. That matches the spec -- a stale history card should reflect the latest version.

- [ ] **Step 5: Manual verification**

Run `php artisan serve` and exercise the writer chat through a full autopilot run. When `write_blog_post` completes you should see the card inline; ask for an update ("Make the intro punchier.") and confirm a `update_blog_post` card appears with `v2`. Clicking "Open" will 404 until Task 12 lands, which is expected.

- [ ] **Step 6: Commit**

```bash
git add marketminded-laravel/routes/web.php \
        marketminded-laravel/resources/views/pages/teams/⚡create-chat.blade.php
git commit -m "feat: render inline content piece cards in writer chat"
```

---

## Task 12: Content piece page with version history and restore

**Files:**
- Create: `marketminded-laravel/resources/views/pages/teams/⚡content-piece.blade.php`

The `content.show` route was registered in Task 11. This task adds the Volt view that the route points at, making the page reachable.

- [ ] **Step 1: Create the Volt page**

Create `resources/views/pages/teams/⚡content-piece.blade.php`:

```blade
<?php

use App\Models\ContentPiece;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public ContentPiece $contentPiece;

    public ?int $selectedVersion = null;

    public function mount(Team $current_team, ContentPiece $contentPiece): void
    {
        abort_unless($contentPiece->team_id === $current_team->id, 404);

        $this->teamModel = $current_team;
        $this->contentPiece = $contentPiece;
    }

    public function selectVersion(?int $version): void
    {
        $this->selectedVersion = $version;
    }

    public function updateStatus(string $status): void
    {
        if (! in_array($status, ['draft', 'approved', 'archived'], true)) {
            return;
        }
        $this->contentPiece->update(['status' => $status]);
        $this->contentPiece->refresh();
    }

    public function restoreVersion(int $version): void
    {
        $target = $this->contentPiece->versions()->where('version', $version)->firstOrFail();
        $this->contentPiece->saveSnapshot($target->title, $target->body, "Restored from v{$version}");
        $this->contentPiece->refresh();
        $this->selectedVersion = null;

        \Flux\Flux::modal('restore-version-'.$version)->close();
    }

    public function getDisplayedProperty(): array
    {
        if ($this->selectedVersion === null) {
            return [
                'title' => $this->contentPiece->title,
                'body' => $this->contentPiece->body,
                'version' => $this->contentPiece->current_version,
                'is_current' => true,
            ];
        }

        $version = $this->contentPiece->versions()->where('version', $this->selectedVersion)->firstOrFail();

        return [
            'title' => $version->title,
            'body' => $version->body,
            'version' => $version->version,
            'is_current' => $version->version === $this->contentPiece->current_version,
        ];
    }

    public function render()
    {
        return $this->view()->title($this->contentPiece->title);
    }
}; ?>

<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('content.index', ['current_team' => $teamModel])" wire:navigate />
            <flux:heading size="xl" class="truncate max-w-xl">{{ $contentPiece->title }}</flux:heading>
            <flux:badge variant="pill" size="sm" color="{{ $contentPiece->status === 'approved' ? 'green' : ($contentPiece->status === 'archived' ? 'zinc' : 'indigo') }}">
                {{ match($contentPiece->status) {
                    'draft' => __('Draft'),
                    'approved' => __('Approved'),
                    'archived' => __('Archived'),
                    default => $contentPiece->status,
                } }}
            </flux:badge>
        </div>
        <div class="flex items-center gap-2">
            @if ($contentPiece->conversation_id)
                <flux:button variant="subtle" size="sm" icon="chat-bubble-left" :href="route('create.chat', ['current_team' => $teamModel, 'conversation' => $contentPiece->conversation_id])" wire:navigate>
                    {{ __('Open conversation') }}
                </flux:button>
            @endif
            <flux:dropdown>
                <flux:button variant="subtle" size="sm" icon="ellipsis-horizontal" />
                <flux:menu>
                    <flux:menu.item wire:click="updateStatus('draft')" :checked="$contentPiece->status === 'draft'">{{ __('Mark as Draft') }}</flux:menu.item>
                    <flux:menu.item wire:click="updateStatus('approved')" :checked="$contentPiece->status === 'approved'">{{ __('Approve') }}</flux:menu.item>
                    <flux:menu.item wire:click="updateStatus('archived')" :checked="$contentPiece->status === 'archived'">{{ __('Archive') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mx-auto grid max-w-6xl grid-cols-1 gap-6 px-6 py-4 lg:grid-cols-[1fr_18rem]">
        <div>
            <div class="mb-3 flex items-center gap-2">
                <flux:badge variant="pill" size="sm">v{{ $this->displayed['version'] }}</flux:badge>
                @if (! $this->displayed['is_current'])
                    <flux:text class="text-xs text-amber-500">{{ __('Viewing a past version') }}</flux:text>
                    <flux:button size="xs" variant="subtle" wire:click="selectVersion(null)">{{ __('View current') }}</flux:button>
                @endif
            </div>

            <article class="prose prose-invert max-w-none">
                <h1>{{ $this->displayed['title'] }}</h1>
                <div class="whitespace-pre-wrap">{{ $this->displayed['body'] }}</div>
            </article>
        </div>

        <aside>
            <flux:heading size="sm" class="mb-2">{{ __('Version history') }}</flux:heading>
            <div class="space-y-2">
                @foreach ($contentPiece->versions as $v)
                    <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-3 {{ $selectedVersion === $v->version ? 'ring-1 ring-indigo-500' : '' }}">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:badge variant="pill" size="sm" color="{{ $v->version === $contentPiece->current_version ? 'indigo' : 'zinc' }}">
                                    v{{ $v->version }}
                                </flux:badge>
                                @if ($v->version === $contentPiece->current_version)
                                    <flux:text class="text-xs text-indigo-400">{{ __('Current') }}</flux:text>
                                @endif
                            </div>
                            <flux:text class="text-xs text-zinc-500">{{ $v->created_at?->diffForHumans() }}</flux:text>
                        </div>
                        @if ($v->change_description)
                            <flux:text class="mt-1 text-xs text-zinc-400">{{ $v->change_description }}</flux:text>
                        @endif
                        <div class="mt-2 flex items-center gap-2">
                            <flux:button size="xs" variant="subtle" wire:click="selectVersion({{ $v->version }})">{{ __('View') }}</flux:button>
                            @if ($v->version !== $contentPiece->current_version)
                                <flux:modal.trigger :name="'restore-version-'.$v->version">
                                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left">{{ __('Restore') }}</flux:button>
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </div>

                    <flux:modal :name="'restore-version-'.$v->version" class="min-w-[22rem]">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Restore v:v?', ['v' => $v->version]) }}</flux:heading>
                                <flux:text class="mt-2">{{ __('This creates a new version with v:v\'s content, preserving history.', ['v' => $v->version]) }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:spacer />
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" wire:click="restoreVersion({{ $v->version }})">
                                    {{ __('Restore') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endforeach
            </div>
        </aside>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add marketminded-laravel/resources/views/pages/teams/⚡content-piece.blade.php
git commit -m "feat: add content piece page with version history and restore"
```

---

## Task 13: Content index page, routes, sidebar nav, and create-page card

**Files:**
- Create: `marketminded-laravel/resources/views/pages/teams/⚡content.blade.php`
- Modify: `marketminded-laravel/routes/web.php`
- Modify: `marketminded-laravel/resources/views/layouts/app/sidebar.blade.php`
- Modify: `marketminded-laravel/resources/views/pages/teams/⚡create.blade.php`

- [ ] **Step 1: Add content.index route**

`content.show` was added in Task 11. Now add the sibling index route. In `routes/web.php`, inside the team-scoped group, add (above the `content/{contentPiece}` line):

```php
            Route::livewire('content', 'pages::teams.content')->name('content.index');
```

- [ ] **Step 2: Create content index page**

Create `resources/views/pages/teams/⚡content.blade.php`:

```blade
<?php

use App\Models\ContentPiece;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function getPiecesProperty()
    {
        return ContentPiece::where('team_id', $this->teamModel->id)
            ->with('topic')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Content'));
    }
}; ?>

<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Content') }}</flux:heading>
            @if ($this->pieces->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->pieces->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create')" wire:navigate>
            {{ __('New blog post') }}
        </flux:button>
    </div>

    @if ($this->pieces->isEmpty())
        <div class="py-20 text-center">
            <flux:icon name="document-text" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="lg" class="mt-4">{{ __('No content yet') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Write your first blog post from a Topic.') }}</flux:subheading>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" :href="route('create')" wire:navigate>
                    {{ __('New blog post') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="grid gap-2 px-6 py-4 sm:grid-cols-2">
            @foreach ($this->pieces as $piece)
                <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $piece->id]) }}" wire:navigate>
                    <flux:card class="flex flex-col p-4 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <flux:heading class="truncate">{{ $piece->title ?: __('Untitled') }}</flux:heading>
                                <flux:text class="mt-1 text-xs text-zinc-500">
                                    v{{ $piece->current_version }}
                                    &middot; {{ $piece->updated_at->diffForHumans() }}
                                    @if ($piece->topic)
                                        &middot; {{ $piece->topic->title }}
                                    @endif
                                </flux:text>
                            </div>
                            <flux:badge variant="pill" size="sm" color="{{ $piece->status === 'approved' ? 'green' : ($piece->status === 'archived' ? 'zinc' : 'indigo') }}">
                                {{ match($piece->status) {
                                    'draft' => __('Draft'),
                                    'approved' => __('Approved'),
                                    'archived' => __('Archived'),
                                    default => $piece->status,
                                } }}
                            </flux:badge>
                        </div>
                        <flux:text class="mt-2 text-sm text-zinc-400 line-clamp-3">{{ mb_substr(strip_tags($piece->body), 0, 200) }}</flux:text>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
```

- [ ] **Step 3: Add "Content" item to the sidebar**

In `resources/views/layouts/app/sidebar.blade.php`, after the "Topics" sidebar item (line 25), add:

```blade
<flux:sidebar.item icon="document-text" :href="route('content.index')" :current="request()->routeIs('content.*')" wire:navigate>
    {{ __('Content') }}
</flux:sidebar.item>
```

- [ ] **Step 4: Update `⚡create.blade.php` badge map**

In `⚡create.blade.php` around line 85, update the badge match:

```blade
@if ($conversation->type)
    <flux:badge variant="pill" size="sm" class="shrink-0">{{ match($conversation->type) {
        'brand' => __('Brand'),
        'topics' => __('Topics'),
        'writer' => __('Writer'),
        default => $conversation->type,
    } }}</flux:badge>
@endif
```

- [ ] **Step 5: Manual verification**

Run `php artisan serve`:

1. Create a writer conversation, pick a topic, pick autopilot, and run the pipeline. Verify the content piece card links to `/content/{id}` and the piece page renders.
2. Click a past version's "View" button -- left panel updates.
3. Click "Restore" on an old version, confirm modal -- a new version is added with the restored content, current_version becomes the new one, history still shows all versions.
4. Go to "Content" in the sidebar -- index page shows the piece.
5. Try `!autopilot` / `!checkpoint` in the writer chat again to confirm the badge flips.

- [ ] **Step 6: Run full test suite**

Run:
```bash
php artisan test
```
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add marketminded-laravel/routes/web.php \
        marketminded-laravel/resources/views/pages/teams/⚡content.blade.php \
        marketminded-laravel/resources/views/layouts/app/sidebar.blade.php \
        marketminded-laravel/resources/views/pages/teams/⚡create.blade.php
git commit -m "feat: add content index page, routes, and sidebar nav"
```

---

## Done

At this point:
- A writer chat type exists end-to-end: topic picker -> mode tiles -> composer
- The AI produces a blog post via `research_topic -> create_outline -> write_blog_post` with a hard gate at the write step
- Revisions flow through `update_blog_post` and are versioned
- The content piece has a dedicated page with version history and restore
- `!autopilot` / `!checkpoint` commands switch modes mid-conversation
- The generic `write` chat type has been removed
- Sidebar and Create page reflect the new writer flow
