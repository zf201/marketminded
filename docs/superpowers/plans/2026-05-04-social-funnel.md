# Social Funnel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a 4th create-workflow ("Build a Funnel") that turns a `ContentPiece` into 3–6 platform-appropriate social posts, browsable on a new **Social** sidebar page and refinable through chat.

**Architecture:** Reuses the existing shared `create-chat` Livewire component via a new `type='funnel'` value (same dispatch pattern as `topics` and `writer`). New `SocialPost` model with four agent tools (`propose_posts`, `update_post`, `delete_post`, `replace_all_posts`). Two new pages render the social index and per-content-piece subpage as Flux card grids.

**Tech Stack:** Laravel 13 + Livewire 3 + Flux UI, OpenRouter via existing `OpenRouterClient`, Pest 3 for tests, Sail for the dev environment (Postgres). All commands run through `./vendor/bin/sail`.

**Reference spec:** `docs/superpowers/specs/2026-05-04-social-funnel-design.md`

---

## File Structure

**New files:**
- `database/migrations/{ts}_create_social_posts_table.php`
- `database/migrations/{ts}_add_content_piece_id_to_conversations.php`
- `app/Models/SocialPost.php`
- `app/Services/SocialPostToolHandler.php`
- `database/factories/SocialPostFactory.php`
- `resources/views/pages/teams/⚡social.blade.php` (index)
- `resources/views/pages/teams/⚡social-piece.blade.php` (per-piece subpage)
- `tests/Feature/SocialPostToolHandlerTest.php`
- `tests/Feature/Pages/SocialIndexTest.php`
- `tests/Feature/Pages/SocialPieceTest.php`
- `tests/Feature/FunnelChatTypeTest.php`

**Modified files:**
- `app/Services/ChatPromptBuilder.php` — add `'funnel'` to `build()` + `tools()`, add `funnelPrompt()` method
- `app/Models/Conversation.php` — fillable + `contentPiece()` relation
- `app/Models/ContentPiece.php` — `socialPosts()` relation
- `resources/views/pages/teams/⚡create-chat.blade.php` — 4th tile, funnel picker, input gate, tool dispatch, type badge
- `resources/views/pages/teams/⚡create.blade.php` — type badge label for `funnel`
- `resources/views/layouts/app/sidebar.blade.php` — Social entry
- `routes/web.php` — `social` and `social/{contentPiece}` routes

**One file per responsibility:** the tool handler, the model, each page. The chat blade is already a known-large file in this codebase — we follow its existing pattern rather than restructuring.

---

## Task 1: Migrations

**Files:**
- Create: `database/migrations/{ts}_create_social_posts_table.php`
- Create: `database/migrations/{ts}_add_content_piece_id_to_conversations.php`

- [ ] **Step 1: Generate the social_posts migration**

```bash
./vendor/bin/sail artisan make:migration create_social_posts_table
```

- [ ] **Step 2: Fill in the migration**

Replace the generated file's content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_piece_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 20); // linkedin, facebook, instagram, short_video
            $table->text('hook');
            $table->text('body');
            $table->json('hashtags')->default('[]');
            $table->text('image_prompt')->nullable();
            $table->text('video_treatment')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('status', 20)->default('active'); // active, deleted
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'content_piece_id', 'status']);
            $table->index(['content_piece_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
```

- [ ] **Step 3: Generate the conversations migration**

```bash
./vendor/bin/sail artisan make:migration add_content_piece_id_to_conversations
```

- [ ] **Step 4: Fill in the migration**

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
            $table->foreignId('content_piece_id')->nullable()->after('topic_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['content_piece_id']);
            $table->dropColumn('content_piece_id');
        });
    }
};
```

- [ ] **Step 5: Run migrations**

```bash
./vendor/bin/sail artisan migrate
```
Expected: both migrations marked `DONE`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations
git commit -m "feat(social): add social_posts table and conversations.content_piece_id"
```

---

## Task 2: SocialPost model + factory + relations

**Files:**
- Create: `app/Models/SocialPost.php`
- Create: `database/factories/SocialPostFactory.php`
- Modify: `app/Models/ContentPiece.php` — add `socialPosts()` relation
- Modify: `app/Models/Conversation.php` — add `contentPiece()` relation + fillable

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SocialPostModelTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;

it('belongs to team, content piece, and conversation', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $post = SocialPost::factory()->create([
        'team_id' => $team->id,
        'content_piece_id' => $piece->id,
        'conversation_id' => $conv->id,
        'platform' => 'linkedin',
    ]);

    expect($post->team->id)->toBe($team->id)
        ->and($post->contentPiece->id)->toBe($piece->id)
        ->and($post->conversation->id)->toBe($conv->id);
});

it('casts hashtags as array', function () {
    $post = SocialPost::factory()->create(['hashtags' => ['ai', 'marketing']]);
    expect($post->fresh()->hashtags)->toBe(['ai', 'marketing']);
});

it('exposes social posts on content piece', function () {
    $piece = ContentPiece::factory()->create();
    SocialPost::factory()->count(3)->create(['content_piece_id' => $piece->id]);
    expect($piece->socialPosts)->toHaveCount(3);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/sail test --filter=SocialPostModelTest
```
Expected: FAIL — `SocialPost` and factory don't exist yet.

- [ ] **Step 3: Create the model**

Create `app/Models/SocialPost.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id', 'content_piece_id', 'conversation_id',
    'platform', 'hook', 'body', 'hashtags',
    'image_prompt', 'video_treatment',
    'score', 'posted_at', 'status', 'position',
])]
class SocialPost extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'score' => 'integer',
            'position' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function contentPiece(): BelongsTo
    {
        return $this->belongsTo(ContentPiece::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

- [ ] **Step 4: Create the factory**

Create `database/factories/SocialPostFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialPostFactory extends Factory
{
    protected $model = SocialPost::class;

    public function definition(): array
    {
        $team = Team::factory();

        return [
            'team_id' => $team,
            'content_piece_id' => ContentPiece::factory()->state(fn ($attrs, $t) => ['team_id' => $attrs['team_id'] ?? $t?->id]),
            'conversation_id' => null,
            'platform' => 'linkedin',
            'hook' => $this->faker->sentence(),
            'body' => "Body with [POST_URL] inside.",
            'hashtags' => ['ai', 'marketing'],
            'image_prompt' => 'A clean over-the-shoulder laptop shot.',
            'video_treatment' => null,
            'score' => null,
            'posted_at' => null,
            'status' => 'active',
            'position' => 0,
        ];
    }
}
```

- [ ] **Step 5: Add relation to ContentPiece**

In `app/Models/ContentPiece.php`, add (after the `versions()` method):

```php
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class)->where('status', 'active')->orderBy('position');
    }
```

(The `HasMany` import is already there.)

- [ ] **Step 6: Update Conversation model**

In `app/Models/Conversation.php`, add `content_piece_id` to the `Fillable` attribute list and add this relation method:

```php
    public function contentPiece(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ContentPiece::class);
    }
```

- [ ] **Step 7: Run test to verify it passes**

```bash
./vendor/bin/sail test --filter=SocialPostModelTest
```
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Models database/factories tests/Feature/SocialPostModelTest.php
git commit -m "feat(social): add SocialPost model, factory, and relations"
```

---

## Task 3: SocialPostToolHandler — schemas

**Files:**
- Create: `app/Services/SocialPostToolHandler.php`
- Create: `tests/Feature/SocialPostToolHandlerTest.php`

This task creates the handler shell with the four tool schemas. Execution logic lands in Task 4.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SocialPostToolHandlerTest.php`:

```php
<?php

use App\Services\SocialPostToolHandler;

it('exposes four tool schemas', function () {
    $names = [
        SocialPostToolHandler::proposeSchema()['function']['name'],
        SocialPostToolHandler::updateSchema()['function']['name'],
        SocialPostToolHandler::deleteSchema()['function']['name'],
        SocialPostToolHandler::replaceAllSchema()['function']['name'],
    ];

    expect($names)->toBe(['propose_posts', 'update_post', 'delete_post', 'replace_all_posts']);
});

it('propose_posts schema requires posts array of 3-6 items', function () {
    $schema = SocialPostToolHandler::proposeSchema()['function']['parameters'];
    expect($schema['properties']['posts']['minItems'])->toBe(3)
        ->and($schema['properties']['posts']['maxItems'])->toBe(6);
});

it('post item schema enumerates platforms', function () {
    $schema = SocialPostToolHandler::proposeSchema()['function']['parameters'];
    expect($schema['properties']['posts']['items']['properties']['platform']['enum'])
        ->toBe(['linkedin', 'facebook', 'instagram', 'short_video']);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Create the handler skeleton with schemas**

Create `app/Services/SocialPostToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class SocialPostToolHandler
{
    public static function postItemSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['platform', 'hook', 'body'],
            'properties' => [
                'platform' => [
                    'type' => 'string',
                    'enum' => ['linkedin', 'facebook', 'instagram', 'short_video'],
                ],
                'hook' => [
                    'type' => 'string',
                    'description' => 'Scroll-stopping opener line.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Full post body in markdown. MUST contain [POST_URL] exactly once at a natural CTA point.',
                ],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Hashtags without leading #.',
                ],
                'image_prompt' => [
                    'type' => 'string',
                    'description' => 'Direction for the visual. Required for non-video platforms.',
                ],
                'video_treatment' => [
                    'type' => 'string',
                    'description' => 'Hook beat / value beats / CTA. Required when platform = short_video.',
                ],
            ],
        ];
    }

    public static function proposeSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_posts',
                'description' => 'Save the initial set of social posts for the selected content piece. 3–6 posts, at most 1 with platform=short_video. Every body must contain [POST_URL] exactly once.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['posts'],
                    'properties' => [
                        'posts' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 6,
                            'items' => self::postItemSchema(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function updateSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_post',
                'description' => 'Update one existing social post by id. Only include fields you want to change.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'platform' => ['type' => 'string', 'enum' => ['linkedin', 'facebook', 'instagram', 'short_video']],
                        'hook' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'image_prompt' => ['type' => 'string'],
                        'video_treatment' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    public static function deleteSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'delete_post',
                'description' => 'Soft-delete one social post by id. Briefly explain why in your message.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    public static function replaceAllSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'replace_all_posts',
                'description' => 'Soft-delete all current active posts for the content piece and create a new set. Same constraints as propose_posts.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['posts'],
                    'properties' => [
                        'posts' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 6,
                            'items' => self::postItemSchema(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function propose(Team $team, int $conversationId, ContentPiece $piece, array $data): string
    {
        return ''; // implemented in Task 4
    }

    public function update(Team $team, int $conversationId, array $data): string
    {
        return ''; // implemented in Task 4
    }

    public function delete(Team $team, array $data): string
    {
        return ''; // implemented in Task 4
    }

    public function replaceAll(Team $team, int $conversationId, ContentPiece $piece, array $data): string
    {
        return ''; // implemented in Task 4
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SocialPostToolHandler.php tests/Feature/SocialPostToolHandlerTest.php
git commit -m "feat(social): add SocialPostToolHandler tool schemas"
```

---

## Task 4: SocialPostToolHandler — execution logic

**Files:**
- Modify: `app/Services/SocialPostToolHandler.php`
- Modify: `tests/Feature/SocialPostToolHandlerTest.php`

- [ ] **Step 1: Write failing tests for `propose`**

Append to `tests/Feature/SocialPostToolHandlerTest.php`:

```php
use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;

it('propose() saves posts and returns ids', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $handler = new SocialPostToolHandler();
    $result = json_decode($handler->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h1', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h2', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h3', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('saved')
        ->and($result['ids'])->toHaveCount(3)
        ->and(SocialPost::where('content_piece_id', $piece->id)->count())->toBe(3);
});

it('propose() rejects missing [POST_URL] in body', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $result = json_decode((new SocialPostToolHandler())->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h', 'body' => 'no placeholder', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('[POST_URL]');
});

it('propose() rejects more than one short_video post', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $result = json_decode((new SocialPostToolHandler())->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'short_video', 'hook' => 'h', 'body' => 'b [POST_URL]', 'video_treatment' => 'v'],
            ['platform' => 'short_video', 'hook' => 'h', 'body' => 'b [POST_URL]', 'video_treatment' => 'v'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('short_video');
});

it('update() patches one post within team scope', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $post = SocialPost::factory()->create([
        'team_id' => $team->id,
        'content_piece_id' => $piece->id,
        'platform' => 'linkedin',
        'hook' => 'old hook',
    ]);

    $result = json_decode((new SocialPostToolHandler())->update($team, 0, [
        'id' => $post->id,
        'hook' => 'new hook',
    ]), true);

    expect($result['status'])->toBe('saved')
        ->and($post->fresh()->hook)->toBe('new hook');
});

it('update() refuses cross-team ids', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $teamB->id]);
    $post = SocialPost::factory()->create(['team_id' => $teamB->id, 'content_piece_id' => $piece->id]);

    $result = json_decode((new SocialPostToolHandler())->update($teamA, 0, [
        'id' => $post->id, 'hook' => 'x',
    ]), true);

    expect($result['status'])->toBe('error');
});

it('delete() soft-deletes', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $post = SocialPost::factory()->create(['team_id' => $team->id, 'content_piece_id' => $piece->id]);

    (new SocialPostToolHandler())->delete($team, ['id' => $post->id]);

    expect($post->fresh()->status)->toBe('deleted');
});

it('replaceAll() soft-deletes existing and creates new set', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);
    SocialPost::factory()->count(3)->create(['team_id' => $team->id, 'content_piece_id' => $piece->id]);

    (new SocialPostToolHandler())->replaceAll($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]);

    expect(SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->count())->toBe(3)
        ->and(SocialPost::where('content_piece_id', $piece->id)->where('status', 'deleted')->count())->toBe(3);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: 7 new tests FAIL (handler methods return empty strings).

- [ ] **Step 3: Implement the four execution methods**

Replace the four placeholder methods at the bottom of `app/Services/SocialPostToolHandler.php`:

```php
    public function propose(Team $team, int $conversationId, ContentPiece $piece, array $data): string
    {
        if ($piece->team_id !== $team->id) {
            return $this->err('Content piece does not belong to this team.');
        }

        $validation = $this->validatePosts($data['posts'] ?? []);
        if ($validation !== null) {
            return $this->err($validation);
        }

        $ids = DB::transaction(function () use ($team, $conversationId, $piece, $data) {
            $startPos = (int) SocialPost::where('content_piece_id', $piece->id)->max('position');
            $ids = [];
            foreach ($data['posts'] as $i => $p) {
                $ids[] = SocialPost::create($this->postAttrs($team, $conversationId, $piece, $p, $startPos + $i + 1))->id;
            }
            return $ids;
        });

        return json_encode(['status' => 'saved', 'count' => count($ids), 'ids' => $ids]);
    }

    public function update(Team $team, int $conversationId, array $data): string
    {
        $post = SocialPost::where('team_id', $team->id)->find($data['id'] ?? 0);
        if (! $post) {
            return $this->err('Post not found in this team.');
        }

        $patch = array_intersect_key($data, array_flip([
            'platform', 'hook', 'body', 'hashtags', 'image_prompt', 'video_treatment',
        ]));

        if (isset($patch['body']) && substr_count($patch['body'], '[POST_URL]') !== 1) {
            return $this->err('Body must contain [POST_URL] exactly once.');
        }
        if (isset($patch['platform']) && $patch['platform'] === 'short_video') {
            $otherShortVideos = SocialPost::where('content_piece_id', $post->content_piece_id)
                ->where('status', 'active')->where('id', '!=', $post->id)
                ->where('platform', 'short_video')->count();
            if ($otherShortVideos >= 1) {
                return $this->err('Only one short_video post is allowed per content piece.');
            }
        }

        $post->update($patch + ['conversation_id' => $conversationId ?: $post->conversation_id]);

        return json_encode(['status' => 'saved', 'id' => $post->id]);
    }

    public function delete(Team $team, array $data): string
    {
        $post = SocialPost::where('team_id', $team->id)->find($data['id'] ?? 0);
        if (! $post) {
            return $this->err('Post not found in this team.');
        }
        $post->update(['status' => 'deleted']);
        return json_encode(['status' => 'deleted', 'id' => $post->id]);
    }

    public function replaceAll(Team $team, int $conversationId, ContentPiece $piece, array $data): string
    {
        if ($piece->team_id !== $team->id) {
            return $this->err('Content piece does not belong to this team.');
        }
        $validation = $this->validatePosts($data['posts'] ?? []);
        if ($validation !== null) {
            return $this->err($validation);
        }

        $ids = DB::transaction(function () use ($team, $conversationId, $piece, $data) {
            SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->update(['status' => 'deleted']);
            $ids = [];
            foreach ($data['posts'] as $i => $p) {
                $ids[] = SocialPost::create($this->postAttrs($team, $conversationId, $piece, $p, $i + 1))->id;
            }
            return $ids;
        });

        return json_encode(['status' => 'saved', 'count' => count($ids), 'ids' => $ids]);
    }

    private function validatePosts(array $posts): ?string
    {
        $count = count($posts);
        if ($count < 3 || $count > 6) {
            return 'Must propose between 3 and 6 posts.';
        }
        $shortVideos = 0;
        foreach ($posts as $i => $p) {
            $platform = $p['platform'] ?? '';
            if (! in_array($platform, ['linkedin', 'facebook', 'instagram', 'short_video'], true)) {
                return "Post #{$i}: invalid platform '{$platform}'.";
            }
            $body = $p['body'] ?? '';
            if (substr_count($body, '[POST_URL]') !== 1) {
                return "Post #{$i}: body must contain [POST_URL] exactly once.";
            }
            if ($platform === 'short_video') {
                $shortVideos++;
                if (empty($p['video_treatment'] ?? '')) {
                    return "Post #{$i}: short_video posts require video_treatment.";
                }
            } else {
                if (empty($p['image_prompt'] ?? '')) {
                    return "Post #{$i}: non-video posts require image_prompt.";
                }
            }
        }
        if ($shortVideos > 1) {
            return 'At most one post may have platform=short_video.';
        }
        return null;
    }

    private function postAttrs(Team $team, int $conversationId, ContentPiece $piece, array $p, int $position): array
    {
        return [
            'team_id' => $team->id,
            'content_piece_id' => $piece->id,
            'conversation_id' => $conversationId ?: null,
            'platform' => $p['platform'],
            'hook' => $p['hook'],
            'body' => $p['body'],
            'hashtags' => $p['hashtags'] ?? [],
            'image_prompt' => $p['image_prompt'] ?? null,
            'video_treatment' => $p['video_treatment'] ?? null,
            'status' => 'active',
            'position' => $position,
        ];
    }

    private function err(string $message): string
    {
        return json_encode(['status' => 'error', 'message' => $message]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: 10 tests PASS (3 schema + 7 execution).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SocialPostToolHandler.php tests/Feature/SocialPostToolHandlerTest.php
git commit -m "feat(social): implement SocialPostToolHandler execution logic"
```

---

## Task 5: ChatPromptBuilder — funnel system prompt + tools

**Files:**
- Modify: `app/Services/ChatPromptBuilder.php`
- Modify: `tests/Feature/SocialPostToolHandlerTest.php` (add prompt-builder tests at the bottom)

The prompt embeds best-practice guidance per platform. Keep it inline (not a separate doc) so it's versioned with the prompt.

- [ ] **Step 1: Write failing test**

Append to `tests/Feature/SocialPostToolHandlerTest.php`:

```php
use App\Models\Team;
use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Services\ChatPromptBuilder;

it('funnel prompt includes content piece and platform guidance', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id, 'title' => 'My Post Title', 'body' => 'Body here.']);
    $conv = Conversation::factory()->create(['team_id' => $team->id, 'content_piece_id' => $piece->id, 'type' => 'funnel']);

    $prompt = ChatPromptBuilder::build('funnel', $team, $conv);

    expect($prompt)
        ->toContain('My Post Title')
        ->toContain('[POST_URL]')
        ->toContain('LinkedIn')
        ->toContain('short_video')
        ->toContain('propose_posts');
});

it('funnel prompt lists existing active posts when present', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id, 'content_piece_id' => $piece->id, 'type' => 'funnel']);
    \App\Models\SocialPost::factory()->create([
        'team_id' => $team->id, 'content_piece_id' => $piece->id,
        'hook' => 'EXISTING HOOK', 'platform' => 'linkedin',
    ]);

    $prompt = ChatPromptBuilder::build('funnel', $team, $conv);
    expect($prompt)->toContain('EXISTING HOOK')->toContain('Current funnel');
});

it('tools(funnel) returns the four social tool schemas plus fetch_url', function () {
    $tools = ChatPromptBuilder::tools('funnel');
    $names = array_map(fn ($t) => $t['function']['name'], $tools);
    expect($names)->toContain('propose_posts', 'update_post', 'delete_post', 'replace_all_posts', 'fetch_url');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: 3 new tests FAIL.

- [ ] **Step 3: Add `'funnel'` cases to `build()` and `tools()`**

In `app/Services/ChatPromptBuilder.php`, modify the `build()` match:

```php
        return match ($type) {
            'brand' => self::brandPrompt($profile),
            'topics' => self::topicsPrompt($profile, $hasProfile, $team),
            'writer' => self::writerPrompt($profile, $hasProfile, $conversation),
            'funnel' => self::funnelPrompt($profile, $team, $conversation),
            default => 'You are a helpful AI assistant.',
        };
```

And the `tools()` match — add:

```php
            'funnel' => [
                SocialPostToolHandler::proposeSchema(),
                SocialPostToolHandler::updateSchema(),
                SocialPostToolHandler::deleteSchema(),
                SocialPostToolHandler::replaceAllSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
```

Add at the top imports:

```php
use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Topic;
```

(`Topic` is already imported; add `ContentPiece` and `SocialPost`.)

- [ ] **Step 4: Implement `funnelPrompt()`**

Add this method to the class (alongside `topicsPrompt` / `writerPrompt`):

```php
    private static function funnelPrompt(string $profile, Team $team, ?Conversation $conversation): string
    {
        $piece = $conversation?->contentPiece;
        $pieceBlock = '(no content piece selected yet)';
        if ($piece) {
            $pieceBlock = "Title: {$piece->title}\n\nBody:\n{$piece->body}";
        }

        $brief = $conversation?->brief ?? [];
        $guidance = is_array($brief) && ! empty($brief['funnel_guidance']) ? $brief['funnel_guidance'] : null;

        $topicBlock = '';
        if ($piece && $piece->topic_id) {
            $topic = Topic::find($piece->topic_id);
            if ($topic) {
                $sources = is_array($topic->sources) ? implode("\n- ", $topic->sources) : '';
                $topicBlock = "\n\n## Source topic brainstorm\nTitle: {$topic->title}\nAngle: {$topic->angle}" .
                    ($sources ? "\nSources:\n- {$sources}" : '');
            }
        }

        $existing = $piece
            ? SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->orderBy('position')->get()
            : collect();
        $existingBlock = '';
        if ($existing->isNotEmpty()) {
            $lines = $existing->map(function ($p) {
                $tags = is_array($p->hashtags) ? implode(' ', array_map(fn ($t) => '#'.$t, $p->hashtags)) : '';
                $visual = $p->platform === 'short_video' ? "Video: {$p->video_treatment}" : "Image: {$p->image_prompt}";
                return "- id={$p->id} platform={$p->platform}\n  hook: {$p->hook}\n  body: {$p->body}\n  tags: {$tags}\n  {$visual}";
            })->implode("\n");
            $existingBlock = "\n\n## Current funnel for this piece\nThe user is refining the existing funnel. Default to keeping these unless asked to change. Reference posts by id when discussing them.\n\n<existing-posts>\n{$lines}\n</existing-posts>";
        }

        $guidanceBlock = $guidance ? "\n\n## User guidance\n{$guidance}" : '';

        return <<<PROMPT
You are a social-media strategist building a traffic funnel back to one piece of long-form content. You produce 3–6 platform-appropriate posts that drive readers to that piece.

## CRITICAL: function calling
You only do work through tool calls. The user sees saved posts as cards on the Social page — text-only responses with post drafts are useless.

## Your tools
- propose_posts — REQUIRED on first turn for a new funnel. Saves the initial 3–6 posts.
- update_post(id, fields) — patch one post when the user asks to fix a specific one.
- delete_post(id) — drop one post.
- replace_all_posts — soft-delete current set and create a new one (use only when the user wants a full redo).
- fetch_url — fetch a URL when needed.

## Hard rules
- Output 3–6 posts total.
- AT MOST ONE post may have platform=short_video. Most funnels will have zero.
- Every body MUST contain the literal token `[POST_URL]` exactly once at a natural CTA point. The user replaces it with the live link at posting time.
- Hashtags array — no leading `#`, no spaces inside tags.
- Non-video posts require an image_prompt. short_video posts require a video_treatment.
- Write in the same language as the source content piece.

## Per-platform best practices
- LinkedIn: long-form ok (700–1500 chars). Hook on line 1, single-sentence paragraphs, generous line breaks. End with the CTA paragraph that contains [POST_URL]. Hashtags: 3–5, professional, end of post. No emoji spam.
- Facebook: conversational, 1–3 short paragraphs. Open with a question or a vivid detail. Hashtags optional, 0–3. CTA paragraph carries [POST_URL].
- Instagram: caption-style, visual-first. Punchy hook line, then 2–4 short paragraphs separated by blank lines. Heavy hashtag block (8–15) at the very end after the [POST_URL] CTA. image_prompt should describe a single, scroll-stopping shot.
- short_video (TikTok / Reels / Shorts): a 15–45s treatment, written as Hook (0–3s) / Value beats (3–25s) / CTA (last 5s). The body field is the on-screen caption + voice-over script. video_treatment is the directorial / shot-list version. Both must contain [POST_URL] inside the body once (e.g. "Link below — [POST_URL]").

## How to mix the funnel
- Aim for 1 LinkedIn + 1 Facebook + 1–2 Instagram by default. Add a short_video only if the source piece has a strong visual or narrative hook.
- Don't repeat the same hook across posts. Vary angle: data point → personal story → contrarian take → tactical how-to.

## Source content piece
<content-piece>
{$pieceBlock}
</content-piece>{$topicBlock}{$guidanceBlock}{$existingBlock}

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: all 13 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/ChatPromptBuilder.php tests/Feature/SocialPostToolHandlerTest.php
git commit -m "feat(social): add funnel system prompt + tool wiring"
```

---

## Task 6: Wire the funnel type into create-chat (UI + tool dispatch)

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php`
- Modify: `resources/views/pages/teams/⚡create.blade.php`
- Create: `tests/Feature/FunnelChatTypeTest.php`

The chat is reused — we add a 4th type. There are five edits:
A. New properties + selector method (`selectFunnelContentPiece`, `setFunnelGuidance`)
B. Tool dispatch for the four funnel tools inside `$toolExecutor`
C. 4th type-tile button
D. Funnel pre-input picker (content-piece + guidance)
E. Input-shown gate updated so input only appears once a content piece is picked
F. Type badge labels (`'funnel' => __('Funnel')`) in two places

- [ ] **Step 1: Write failing feature test for the dispatch and prompt selection**

Create `tests/Feature/FunnelChatTypeTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('renders create-chat with type=funnel param and shows the content piece picker', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user, ['role' => 'owner']);
    $user->update(['current_team_id' => $team->id]);
    $piece = ContentPiece::factory()->create(['team_id' => $team->id, 'title' => 'Pickable Piece']);

    actingAs($user)
        ->get(route('create.new', ['current_team' => $team, 'type' => 'funnel']))
        ->assertOk()
        ->assertSee('Pick a content piece')
        ->assertSee('Pickable Piece');
});

it('runs propose_posts via the chat tool dispatcher and saves posts to the picked piece', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user, ['role' => 'owner']);
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create([
        'team_id' => $team->id,
        'type' => 'funnel',
        'content_piece_id' => $piece->id,
    ]);

    $handler = new \App\Services\SocialPostToolHandler();
    $result = json_decode($handler->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('saved')
        ->and(\App\Models\SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->count())->toBe(3);
});
```

- [ ] **Step 2: Run test to verify the picker test fails**

```bash
./vendor/bin/sail test --filter=FunnelChatTypeTest
```
Expected: first test FAILS (no funnel UI yet).

- [ ] **Step 3: Add the 4th type-selection tile**

In `resources/views/pages/teams/⚡create-chat.blade.php` around line 1126, change the grid from `sm:grid-cols-3` to `sm:grid-cols-4` and add a 4th button after the writer button:

```blade
                        <button wire:click="selectType('funnel')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="megaphone" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Build a Funnel') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Turn a content piece into 3–6 social posts that drive traffic back to it') }}</flux:text>
                        </button>
```

- [ ] **Step 4: Add funnel state + methods on the Livewire component**

In `create-chat.blade.php`, near the existing public properties (e.g. `public ?string $type = null;`), add:

```php
    public ?int $contentPieceId = null;
    public ?string $funnelGuidance = null;
```

In `mount()` (where `$this->type` is hydrated), also hydrate `contentPieceId`:

```php
    $this->contentPieceId = $conversation?->content_piece_id;
    $this->funnelGuidance = is_array($conversation?->brief ?? null) ? ($conversation->brief['funnel_guidance'] ?? null) : null;
```

Add two new methods next to `selectType` / `selectTopicsMode`:

```php
    public function selectFunnelContentPiece(int $contentPieceId): void
    {
        $piece = \App\Models\ContentPiece::where('team_id', $this->teamModel->id)->findOrFail($contentPieceId);
        $this->contentPieceId = $piece->id;
        if ($this->conversation) {
            $brief = is_array($this->conversation->brief) ? $this->conversation->brief : [];
            if ($this->funnelGuidance) {
                $brief['funnel_guidance'] = $this->funnelGuidance;
            }
            $this->conversation->update([
                'content_piece_id' => $piece->id,
                'brief' => $brief,
            ]);
        }
    }

    public function setFunnelGuidance(string $guidance): void
    {
        $this->funnelGuidance = trim($guidance) ?: null;
        if ($this->conversation) {
            $brief = is_array($this->conversation->brief) ? $this->conversation->brief : [];
            $brief['funnel_guidance'] = $this->funnelGuidance;
            $this->conversation->update(['brief' => $brief]);
        }
    }
```

- [ ] **Step 5: Add funnel pre-input picker block**

After the existing writer-topic-picker block (around line 1202), add:

```blade
            {{-- Funnel: Content piece picker (required before input) --}}
            @if ($type === 'funnel' && !$contentPieceId && empty($messages))
                @php
                    $availablePieces = \App\Models\ContentPiece::where('team_id', $teamModel->id)
                        ->latest()->get();
                @endphp
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('Pick a content piece') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('The funnel will drive traffic back to this piece.') }}</flux:subheading>
                    @if ($availablePieces->isEmpty())
                        <p class="text-sm text-zinc-500">
                            {{ __('No content pieces yet.') }}
                            <a href="{{ route('content.index', ['current_team' => $teamModel]) }}" class="text-indigo-400 hover:underline" wire:navigate>{{ __('Browse Content') }}</a>
                        </p>
                    @else
                        <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                            @foreach ($availablePieces as $cp)
                                <button wire:click="selectFunnelContentPiece({{ $cp->id }})" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                                    <flux:heading size="sm">{{ $cp->title }}</flux:heading>
                                    <flux:text class="mt-1 text-xs line-clamp-2">{{ mb_substr(strip_tags($cp->body ?? ''), 0, 160) }}</flux:text>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
```

- [ ] **Step 6: Update the input-shown gate**

Around line 1208, find:
```blade
    @if ($type
        && !($type === 'topics' && !$topicsMode && empty($messages))
        && !($type === 'writer' && !$topicId && !$freeForm && empty($messages)))
```
Change to:
```blade
    @if ($type
        && !($type === 'topics' && !$topicsMode && empty($messages))
        && !($type === 'writer' && !$topicId && !$freeForm && empty($messages))
        && !($type === 'funnel' && !$contentPieceId && empty($messages)))
```

- [ ] **Step 7: Add tool dispatch for the four funnel tools**

In `create-chat.blade.php`, add these lines at the top (with the other use statements):

```php
use App\Services\SocialPostToolHandler;
```

Inside `$toolExecutor` (around line 213), instantiate the handler and add four branches. After `$proofreadHandler = new ProofreadBlogPostToolHandler;` add:

```php
        $socialHandler = new SocialPostToolHandler;
```

Add `$socialHandler` to the `use` list of the closure. Inside the closure body, after the `proofread_blog_post` branch, add:

```php
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
```

- [ ] **Step 8: Update the type badge match in create-chat.blade.php**

Around line 920, the `match($type)` for the badge label — add the `'funnel' => __('Funnel'),` arm. Apply the same change in `resources/views/pages/teams/⚡create.blade.php` (around line 80).

- [ ] **Step 9: Add a guidance text input below the picker**

Below the picker grid in step 5, before the closing `</div>` of the funnel block, add an optional input:

```blade
                    <div class="mt-6 w-full max-w-2xl">
                        <flux:input
                            wire:change="setFunnelGuidance($event.target.value)"
                            placeholder="{{ __('Optional: angle for the funnel (e.g., focus on the founder story)') }}"
                            value="{{ $funnelGuidance }}" />
                    </div>
```

- [ ] **Step 10: Run all tests**

```bash
./vendor/bin/sail test --filter=FunnelChatTypeTest
./vendor/bin/sail test --filter=SocialPostToolHandlerTest
```
Expected: all PASS.

- [ ] **Step 11: Commit**

```bash
git add resources/views/pages/teams/⚡create-chat.blade.php resources/views/pages/teams/⚡create.blade.php tests/Feature/FunnelChatTypeTest.php
git commit -m "feat(social): wire funnel type into create-chat (UI + tool dispatch)"
```

---

## Task 7: Routes + sidebar entry

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Create: `resources/views/pages/teams/⚡social.blade.php` (placeholder, fully implemented Task 8)
- Create: `resources/views/pages/teams/⚡social-piece.blade.php` (placeholder, Task 9)

We add the routes and a stub view so route tests pass. Real UI lands in Tasks 8–9.

- [ ] **Step 1: Add routes**

In `routes/web.php`, inside the `Route::prefix('{current_team}')` group, after the `content.show` route:

```php
        Route::livewire('social', 'pages::teams.social')->name('social.index');
        Route::livewire('social/{contentPiece}', 'pages::teams.social-piece')->name('social.show');
```

- [ ] **Step 2: Create stub blade pages**

Create `resources/views/pages/teams/⚡social.blade.php`:

```blade
<?php

use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function render()
    {
        return $this->view()->title(__('Social'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <flux:heading size="xl">{{ __('Social') }}</flux:heading>
    </div>
</div>
```

Create `resources/views/pages/teams/⚡social-piece.blade.php`:

```blade
<?php

use App\Models\ContentPiece;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;
    public ContentPiece $piece;

    public function mount(Team $current_team, ContentPiece $contentPiece): void
    {
        abort_unless($contentPiece->team_id === $current_team->id, 404);
        $this->teamModel = $current_team;
        $this->piece = $contentPiece;
    }

    public function render()
    {
        return $this->view()->title($this->piece->title);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <flux:heading size="xl">{{ $piece->title }}</flux:heading>
    </div>
</div>
```

- [ ] **Step 3: Add Social to the sidebar**

In `resources/views/layouts/app/sidebar.blade.php`, after the Topics item and before Content (per spec ordering Topics → Content → Social… wait, the spec says Topics → Content → Social, so insert AFTER Content):

```blade
                    <flux:sidebar.item icon="megaphone" :href="route('social.index')" :current="request()->routeIs('social.*')" wire:navigate>
                        {{ __('Social') }}
                    </flux:sidebar.item>
```

Place this immediately after the Content item (line 31) and before the History item.

- [ ] **Step 4: Add a route smoke test**

Append to `tests/Feature/Pages/SocialIndexTest.php` (create the file):

```php
<?php

use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('renders the social index page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user, ['role' => 'owner']);
    $user->update(['current_team_id' => $team->id]);

    actingAs($user)
        ->get(route('social.index', ['current_team' => $team]))
        ->assertOk()
        ->assertSee('Social');
});
```

- [ ] **Step 5: Run test**

```bash
./vendor/bin/sail test --filter=SocialIndexTest
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/layouts/app/sidebar.blade.php resources/views/pages/teams/⚡social.blade.php resources/views/pages/teams/⚡social-piece.blade.php tests/Feature/Pages/SocialIndexTest.php
git commit -m "feat(social): add social routes, sidebar entry, and stub pages"
```

---

## Task 8: Social index page (cards per content piece)

**Files:**
- Modify: `resources/views/pages/teams/⚡social.blade.php`
- Modify: `tests/Feature/Pages/SocialIndexTest.php`

- [ ] **Step 1: Write failing test**

Replace `tests/Feature/Pages/SocialIndexTest.php` with:

```php
<?php

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->users()->attach($this->user, ['role' => 'owner']);
    $this->user->update(['current_team_id' => $this->team->id]);
});

it('shows pieces with active social posts', function () {
    $pieceWith = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Has Posts']);
    $pieceWithout = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Bare Piece']);
    SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $pieceWith->id, 'platform' => 'linkedin']);

    actingAs($this->user)
        ->get(route('social.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Has Posts')
        ->assertDontSee('Bare Piece');
});

it('shows empty state when no funnels exist', function () {
    actingAs($this->user)
        ->get(route('social.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Build a Funnel');
});

it('hides pieces whose only posts are deleted', function () {
    $piece = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Empty Piece']);
    SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $piece->id, 'status' => 'deleted']);

    actingAs($this->user)
        ->get(route('social.index', ['current_team' => $this->team]))
        ->assertDontSee('Empty Piece');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/sail test --filter=SocialIndexTest
```
Expected: FAIL.

- [ ] **Step 3: Replace the social index blade**

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
            ->whereHas('socialPosts')
            ->with(['socialPosts' => fn ($q) => $q->select('id', 'content_piece_id', 'platform')])
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Social'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Social') }}</flux:heading>
            @if ($this->pieces->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->pieces->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
            {{ __('Build a Funnel') }}
        </flux:button>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->pieces->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="megaphone" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No funnels yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Pick a content piece and turn it into a set of social posts.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
                        {{ __('Build a Funnel') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->pieces as $piece)
                    <a href="{{ route('social.show', ['current_team' => $teamModel, 'contentPiece' => $piece]) }}" wire:navigate class="block rounded-xl border border-zinc-200 p-4 transition hover:border-indigo-400 dark:border-zinc-700 dark:hover:border-indigo-500">
                        <flux:heading size="sm" class="line-clamp-2">{{ $piece->title }}</flux:heading>
                        <div class="mt-2 flex items-center gap-2 text-xs text-zinc-500">
                            <span>{{ trans_choice('{1} 1 post|[2,*] :count posts', $piece->socialPosts->count(), ['count' => $piece->socialPosts->count()]) }}</span>
                            <span class="text-zinc-300">•</span>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($piece->socialPosts->pluck('platform')->unique() as $platform)
                                    <flux:badge variant="pill" size="sm">{{ $platform }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/sail test --filter=SocialIndexTest
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/teams/⚡social.blade.php tests/Feature/Pages/SocialIndexTest.php
git commit -m "feat(social): implement Social index page"
```

---

## Task 9: Social subpage with cards (score, posted, copy, refine)

**Files:**
- Modify: `resources/views/pages/teams/⚡social-piece.blade.php`
- Create: `tests/Feature/Pages/SocialPieceTest.php`

This page is the main user-facing surface. Card actions: score 1–10, "Posted" toggle, Copy markdown, Delete (soft), Refine in chat.

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Pages/SocialPieceTest.php`:

```php
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->users()->attach($this->user, ['role' => 'owner']);
    $this->user->update(['current_team_id' => $this->team->id]);
    $this->piece = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Source']);
});

it('renders cards for each active post', function () {
    SocialPost::factory()->create([
        'team_id' => $this->team->id, 'content_piece_id' => $this->piece->id,
        'platform' => 'linkedin', 'hook' => 'LinkedIn Hook Here', 'body' => 'Body [POST_URL]',
    ]);
    SocialPost::factory()->create([
        'team_id' => $this->team->id, 'content_piece_id' => $this->piece->id,
        'platform' => 'instagram', 'hook' => 'IG Hook Here', 'body' => 'Body [POST_URL]',
    ]);

    actingAs($this->user)
        ->get(route('social.show', ['current_team' => $this->team, 'contentPiece' => $this->piece]))
        ->assertOk()
        ->assertSee('LinkedIn Hook Here')
        ->assertSee('IG Hook Here')
        ->assertSee('[POST_URL]');
});

it('updateScore persists the score within team scope', function () {
    actingAs($this->user);
    $post = SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $this->piece->id]);

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('updateScore', $post->id, 8);

    expect($post->fresh()->score)->toBe(8);
});

it('togglePosted flips posted_at', function () {
    actingAs($this->user);
    $post = SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $this->piece->id]);

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('togglePosted', $post->id);
    expect($post->fresh()->posted_at)->not->toBeNull();

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('togglePosted', $post->id);
    expect($post->fresh()->posted_at)->toBeNull();
});

it('deletePost soft-deletes', function () {
    actingAs($this->user);
    $post = SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $this->piece->id]);

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('deletePost', $post->id);

    expect($post->fresh()->status)->toBe('deleted');
});

it('refine button links to most recent funnel conversation when one exists', function () {
    $conv = Conversation::factory()->create([
        'team_id' => $this->team->id,
        'type' => 'funnel',
        'content_piece_id' => $this->piece->id,
    ]);
    SocialPost::factory()->create([
        'team_id' => $this->team->id,
        'content_piece_id' => $this->piece->id,
        'conversation_id' => $conv->id,
    ]);

    actingAs($this->user)
        ->get(route('social.show', ['current_team' => $this->team, 'contentPiece' => $this->piece]))
        ->assertSee(route('create.chat', ['current_team' => $this->team, 'conversation' => $conv]));
});

it('blocks access to a piece from another team', function () {
    $other = Team::factory()->create();
    $foreign = ContentPiece::factory()->create(['team_id' => $other->id]);

    actingAs($this->user)
        ->get(route('social.show', ['current_team' => $this->team, 'contentPiece' => $foreign]))
        ->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/sail test --filter=SocialPieceTest
```
Expected: FAIL.

- [ ] **Step 3: Replace the social-piece blade**

```blade
<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;
    public ContentPiece $piece;

    public function mount(Team $current_team, ContentPiece $contentPiece): void
    {
        abort_unless($contentPiece->team_id === $current_team->id, 404);
        $this->teamModel = $current_team;
        $this->piece = $contentPiece;
    }

    public function getPostsProperty()
    {
        return SocialPost::where('content_piece_id', $this->piece->id)
            ->where('status', 'active')
            ->orderBy('position')
            ->get();
    }

    public function getRefineConversationProperty(): ?Conversation
    {
        return Conversation::where('team_id', $this->teamModel->id)
            ->where('type', 'funnel')
            ->where('content_piece_id', $this->piece->id)
            ->latest()
            ->first();
    }

    public function updateScore(int $postId, int $score): void
    {
        SocialPost::where('team_id', $this->teamModel->id)
            ->findOrFail($postId)
            ->update(['score' => max(1, min(10, $score))]);
    }

    public function togglePosted(int $postId): void
    {
        $post = SocialPost::where('team_id', $this->teamModel->id)->findOrFail($postId);
        $post->update(['posted_at' => $post->posted_at ? null : now()]);
    }

    public function deletePost(int $postId): void
    {
        SocialPost::where('team_id', $this->teamModel->id)
            ->findOrFail($postId)
            ->update(['status' => 'deleted']);
        \Flux\Flux::modal('delete-social-'.$postId)->close();
    }

    public function copyMarkdown(int $postId): string
    {
        $post = SocialPost::where('team_id', $this->teamModel->id)->findOrFail($postId);
        $platformLabel = match ($post->platform) {
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'short_video' => 'Short-form Video',
            default => $post->platform,
        };
        $tags = is_array($post->hashtags) && $post->hashtags
            ? '#'.implode(' #', $post->hashtags)
            : '';
        $visualLabel = $post->platform === 'short_video' ? 'Video' : 'Image';
        $visualValue = $post->platform === 'short_video' ? $post->video_treatment : $post->image_prompt;

        return "**{$platformLabel}**\n\n{$post->hook}\n\n{$post->body}\n\n{$tags}\n\n---\n{$visualLabel}: {$visualValue}\n";
    }

    public function render()
    {
        return $this->view()->title($this->piece->title);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl" class="line-clamp-1">{{ $piece->title }}</flux:heading>
            @if ($this->posts->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->posts->count() }}</flux:badge>
            @endif
        </div>
        @php $refineConv = $this->refineConversation; @endphp
        @if ($refineConv)
            <flux:button variant="primary" size="sm" icon="chat-bubble-left-right"
                :href="route('create.chat', ['current_team' => $teamModel, 'conversation' => $refineConv])" wire:navigate>
                {{ __('Refine in chat') }}
            </flux:button>
        @else
            <flux:button variant="primary" size="sm" icon="chat-bubble-left-right"
                :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
                {{ __('Build a Funnel') }}
            </flux:button>
        @endif
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->posts->isEmpty())
            <div class="py-20 text-center">
                <flux:subheading>{{ __('No active posts for this piece.') }}</flux:subheading>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->posts as $post)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 {{ $post->posted_at ? 'opacity-60' : '' }}">
                        <div class="mb-2 flex items-center justify-between">
                            <flux:badge variant="pill" size="sm">{{ ucfirst(str_replace('_', ' ', $post->platform)) }}</flux:badge>
                            @if ($post->posted_at)
                                <flux:badge variant="pill" size="sm" color="lime">{{ __('Posted') }}</flux:badge>
                            @endif
                        </div>
                        <p class="font-semibold">{{ $post->hook }}</p>
                        <div class="prose prose-sm mt-2 text-zinc-600 dark:text-zinc-300">
                            {!! str_replace('[POST_URL]', '<span class="rounded bg-amber-100 px-1 font-mono text-xs text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">[POST_URL]</span>', e($post->body)) !!}
                        </div>
                        @if (! empty($post->hashtags))
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($post->hashtags as $tag)
                                    <flux:badge variant="pill" size="sm">#{{ $tag }}</flux:badge>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-3 rounded-md bg-zinc-50 p-2 text-xs dark:bg-zinc-800/50">
                            <span class="font-semibold">{{ $post->platform === 'short_video' ? __('Video') : __('Image') }}:</span>
                            {{ $post->platform === 'short_video' ? $post->video_treatment : $post->image_prompt }}
                        </div>

                        <div class="mt-3 flex items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-1">
                                @for ($s = 1; $s <= 10; $s++)
                                    <button wire:click="updateScore({{ $post->id }}, {{ $s }})"
                                        class="size-5 rounded-full text-xs {{ $post->score && $post->score >= $s ? 'bg-indigo-500 text-white' : 'bg-zinc-100 dark:bg-zinc-700' }}">{{ $s }}</button>
                                @endfor
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:button size="xs" variant="ghost" icon="check"
                                    wire:click="togglePosted({{ $post->id }})">
                                    {{ $post->posted_at ? __('Unposted') : __('Posted') }}
                                </flux:button>
                                <flux:button size="xs" variant="ghost" icon="clipboard"
                                    x-on:click="navigator.clipboard.writeText(@js($this->copyMarkdown($post->id)))">
                                    {{ __('Copy') }}
                                </flux:button>
                                <flux:modal.trigger name="delete-social-{{ $post->id }}">
                                    <flux:button size="xs" variant="ghost" icon="trash" />
                                </flux:modal.trigger>
                            </div>
                        </div>
                    </div>

                    <flux:modal name="delete-social-{{ $post->id }}">
                        <div class="space-y-4">
                            <flux:heading>{{ __('Delete this post?') }}</flux:heading>
                            <flux:subheading>{{ __('This is a soft delete; you can rebuild the funnel any time.') }}</flux:subheading>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                <flux:button variant="danger" wire:click="deletePost({{ $post->id }})">{{ __('Delete') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endforeach
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/sail test --filter=SocialPieceTest
```
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/teams/⚡social-piece.blade.php tests/Feature/Pages/SocialPieceTest.php
git commit -m "feat(social): implement social subpage with cards"
```

---

## Task 10: End-to-end smoke and full test suite

**Files:** none (verification only)

- [ ] **Step 1: Run the entire suite**

```bash
./vendor/bin/sail test
```
Expected: green across the board. If anything fails, fix it before declaring done.

- [ ] **Step 2: Manual sanity check**

Spin up the app via `./vendor/bin/sail up -d` if not running. Sign in, switch to a team that has at least one ContentPiece, and click Start Creating → Build a Funnel. Verify:
- Picker lists pieces.
- After picking a piece, the chat input appears and a `funnel` badge shows.
- The Social sidebar entry navigates to a populated/empty index.

- [ ] **Step 3: Final commit (if any cleanup)**

If the suite or manual check turned up anything, commit fixes with focused messages. Otherwise this task is a no-op.

---

## Self-review (filled in)

**Spec coverage:**
- Workflow & dispatch via `?type=funnel` → Tasks 5, 6.
- Data model → Tasks 1, 2.
- Generation flow & tools → Tasks 3, 4, 5, 6.
- Routes & navigation → Task 7.
- Social index page → Task 8.
- Social subpage with cards (score 1–10, Posted toggle, copy markdown, delete, refine) → Task 9.
- Lifecycle (soft delete only, replaceAll keeps history) → Task 4 (`replaceAll`, `delete`).
- Out-of-scope items in spec are not implemented (correct).

**Placeholder scan:** No "TBD"/"TODO"/"implement later". Each step contains the actual code or command.

**Type/name consistency:** `SocialPost`, `SocialPostToolHandler`, methods (`propose`, `update`, `delete`, `replaceAll`), tool names (`propose_posts`, `update_post`, `delete_post`, `replace_all_posts`), route names (`social.index`, `social.show`), Livewire methods on the subpage (`updateScore`, `togglePosted`, `deletePost`, `copyMarkdown`) all match across tasks.

**Constraint:** the validation in Task 4 enforces 3–6 posts, ≤1 short-form video, exactly one `[POST_URL]` per body, and required `image_prompt`/`video_treatment` per platform — matching the spec rules.
