# Social Planner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `planner` chat orchestrator + a singleton-per-team monthly Content Calendar with read-only month view, ◀ / ▶ navigation, and CSV export matching the team's current spreadsheet format.

**Architecture:** New `planner` chat type joins existing `brand`/`topics`/`writer`/`funnel`. Two new tables (`content_calendars` 1:1 with team, `calendar_entries` keyed by `scheduled_for`). Three existing tables gain a `used` boolean (`topics`, `social_posts`, `content_pieces`); `teams` gains a `posting_days` JSON. Agent writes calendar entries via tool calls; Calendar page only reads. CSV export pulls the visible month verbatim.

**Tech Stack:** Laravel 13, Sail (`./vendor/bin/sail`), PostgreSQL, Livewire Volt single-file components (`⚡*.blade.php` under `resources/views/pages/teams/`), Flux UI, Pest tests.

---

## Spec reference
- `docs/superpowers/specs/2026-05-18-social-planner-design.md`

## Pattern references (read before starting)
- Tool handler shape: `app/Services/SocialPostToolHandler.php`
- Tool handler test shape: `tests/Feature/SocialPostToolHandlerTest.php`
- Chat-type prompt shape: `app/Services/ChatPromptBuilder.php::funnelPrompt`
- Chat-type test shape: `tests/Unit/Services/ChatPromptBuilderTopicsTest.php`
- Chat surface (used for all chat types): `resources/views/pages/teams/⚡create-chat.blade.php`
- Index page that links to a chat type: `resources/views/pages/teams/⚡social.blade.php`
- Prompt-editing rules: `docs/system-prompt-guidelines.md` (read before Task 7).

## Conventions
- **All commands run through Sail.** `./vendor/bin/sail artisan ...`, `./vendor/bin/sail test ...`, `./vendor/bin/sail composer ...`. Bare `php`/`composer` will fail (host lacks `pdo_pgsql`).
- **Migrations are additive and reversible.** Never use `migrate:fresh` / `db:wipe` / `rollback` without explicit user confirmation.
- **TDD:** failing test → minimal impl → green → commit. Each task ends in a commit.
- **One change at a time** so A/B is possible — applies to prompt edits.

---

## Task 1: Migration — `content_calendars`

**Files:**
- Create: `database/migrations/2026_05_18_000001_create_content_calendars_table.php`

- [ ] **Step 1: Generate the migration**

```bash
./vendor/bin/sail artisan make:migration create_content_calendars_table
```

This produces a timestamped file. Rename it (or pass `--path` flags from your editor) to match `2026_05_18_000001_create_content_calendars_table.php` so the ordering is deterministic across this plan.

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('posting_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_calendars');
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
./vendor/bin/sail artisan migrate
```
Expected: `Migrating: 2026_05_18_000001_create_content_calendars_table` → `Migrated:`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_18_000001_create_content_calendars_table.php
git commit -m "feat(planner): add content_calendars table"
```

---

## Task 2: Migration — `calendar_entries`

**Files:**
- Create: `database/migrations/2026_05_18_000002_create_calendar_entries_table.php`

- [ ] **Step 1: Generate the migration**

```bash
./vendor/bin/sail artisan make:migration create_calendar_entries_table
```

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('content_calendars')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_for');
            $table->string('title');
            $table->text('image_headline')->nullable();
            $table->text('image_prompt')->nullable();
            $table->text('linkedin_copy')->nullable();
            $table->text('instagram_copy')->nullable();
            $table->text('facebook_copy')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('source_topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->foreignId('source_social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
            $table->foreignId('source_content_piece_id')->nullable()->constrained('content_pieces')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->unique(['calendar_id', 'scheduled_for']);
            $table->index(['team_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
    }
};
```

- [ ] **Step 3: Run and commit**

```bash
./vendor/bin/sail artisan migrate
git add database/migrations/2026_05_18_000002_create_calendar_entries_table.php
git commit -m "feat(planner): add calendar_entries table"
```

---

## Task 3: Migration — `used` flags + team `posting_days`

**Files:**
- Create: `database/migrations/2026_05_18_000003_add_used_flags_and_posting_days.php`

- [ ] **Step 1: Generate the migration**

```bash
./vendor/bin/sail artisan make:migration add_used_flags_and_posting_days
```

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['topics', 'social_posts', 'content_pieces'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('used')->default(false)->index();
            });
        }
        Schema::table('teams', function (Blueprint $t) {
            $t->json('posting_days')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['topics', 'social_posts', 'content_pieces'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('used');
            });
        }
        Schema::table('teams', function (Blueprint $t) {
            $t->dropColumn('posting_days');
        });
    }
};
```

- [ ] **Step 3: Run and commit**

```bash
./vendor/bin/sail artisan migrate
git add database/migrations/2026_05_18_000003_add_used_flags_and_posting_days.php
git commit -m "feat(planner): add used flags and team posting_days"
```

---

## Task 4: Model — `ContentCalendar`

**Files:**
- Create: `app/Models/ContentCalendar.php`
- Test: `tests/Unit/Models/ContentCalendarTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\User;

it('belongs to a team and casts posting_days as array', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    $cal = ContentCalendar::create([
        'team_id' => $team->id,
        'posting_days' => ['mon', 'wed', 'fri'],
    ]);

    expect($cal->fresh()->posting_days)->toBe(['mon', 'wed', 'fri']);
    expect($cal->team->id)->toBe($team->id);
});
```

- [ ] **Step 2: Run test (expect failure)**

```bash
./vendor/bin/sail test --filter ContentCalendarTest
```
Expected: FAIL — `Class App\Models\ContentCalendar not found`.

- [ ] **Step 3: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCalendar extends Model
{
    protected $fillable = ['team_id', 'posting_days'];

    protected $casts = [
        'posting_days' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CalendarEntry::class, 'calendar_id');
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter ContentCalendarTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/ContentCalendar.php tests/Unit/Models/ContentCalendarTest.php
git commit -m "feat(planner): add ContentCalendar model"
```

---

## Task 5: Model — `CalendarEntry` with auto-mark-used

**Files:**
- Create: `app/Models/CalendarEntry.php`
- Test: `tests/Unit/Models/CalendarEntryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;

it('flips source topic to used when set', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    $cal = ContentCalendar::create(['team_id' => $team->id]);
    $topic = Topic::factory()->for($team)->create(['used' => false]);

    CalendarEntry::create([
        'calendar_id' => $cal->id,
        'team_id' => $team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Idea',
        'source_topic_id' => $topic->id,
    ]);

    expect($topic->fresh()->used)->toBeTrue();
});

it('does not unmark source when entry is deleted', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    $cal = ContentCalendar::create(['team_id' => $team->id]);
    $topic = Topic::factory()->for($team)->create(['used' => false]);

    $entry = CalendarEntry::create([
        'calendar_id' => $cal->id,
        'team_id' => $team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Idea',
        'source_topic_id' => $topic->id,
    ]);
    $entry->delete();

    expect($topic->fresh()->used)->toBeTrue();
});
```

- [ ] **Step 2: Run test (expect failure)**

```bash
./vendor/bin/sail test --filter CalendarEntryTest
```
Expected: FAIL — model missing.

- [ ] **Step 3: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEntry extends Model
{
    protected $fillable = [
        'calendar_id', 'team_id', 'scheduled_for',
        'title', 'image_headline', 'image_prompt',
        'linkedin_copy', 'instagram_copy', 'facebook_copy',
        'notes',
        'source_topic_id', 'source_social_post_id', 'source_content_piece_id',
        'status',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(function (CalendarEntry $entry) {
            if ($entry->source_topic_id && $entry->wasChanged('source_topic_id') || ($entry->wasRecentlyCreated && $entry->source_topic_id)) {
                Topic::whereKey($entry->source_topic_id)->update(['used' => true]);
            }
            if ($entry->source_social_post_id && ($entry->wasChanged('source_social_post_id') || ($entry->wasRecentlyCreated && $entry->source_social_post_id))) {
                SocialPost::whereKey($entry->source_social_post_id)->update(['used' => true]);
            }
            if ($entry->source_content_piece_id && ($entry->wasChanged('source_content_piece_id') || ($entry->wasRecentlyCreated && $entry->source_content_piece_id))) {
                ContentPiece::whereKey($entry->source_content_piece_id)->update(['used' => true]);
            }
        });
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ContentCalendar::class, 'calendar_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sourceTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'source_topic_id');
    }

    public function sourceSocialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'source_social_post_id');
    }

    public function sourceContentPiece(): BelongsTo
    {
        return $this->belongsTo(ContentPiece::class, 'source_content_piece_id');
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter CalendarEntryTest
```
Expected: PASS for both tests.

- [ ] **Step 5: Commit**

```bash
git add app/Models/CalendarEntry.php tests/Unit/Models/CalendarEntryTest.php
git commit -m "feat(planner): add CalendarEntry model with auto-mark-used"
```

---

## Task 6: Update `Team`, `Topic`, `SocialPost`, `ContentPiece` casts/fillable

**Files:**
- Modify: `app/Models/Team.php` — add `posting_days` to `$fillable` and `'posting_days' => 'array'` to `$casts`; add `calendar()` hasOne to `ContentCalendar`.
- Modify: `app/Models/Topic.php` — add `'used'` to `$fillable` and `'used' => 'boolean'` to `$casts`.
- Modify: `app/Models/SocialPost.php` — add `'used'` to `$fillable` and `'used' => 'boolean'` to `$casts`.
- Modify: `app/Models/ContentPiece.php` — add `'used'` to `$fillable` and `'used' => 'boolean'` to `$casts`.

- [ ] **Step 1: Edit each model.** For each of the four, locate the `$fillable` array and `$casts` array (or `casts()` method) and add the field as listed above. For `Team`, also add:

```php
public function calendar(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\ContentCalendar::class);
}
```

- [ ] **Step 2: Quick sanity test**

```bash
./vendor/bin/sail artisan tinker --execute="echo App\Models\Team::first()?->id ?? 'no team';"
```
Expected: prints a team id or `no team`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Team.php app/Models/Topic.php app/Models/SocialPost.php app/Models/ContentPiece.php
git commit -m "feat(planner): expose used + posting_days on models"
```

---

## Task 7: Planner prompt in `ChatPromptBuilder`

Read `docs/system-prompt-guidelines.md` first.

**Files:**
- Modify: `app/Services/ChatPromptBuilder.php`
- Test: `tests/Unit/Services/ChatPromptBuilderPlannerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Team;
use App\Models\User;
use App\Services\ChatPromptBuilder;

it('builds a planner prompt with required sections', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'owner_id' => $user->id,
        'content_language' => 'sl',
        'posting_days' => ['mon', 'wed', 'fri'],
    ]);

    $prompt = ChatPromptBuilder::build('planner', $team);

    expect($prompt)->toContain('content calendar');
    expect($prompt)->toContain('propose_entries');
    expect($prompt)->toContain('update_entry');
    expect($prompt)->toContain('delete_entry');
    expect($prompt)->toContain('mark_used');
    expect($prompt)->toContain('<brand-profile>');
    expect($prompt)->toContain('posting days');
});

it('lists planner tools', function () {
    $tools = ChatPromptBuilder::tools('planner');
    $names = collect($tools)->map(fn ($t) => $t['function']['name'])->all();

    expect($names)->toContain('propose_entries');
    expect($names)->toContain('update_entry');
    expect($names)->toContain('delete_entry');
    expect($names)->toContain('mark_used');
    expect($names)->toContain('list_available_pool');
});
```

- [ ] **Step 2: Run test (expect failure)**

```bash
./vendor/bin/sail test --filter ChatPromptBuilderPlannerTest
```
Expected: FAIL.

- [ ] **Step 3: Add the planner branch to `ChatPromptBuilder::build`**

In `app/Services/ChatPromptBuilder.php`, extend the `match ($type)` in `build()`:

```php
'planner' => self::plannerPrompt($profile, $team, $conversation),
```

Extend `tools()`:

```php
'planner' => [
    CalendarEntryToolHandler::proposeSchema(),
    CalendarEntryToolHandler::updateSchema(),
    CalendarEntryToolHandler::deleteSchema(),
    MarkUsedToolHandler::toolSchema(),
    ListAvailablePoolToolHandler::toolSchema(),
    BrandIntelligenceToolHandler::fetchUrlToolSchema(),
],
```

Add a `use` line at the top: `use App\Services\CalendarEntryToolHandler; use App\Services\MarkUsedToolHandler; use App\Services\ListAvailablePoolToolHandler;`

Append a new private method:

```php
private static function plannerPrompt(string $profile, Team $team, ?Conversation $conversation): string
{
    $calendar = $team->calendar; // may be null until first turn
    $postingDays = $calendar?->posting_days ?? $team->posting_days ?? ['mon', 'wed', 'fri'];
    $postingDaysStr = implode(', ', array_map('ucfirst', $postingDays));

    $month = $conversation?->brief['planner_month'] ?? now()->format('Y-m');
    [$year, $monthNum] = explode('-', $month);

    $entries = $calendar
        ? \App\Models\CalendarEntry::where('calendar_id', $calendar->id)
            ->whereYear('scheduled_for', (int) $year)
            ->whereMonth('scheduled_for', (int) $monthNum)
            ->orderBy('scheduled_for')
            ->get()
        : collect();

    $entriesBlock = $entries->isEmpty()
        ? 'No entries yet for this month.'
        : $entries->map(function ($e) {
            $filled = collect([
                $e->image_headline ? 'image' : null,
                $e->linkedin_copy ? 'li' : null,
                $e->instagram_copy ? 'ig' : null,
                $e->facebook_copy ? 'fb' : null,
            ])->filter()->implode('+');
            return "- id={$e->id} {$e->scheduled_for->format('Y-m-d')} ({$filled}): {$e->title}";
        })->implode("\n");

    $topics = \App\Models\Topic::where('team_id', $team->id)->where('used', false)->latest()->limit(15)->get(['id', 'title']);
    $pieces = \App\Models\ContentPiece::where('team_id', $team->id)->where('used', false)->latest()->limit(15)->get(['id', 'title']);
    $posts = \App\Models\SocialPost::where('team_id', $team->id)->where('used', false)->where('status', 'active')->latest()->limit(15)->get(['id', 'platform', 'hook']);

    $poolBlock = "Unused topics:\n" . ($topics->isEmpty() ? '(none)' : $topics->map(fn ($t) => "- topic_id={$t->id}: {$t->title}")->implode("\n"));
    $poolBlock .= "\n\nUnused content pieces:\n" . ($pieces->isEmpty() ? '(none)' : $pieces->map(fn ($p) => "- content_piece_id={$p->id}: {$p->title}")->implode("\n"));
    $poolBlock .= "\n\nUnused social posts:\n" . ($posts->isEmpty() ? '(none)' : $posts->map(fn ($p) => "- social_post_id={$p->id} [{$p->platform}]: {$p->hook}")->implode("\n"));

    return <<<PROMPT
You are a social-media content planner. Your job is to help the user prepare a monthly content calendar by pulling from their unused topics, social posts, and content pieces, and brainstorming fresh ideas for empty days.

## CRITICAL: function calling
Every response that creates, changes, or removes calendar entries MUST end with a tool call. The user only sees entries that you save through `propose_entries` / `update_entry` / `delete_entry`. Plain-text drafts are invisible.
- Never say "added", "scheduled", "saved", "updated", "removed" unless the matching tool was called this turn.
- When brainstorming for empty days, call `propose_entries` with all the new entries in one call.
- When fixing one day's copy, call `update_entry(id, fields)`.
- When dropping an entry, call `delete_entry(id)`.

## Your tools
- `propose_entries(entries[])` — REQUIRED to populate empty days. Setting `source_topic_id` / `source_social_post_id` / `source_content_piece_id` flips that source to `used`.
- `update_entry(id, fields)` — patch one entry.
- `delete_entry(id)` — drop one entry. Does not unmark its source.
- `mark_used(type, id, used)` — toggle `used` on a topic / social_post / content_piece.
- `list_available_pool` — refresh the unused pool mid-conversation.
- `fetch_url(url)` — read a web page.
- `web_search(query)` — research before brainstorming fresh ideas.

## How to work
1. Look at the month's existing entries and the posting-days cadence below.
2. Identify gaps (posting days with no entry).
3. Suggest filling some from the unused pool and brainstorming the rest (with web research for timely angles).
4. Confirm direction with the user, then call `propose_entries` with all the rows in one call.
5. Iterate: user says "rewrite May 8 LinkedIn" → `update_entry`. User says "drop May 15" → `delete_entry`.

## Calendar context
Currently focused month: {$month}
Posting days: {$postingDaysStr}

<existing-entries>
{$entriesBlock}
</existing-entries>

<available-pool>
{$poolBlock}
</available-pool>

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter ChatPromptBuilderPlannerTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ChatPromptBuilder.php tests/Unit/Services/ChatPromptBuilderPlannerTest.php
git commit -m "feat(planner): add planner chat type prompt"
```

---

## Task 8: `CalendarEntryToolHandler` — propose_entries

**Files:**
- Create: `app/Services/CalendarEntryToolHandler.php`
- Test: `tests/Feature/CalendarEntryToolHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\CalendarEntryToolHandler;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
    $this->calendar = ContentCalendar::create(['team_id' => $this->team->id]);
});

it('propose creates entries and flips source used', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => false]);

    $result = CalendarEntryToolHandler::propose($this->team, $this->calendar, [
        'entries' => [
            [
                'scheduled_for' => '2026-05-06',
                'title' => 'Huawei headlights',
                'linkedin_copy' => 'Long LI body...',
                'source_topic_id' => $topic->id,
            ],
            [
                'scheduled_for' => '2026-05-08',
                'title' => 'Tesla Y prime',
                'linkedin_copy' => 'Another...',
            ],
        ],
    ]);

    expect($result['status'])->toBe('ok');
    expect(CalendarEntry::where('calendar_id', $this->calendar->id)->count())->toBe(2);
    expect($topic->fresh()->used)->toBeTrue();
});

it('propose enforces team scoping on source ids', function () {
    $otherTeam = Team::factory()->create(['owner_id' => User::factory()->create()->id]);
    $otherTopic = Topic::factory()->for($otherTeam)->create();

    $result = CalendarEntryToolHandler::propose($this->team, $this->calendar, [
        'entries' => [[
            'scheduled_for' => '2026-05-06',
            'title' => 'x',
            'source_topic_id' => $otherTopic->id,
        ]],
    ]);

    expect($result['status'])->toBe('error');
    expect(CalendarEntry::count())->toBe(0);
});
```

- [ ] **Step 2: Run test (expect failure)**

```bash
./vendor/bin/sail test --filter CalendarEntryToolHandlerTest
```
Expected: FAIL — class missing.

- [ ] **Step 3: Create the handler with `propose`**

```php
<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;

class CalendarEntryToolHandler
{
    private const ENTRY_FIELDS = [
        'scheduled_for', 'title', 'image_headline', 'image_prompt',
        'linkedin_copy', 'instagram_copy', 'facebook_copy', 'notes',
        'source_topic_id', 'source_social_post_id', 'source_content_piece_id',
        'status',
    ];

    public static function proposeSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_entries',
                'description' => 'Create one or more calendar entries for the current calendar. Setting a source_*_id flips that entity to used.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['entries'],
                    'properties' => [
                        'entries' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => self::entrySchema(),
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
                'name' => 'update_entry',
                'description' => 'Patch one calendar entry by id. Only include fields to change.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => array_merge(
                        ['id' => ['type' => 'integer']],
                        self::entrySchema()['properties'],
                    ),
                ],
            ],
        ];
    }

    public static function deleteSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'delete_entry',
                'description' => 'Delete one calendar entry by id. Does not unmark its source.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    private static function entrySchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scheduled_for', 'title'],
            'properties' => [
                'scheduled_for' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'title' => ['type' => 'string'],
                'image_headline' => ['type' => 'string'],
                'image_prompt' => ['type' => 'string'],
                'linkedin_copy' => ['type' => 'string'],
                'instagram_copy' => ['type' => 'string'],
                'facebook_copy' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'source_topic_id' => ['type' => 'integer'],
                'source_social_post_id' => ['type' => 'integer'],
                'source_content_piece_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'ready', 'published']],
            ],
        ];
    }

    public static function propose(Team $team, ContentCalendar $calendar, array $args): array
    {
        $entries = $args['entries'] ?? [];
        if (empty($entries)) {
            return ['status' => 'error', 'message' => 'No entries provided.'];
        }

        // Team-scope check on source ids
        foreach ($entries as $e) {
            if (! self::sourcesBelongToTeam($team, $e)) {
                return ['status' => 'error', 'message' => 'Source id does not belong to this team.'];
            }
        }

        $created = DB::transaction(function () use ($team, $calendar, $entries) {
            $ids = [];
            foreach ($entries as $e) {
                $row = $calendar->entries()->create(array_merge(
                    ['team_id' => $team->id],
                    collect($e)->only(self::ENTRY_FIELDS)->all(),
                ));
                $ids[] = $row->id;
            }
            return $ids;
        });

        return ['status' => 'ok', 'created_ids' => $created];
    }

    private static function sourcesBelongToTeam(Team $team, array $entry): bool
    {
        if (! empty($entry['source_topic_id'])
            && ! Topic::where('id', $entry['source_topic_id'])->where('team_id', $team->id)->exists()) {
            return false;
        }
        if (! empty($entry['source_social_post_id'])
            && ! SocialPost::where('id', $entry['source_social_post_id'])->where('team_id', $team->id)->exists()) {
            return false;
        }
        if (! empty($entry['source_content_piece_id'])
            && ! ContentPiece::where('id', $entry['source_content_piece_id'])->where('team_id', $team->id)->exists()) {
            return false;
        }
        return true;
    }
}
```

- [ ] **Step 4: Run test (expect pass)**

```bash
./vendor/bin/sail test --filter CalendarEntryToolHandlerTest
```
Expected: PASS for the two propose tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CalendarEntryToolHandler.php tests/Feature/CalendarEntryToolHandlerTest.php
git commit -m "feat(planner): CalendarEntryToolHandler::propose"
```

---

## Task 9: `CalendarEntryToolHandler` — update + delete

**Files:**
- Modify: `app/Services/CalendarEntryToolHandler.php`
- Modify: `tests/Feature/CalendarEntryToolHandlerTest.php`

- [ ] **Step 1: Add failing tests**

```php
it('updates a single entry by id', function () {
    $entry = $this->calendar->entries()->create([
        'team_id' => $this->team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Old',
    ]);

    $result = CalendarEntryToolHandler::update($this->team, [
        'id' => $entry->id,
        'title' => 'New title',
        'linkedin_copy' => 'LI body',
    ]);

    expect($result['status'])->toBe('ok');
    expect($entry->fresh()->title)->toBe('New title');
    expect($entry->fresh()->linkedin_copy)->toBe('LI body');
});

it('refuses to update an entry from another team', function () {
    $otherTeam = Team::factory()->create(['owner_id' => User::factory()->create()->id]);
    $otherCal = ContentCalendar::create(['team_id' => $otherTeam->id]);
    $entry = $otherCal->entries()->create([
        'team_id' => $otherTeam->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Old',
    ]);

    $result = CalendarEntryToolHandler::update($this->team, [
        'id' => $entry->id,
        'title' => 'Hacked',
    ]);

    expect($result['status'])->toBe('error');
    expect($entry->fresh()->title)->toBe('Old');
});

it('deletes an entry by id without unmarking source', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => true]);
    $entry = $this->calendar->entries()->create([
        'team_id' => $this->team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'x',
        'source_topic_id' => $topic->id,
    ]);

    $result = CalendarEntryToolHandler::delete($this->team, ['id' => $entry->id]);

    expect($result['status'])->toBe('ok');
    expect(CalendarEntry::find($entry->id))->toBeNull();
    expect($topic->fresh()->used)->toBeTrue();
});
```

- [ ] **Step 2: Run tests (expect failure)**

```bash
./vendor/bin/sail test --filter CalendarEntryToolHandlerTest
```
Expected: FAIL — methods missing.

- [ ] **Step 3: Add the methods**

In `app/Services/CalendarEntryToolHandler.php`, append:

```php
public static function update(Team $team, array $args): array
{
    $id = $args['id'] ?? null;
    if (! $id) return ['status' => 'error', 'message' => 'Missing id.'];

    $entry = CalendarEntry::where('id', $id)->where('team_id', $team->id)->first();
    if (! $entry) return ['status' => 'error', 'message' => 'Entry not found for this team.'];

    if (! self::sourcesBelongToTeam($team, $args)) {
        return ['status' => 'error', 'message' => 'Source id does not belong to this team.'];
    }

    $entry->fill(collect($args)->only(self::ENTRY_FIELDS)->all())->save();
    return ['status' => 'ok', 'id' => $entry->id];
}

public static function delete(Team $team, array $args): array
{
    $id = $args['id'] ?? null;
    if (! $id) return ['status' => 'error', 'message' => 'Missing id.'];

    $entry = CalendarEntry::where('id', $id)->where('team_id', $team->id)->first();
    if (! $entry) return ['status' => 'error', 'message' => 'Entry not found for this team.'];

    $entry->delete();
    return ['status' => 'ok'];
}
```

- [ ] **Step 4: Run tests (expect pass)**

```bash
./vendor/bin/sail test --filter CalendarEntryToolHandlerTest
```
Expected: PASS for all five tests in the file.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CalendarEntryToolHandler.php tests/Feature/CalendarEntryToolHandlerTest.php
git commit -m "feat(planner): CalendarEntryToolHandler::update + delete"
```

---

## Task 10: `MarkUsedToolHandler`

**Files:**
- Create: `app/Services/MarkUsedToolHandler.php`
- Test: `tests/Feature/MarkUsedToolHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\MarkUsedToolHandler;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
});

it('marks a topic as used', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => false]);

    $result = MarkUsedToolHandler::run($this->team, ['type' => 'topic', 'id' => $topic->id, 'used' => true]);

    expect($result['status'])->toBe('ok');
    expect($topic->fresh()->used)->toBeTrue();
});

it('unmarks a content piece', function () {
    $piece = ContentPiece::factory()->for($this->team)->create(['used' => true]);

    $result = MarkUsedToolHandler::run($this->team, ['type' => 'content_piece', 'id' => $piece->id, 'used' => false]);

    expect($result['status'])->toBe('ok');
    expect($piece->fresh()->used)->toBeFalse();
});

it('rejects unknown type', function () {
    $result = MarkUsedToolHandler::run($this->team, ['type' => 'banana', 'id' => 1, 'used' => true]);
    expect($result['status'])->toBe('error');
});

it('rejects cross-team ids', function () {
    $otherTeam = Team::factory()->create(['owner_id' => User::factory()->create()->id]);
    $topic = Topic::factory()->for($otherTeam)->create(['used' => false]);

    $result = MarkUsedToolHandler::run($this->team, ['type' => 'topic', 'id' => $topic->id, 'used' => true]);

    expect($result['status'])->toBe('error');
    expect($topic->fresh()->used)->toBeFalse();
});
```

- [ ] **Step 2: Run (expect failure)**

```bash
./vendor/bin/sail test --filter MarkUsedToolHandlerTest
```
Expected: FAIL.

- [ ] **Step 3: Create the handler**

```php
<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;

class MarkUsedToolHandler
{
    private const MAP = [
        'topic' => Topic::class,
        'social_post' => SocialPost::class,
        'content_piece' => ContentPiece::class,
    ];

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'mark_used',
                'description' => 'Toggle the used flag on a topic, social_post, or content_piece. Items marked used are excluded from future planner suggestions.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['type', 'id', 'used'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => array_keys(self::MAP)],
                        'id' => ['type' => 'integer'],
                        'used' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];
    }

    public static function run(Team $team, array $args): array
    {
        $type = $args['type'] ?? null;
        if (! isset(self::MAP[$type])) {
            return ['status' => 'error', 'message' => 'Unknown type.'];
        }
        $class = self::MAP[$type];
        $model = $class::where('id', $args['id'] ?? 0)->where('team_id', $team->id)->first();
        if (! $model) {
            return ['status' => 'error', 'message' => 'Not found for this team.'];
        }
        $model->used = (bool) ($args['used'] ?? true);
        $model->save();
        return ['status' => 'ok'];
    }
}
```

- [ ] **Step 4: Run (expect pass)**

```bash
./vendor/bin/sail test --filter MarkUsedToolHandlerTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/MarkUsedToolHandler.php tests/Feature/MarkUsedToolHandlerTest.php
git commit -m "feat(planner): MarkUsedToolHandler"
```

---

## Task 11: `ListAvailablePoolToolHandler`

**Files:**
- Create: `app/Services/ListAvailablePoolToolHandler.php`
- Test: `tests/Feature/ListAvailablePoolToolHandlerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\ListAvailablePoolToolHandler;

it('returns unused items for the team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    $t1 = Topic::factory()->for($team)->create(['used' => false, 'title' => 'Free']);
    Topic::factory()->for($team)->create(['used' => true, 'title' => 'Taken']);

    $result = ListAvailablePoolToolHandler::run($team);

    expect($result['status'])->toBe('ok');
    expect(collect($result['topics'])->pluck('title')->all())->toBe(['Free']);
});
```

- [ ] **Step 2: Run (expect failure)**

```bash
./vendor/bin/sail test --filter ListAvailablePoolToolHandlerTest
```

- [ ] **Step 3: Create the handler**

```php
<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;

class ListAvailablePoolToolHandler
{
    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_available_pool',
                'description' => 'Return the current unused topics, content pieces, and social posts for this team.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    public static function run(Team $team): array
    {
        return [
            'status' => 'ok',
            'topics' => Topic::where('team_id', $team->id)->where('used', false)->latest()->limit(25)->get(['id', 'title'])->all(),
            'content_pieces' => ContentPiece::where('team_id', $team->id)->where('used', false)->latest()->limit(25)->get(['id', 'title'])->all(),
            'social_posts' => SocialPost::where('team_id', $team->id)->where('used', false)->where('status', 'active')->latest()->limit(25)->get(['id', 'platform', 'hook'])->all(),
        ];
    }
}
```

- [ ] **Step 4: Run (expect pass)**

```bash
./vendor/bin/sail test --filter ListAvailablePoolToolHandlerTest
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/ListAvailablePoolToolHandler.php tests/Feature/ListAvailablePoolToolHandlerTest.php
git commit -m "feat(planner): ListAvailablePoolToolHandler"
```

---

## Task 12: Wire planner tool dispatch into the chat runner

Look at how existing tool calls are dispatched. Find the orchestrator that consumes `ChatPromptBuilder::tools()` and routes tool calls to handler classes. Search:

```bash
grep -rn "SocialPostToolHandler::" app/ | head -20
```

This is the existing dispatch site for the `funnel` agent (look in `app/Livewire/...` or wherever the chat runner lives — likely a job class that processes assistant turns).

**Files:**
- Modify: the chat-runner file that maps tool names → handler class methods (one site). Add branches for `propose_entries`, `update_entry`, `delete_entry`, `mark_used`, `list_available_pool` that call into the three new handlers, passing the `Team` and (for entry tools) the `ContentCalendar` resolved via `$team->calendar()->firstOrCreate(['team_id' => $team->id])`.

- [ ] **Step 1: Locate the dispatch site.** Run the grep above and read the surrounding context — note the exact switch/match.

- [ ] **Step 2: Add the planner branches.** Mirror the funnel pattern. Each handler returns an array; serialize it to JSON for the tool result message the same way funnel does.

- [ ] **Step 3: Quick smoke run**

```bash
./vendor/bin/sail test
```
Expected: existing tests still pass.

- [ ] **Step 4: Commit**

```bash
git add -p   # stage the dispatcher edit only
git commit -m "feat(planner): dispatch planner tool calls"
```

---

## Task 13: Planner index page (sidebar entry)

**Files:**
- Create: `resources/views/pages/teams/⚡planner.blade.php`
- Modify: `routes/web.php`

Mirror `resources/views/pages/teams/⚡social.blade.php`. The page lists existing planner conversations for the team (if any) and shows a primary button **Start Planning** that links to `route('create.new', ['current_team' => $teamModel, 'type' => 'planner'])`.

- [ ] **Step 1: Add the route**

In `routes/web.php`, inside the prefix group, alongside `topics` / `social`:

```php
Route::livewire('planner', 'pages::teams.planner')->name('planner');
```

- [ ] **Step 2: Create the Livewire page** by copy-adapting `⚡social.blade.php`:
  - Replace heading copy: "Planner", "Plan your month".
  - Replace icon: `calendar-days`.
  - Replace `route('create.new', [..., 'type' => 'funnel'])` with `'type' => 'planner'`.
  - Replace the data lookup (`getPiecesProperty`) with conversations of type `planner`:

```php
public function getConversationsProperty()
{
    return \App\Models\Conversation::where('team_id', $this->teamModel->id)
        ->where('type', 'planner')
        ->latest()
        ->get();
}
```

- [ ] **Step 3: Wire `planner` into the unified chat surface.** In `resources/views/pages/teams/⚡create-chat.blade.php`, find the spots that list chat types (search for `'funnel' => __('Funnel'),`) and add `'planner' => __('Planner'),` alongside. Also add a card in the type-selector grid mirroring the `funnel` card around line 753.

- [ ] **Step 4: Add to sidebar.** Find the sidebar nav (search for `route('topics')` in `resources/views/`) and add an item that links to `route('planner', ['current_team' => $currentTeam])` with icon `calendar-days`. Add another for the Calendar page (will be wired in Task 14): `route('calendar.index', ...)` icon `calendar`.

- [ ] **Step 5: Smoke check**

```bash
./vendor/bin/sail test
```
Then open `/<team>/planner` in the browser to confirm the page renders and the button creates a `type=planner` conversation.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/pages/teams/⚡planner.blade.php resources/views/pages/teams/⚡create-chat.blade.php resources/views/partials/   # adjust per actual sidebar file
git commit -m "feat(planner): planner index page + sidebar"
```

---

## Task 14: Calendar page (read-only month view)

**Files:**
- Create: `resources/views/pages/teams/⚡calendar.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add the route**

```php
Route::livewire('calendar', 'pages::teams.calendar')->name('calendar.index');
```

- [ ] **Step 2: Create the page**

```blade
<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use Carbon\Carbon;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;
    public string $month;   // 'YYYY-MM'

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
        $this->month = now()->format('Y-m');
    }

    public function prevMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonth()->format('Y-m');
    }

    public function getCalendarProperty()
    {
        return ContentCalendar::firstOrCreate(['team_id' => $this->teamModel->id]);
    }

    public function getRowsProperty()
    {
        $start = Carbon::parse($this->month.'-01');
        $end = $start->copy()->endOfMonth();

        $cal = $this->calendar;
        $postingDays = $cal->posting_days ?? $this->teamModel->posting_days ?? ['mon','wed','fri'];
        $postingDayNumbers = collect($postingDays)->map(fn ($d) => [
            'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>7,
        ][$d] ?? null)->filter()->all();

        $entries = CalendarEntry::where('calendar_id', $cal->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->orderBy('scheduled_for')
            ->get()
            ->keyBy(fn ($e) => $e->scheduled_for->format('Y-m-d'));

        $rows = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! in_array($d->isoWeekday(), $postingDayNumbers)) continue;
            $key = $d->format('Y-m-d');
            $rows[] = ['date' => $d->copy(), 'entry' => $entries->get($key)];
        }
        return $rows;
    }

    public function render()
    {
        return $this->view()->title(__('Calendar'));
    }
}; ?>

<div class="mx-auto max-w-7xl px-6 py-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Calendar') }}</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button size="sm" icon="chevron-left" wire:click="prevMonth" />
            <flux:badge size="sm" variant="pill">{{ \Carbon\Carbon::parse($month.'-01')->format('F Y') }}</flux:badge>
            <flux:button size="sm" icon="chevron-right" wire:click="nextMonth" />
            <flux:button size="sm" variant="primary" icon="arrow-down-tray" :href="route('calendar.export', ['current_team' => $teamModel, 'month' => $month])">
                {{ __('Export CSV') }}
            </flux:button>
        </div>
    </div>

    <table class="mt-4 w-full text-sm">
        <thead>
            <tr class="text-left">
                <th>Day</th><th>Weekday</th><th>Idea</th><th>Image Headline</th>
                <th>LinkedIn</th><th>Instagram</th><th>Facebook</th><th>Notes</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($this->rows as $r)
            @php $e = $r['entry']; @endphp
            <tr class="border-t">
                <td>{{ $r['date']->day }}</td>
                <td>{{ strtolower($r['date']->format('l')) }}</td>
                <td>{{ $e->title ?? '—' }}</td>
                <td>{{ $e->image_headline ?? '' }}</td>
                <td class="max-w-xs whitespace-pre-line">{{ $e->linkedin_copy ?? '' }}</td>
                <td class="max-w-xs whitespace-pre-line">{{ $e->instagram_copy ?? '' }}</td>
                <td class="max-w-xs whitespace-pre-line">{{ $e->facebook_copy ?? '' }}</td>
                <td>{{ $e->notes ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 3: Smoke check** — open `/<team>/calendar` in the browser, click ◀ / ▶, verify rows render for posting days.

- [ ] **Step 4: Commit**

```bash
git add routes/web.php resources/views/pages/teams/⚡calendar.blade.php
git commit -m "feat(planner): read-only Calendar page with month nav"
```

---

## Task 15: CSV export endpoint

**Files:**
- Create: `app/Http/Controllers/CalendarExportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CalendarCsvExportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\User;

it('exports the visible month as CSV in CSV-sample order', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id, 'content_language' => 'sl']);
    $this->actingAs($user);
    $team->users()->attach($user, ['role' => 'owner']);
    $cal = ContentCalendar::create(['team_id' => $team->id, 'posting_days' => ['mon','wed','fri']]);
    CalendarEntry::create([
        'calendar_id' => $cal->id,
        'team_id' => $team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Huawei',
        'image_headline' => 'Lights on the wall',
        'linkedin_copy' => 'LI body',
        'instagram_copy' => 'IG body',
        'facebook_copy' => 'FB body',
        'notes' => 'Vir: Huawei',
    ]);

    $response = $this->get(route('calendar.export', ['current_team' => $team, 'month' => '2026-05']));

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Ideja,Grafika Copy,LinkedIn Copy,Instagram Copy,Facebook Copy,Opombe / Avtor slike');
    expect($csv)->toContain('Huawei');
    expect($csv)->toContain('Vir: Huawei');
});
```

(If the team-membership attach pattern differs in this codebase, copy the setup used in another feature test such as `tests/Feature/SocialPostToolHandlerTest.php`.)

- [ ] **Step 2: Run (expect failure)**

```bash
./vendor/bin/sail test --filter CalendarCsvExportTest
```

- [ ] **Step 3: Add the route**

```php
Route::get('calendar/export', \App\Http\Controllers\CalendarExportController::class)
    ->name('calendar.export');
```
(Inside the team-prefixed group in `routes/web.php`.)

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalendarExportController extends Controller
{
    public function __invoke(Request $request, Team $current_team): StreamedResponse
    {
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth();
        $cal = ContentCalendar::firstOrCreate(['team_id' => $current_team->id]);
        $postingDays = $cal->posting_days ?? $current_team->posting_days ?? ['mon','wed','fri'];
        $postingDayNumbers = collect($postingDays)->map(fn ($d) => [
            'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>7,
        ][$d] ?? null)->filter()->all();

        $entries = CalendarEntry::where('calendar_id', $cal->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->get()
            ->keyBy(fn ($e) => $e->scheduled_for->format('Y-m-d'));

        $headers = $current_team->content_language === 'sl'
            ? ['Ideja','Grafika Copy','LinkedIn Copy','Instagram Copy','Facebook Copy','Opombe / Avtor slike']
            : ['Idea','Image Headline','LinkedIn Copy','Instagram Copy','Facebook Copy','Notes'];

        $weekdayLabels = $current_team->content_language === 'sl'
            ? [1=>'ponedeljek',2=>'torek',3=>'sreda',4=>'četrtek',5=>'petek',6=>'sobota',7=>'nedelja']
            : [1=>'monday',2=>'tuesday',3=>'wednesday',4=>'thursday',5=>'friday',6=>'saturday',7=>'sunday'];

        $filename = ($current_team->slug ?? $current_team->id).'-calendar-'.$month.'.csv';

        return response()->streamDownload(function () use ($start, $end, $postingDayNumbers, $entries, $headers, $weekdayLabels) {
            $out = fopen('php://output', 'w');
            // CSV header: first cell is "DDMM" of the month start, second is empty, then column labels — matches the sample.
            fputcsv($out, array_merge([$start->format('dm'), ''], $headers));
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                if (! in_array($d->isoWeekday(), $postingDayNumbers)) continue;
                $e = $entries->get($d->format('Y-m-d'));
                fputcsv($out, [
                    $d->day,
                    $weekdayLabels[$d->isoWeekday()],
                    $e->title ?? '',
                    $e->image_headline ?? '',
                    $e->linkedin_copy ?? '',
                    $e->instagram_copy ?? '',
                    $e->facebook_copy ?? '',
                    $e->notes ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
```

- [ ] **Step 5: Run (expect pass)**

```bash
./vendor/bin/sail test --filter CalendarCsvExportTest
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CalendarExportController.php routes/web.php tests/Feature/CalendarCsvExportTest.php
git commit -m "feat(planner): Calendar CSV export"
```

---

## Task 16: Used toggle on existing pages

**Files:**
- Modify: `resources/views/pages/teams/⚡topics.blade.php`
- Modify: `resources/views/pages/teams/⚡social.blade.php` (and/or `⚡social-piece.blade.php`)
- Modify: `resources/views/pages/teams/⚡content.blade.php`

For each page, add a small "Mark used / Unmark" button on each item card and a muted style + "used" badge when `used=true`. The button calls a Livewire action on the same component, e.g.:

```php
public function toggleUsed(int $topicId): void
{
    $topic = \App\Models\Topic::where('team_id', $this->teamModel->id)->findOrFail($topicId);
    $topic->used = ! $topic->used;
    $topic->save();
}
```

…and in Blade:

```blade
<flux:button size="xs" variant="ghost" wire:click="toggleUsed({{ $topic->id }})">
    {{ $topic->used ? __('Unmark used') : __('Mark used') }}
</flux:button>
@if ($topic->used)
    <flux:badge size="xs">{{ __('used') }}</flux:badge>
@endif
```

Do the same for `SocialPost` (on the social/funnel listing) and `ContentPiece` (on the content listing).

- [ ] **Step 1: Add the toggle to topics page.** Smoke-check in the browser. Commit.

```bash
git add resources/views/pages/teams/⚡topics.blade.php
git commit -m "feat(planner): used toggle on Topics page"
```

- [ ] **Step 2: Add to social/funnel page.** Smoke-check. Commit.

```bash
git add resources/views/pages/teams/⚡social.blade.php resources/views/pages/teams/⚡social-piece.blade.php
git commit -m "feat(planner): used toggle on Social page"
```

- [ ] **Step 3: Add to content page.** Smoke-check. Commit.

```bash
git add resources/views/pages/teams/⚡content.blade.php
git commit -m "feat(planner): used toggle on Content page"
```

---

## Task 17: Feature test — Planner chat end-to-end

**Files:**
- Create: `tests/Feature/PlannerChatTest.php`

Pattern this after `tests/Feature/FunnelChatTypeTest.php`. Stub the LLM client the same way that test does; assert that a stubbed assistant turn with a `propose_entries` tool call persists `CalendarEntry` rows and flips the source `used` flag.

- [ ] **Step 1: Read `tests/Feature/FunnelChatTypeTest.php`** to learn the LLM-stub pattern in this codebase.

- [ ] **Step 2: Write the test** modelled on it, but stubbing a `propose_entries` tool call instead of `propose_posts`.

- [ ] **Step 3: Run and iterate until green**

```bash
./vendor/bin/sail test --filter PlannerChatTest
```

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/PlannerChatTest.php
git commit -m "test(planner): end-to-end chat test"
```

---

## Task 18: Feature test — Calendar page render

**Files:**
- Create: `tests/Feature/CalendarPageTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\User;
use Livewire\Volt\Volt;

it('renders posting-day rows for the current month, with entry data filled in', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id, 'posting_days' => ['mon','wed','fri']]);
    $team->users()->attach($user, ['role' => 'owner']);
    $cal = ContentCalendar::create(['team_id' => $team->id, 'posting_days' => ['mon','wed','fri']]);
    CalendarEntry::create([
        'calendar_id' => $cal->id,
        'team_id' => $team->id,
        'scheduled_for' => now()->startOfMonth()->next('monday')->format('Y-m-d'),
        'title' => 'Test entry',
    ]);

    $this->actingAs($user);

    Volt::test('pages::teams.calendar', ['current_team' => $team])
        ->assertSee('Test entry')
        ->call('nextMonth')
        ->assertDontSee('Test entry');
});
```

- [ ] **Step 2: Run**

```bash
./vendor/bin/sail test --filter CalendarPageTest
```

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/CalendarPageTest.php
git commit -m "test(planner): Calendar page render + nav"
```

---

## Task 19: Final smoke + push branch

- [ ] **Step 1: Run the full suite**

```bash
./vendor/bin/sail test
```
Expected: all green.

- [ ] **Step 2: Push and open PR (when user asks)**

```bash
git push -u origin feature/social-planner
gh pr create --title "feat: social planner agent + content calendar" --body "$(cat <<'EOF'
## Summary
- New planner chat orchestrator builds a monthly content calendar from existing topics/posts/pieces + brainstormed fillers.
- Read-only Calendar page with month nav + CSV export matching the team's existing format.
- `used` flag added to topics, social_posts, content_pieces so the agent doesn't re-suggest items.

## Test plan
- [ ] Open Planner page, start a planner chat, confirm `propose_entries` writes rows.
- [ ] Open Calendar page, confirm posting-day rows render with entry data, ◀ / ▶ navigation works.
- [ ] Export CSV for a populated month, confirm column order matches the team's spreadsheet.
- [ ] Mark a topic used from the Topics page; confirm planner stops suggesting it.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review

**Spec coverage** — every section of `docs/superpowers/specs/2026-05-18-social-planner-design.md` has a task: data model → Tasks 1–6; planner agent + tools → Tasks 7–12; UI surfaces → Tasks 13–14; CSV export → Task 15; used toggles → Task 16; tests → Tasks 17–18.

**Placeholders** — Tasks 12, 13 (sidebar nav), 16, and 17 reference existing files the engineer must follow rather than spelling out exact byte-for-byte changes. This is deliberate because the existing sidebar partial, chat-runner dispatcher, LLM stub, and per-page card markup vary in shape and a brittle copy here would be wrong. The engineer is told exactly which existing file to mirror in each case.

**Type consistency** — handler method names match across tasks: `CalendarEntryToolHandler::propose / update / delete`; `MarkUsedToolHandler::run`; `ListAvailablePoolToolHandler::run`. Tool schema names match the prompt's tool list: `propose_entries`, `update_entry`, `delete_entry`, `mark_used`, `list_available_pool`.

**Scope** — single plan, single feature branch, ~19 tasks. Reasonable for one execution cycle.
