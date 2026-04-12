# Team AI Settings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-team OpenRouter API key and model selection (fast/powerful) to the team settings page, with a sidebar quick-access link.

**Architecture:** Three columns added to the `teams` table (API key encrypted, two model strings with defaults). The existing team edit Livewire component gets new properties, methods, and a UI section. No new files besides the migration and test.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, Pest, PostgreSQL

**Working directory:** `marketminded-laravel/` — all paths relative to this. Run commands via `docker exec -w /var/www/html marketminded-laravel-laravel.test-1`.

---

## File Structure

### New Files

```
database/migrations/XXXX_add_ai_settings_to_teams_table.php  — Migration for 3 new columns
tests/Feature/Teams/TeamAiSettingsTest.php                     — Pest tests for AI settings
```

### Modified Files

```
app/Models/Team.php                                            — Add fillable, hidden, casts
resources/views/pages/teams/⚡edit.blade.php                   — Add AI settings section + Livewire methods
resources/views/layouts/app/sidebar.blade.php                  — Add settings quick-access link
```

---

## Task 1: Migration

**Files:**
- Create: `database/migrations/XXXX_add_ai_settings_to_teams_table.php`

- [ ] **Step 1: Generate migration via artisan**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration add_ai_settings_to_teams_table
```

- [ ] **Step 2: Write the migration**

Replace the generated migration file contents with:

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
            $table->text('openrouter_api_key')->nullable()->after('is_personal');
            $table->string('fast_model')->default('deepseek/deepseek-v3.2:nitro')->after('openrouter_api_key');
            $table->string('powerful_model')->default('deepseek/deepseek-v3.2:nitro')->after('fast_model');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['openrouter_api_key', 'fast_model', 'powerful_model']);
        });
    }
};
```

- [ ] **Step 3: Run migration**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan migrate
```

Expected: Migration runs successfully, 3 columns added to teams table.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: add ai settings columns to teams table"
```

---

## Task 2: Team Model Update

**Files:**
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Update the Fillable attribute**

In `app/Models/Team.php`, change line 15:

```php
#[Fillable(['name', 'slug', 'is_personal'])]
```

to:

```php
#[Fillable(['name', 'slug', 'is_personal', 'openrouter_api_key', 'fast_model', 'powerful_model'])]
```

- [ ] **Step 2: Add Hidden attribute**

Add this import at line 9 (after the existing Fillable import):

```php
use Illuminate\Database\Eloquent\Attributes\Hidden;
```

Add the Hidden attribute on the line after `#[Fillable(...)]`:

```php
#[Hidden(['openrouter_api_key'])]
```

- [ ] **Step 3: Update casts to encrypt API key**

Replace the `casts()` method (lines 89-94):

```php
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }
```

with:

```php
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'openrouter_api_key' => 'encrypted',
        ];
    }
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/Team.php
git commit -m "feat: add ai settings to Team model (fillable, hidden, encrypted)"
```

---

## Task 3: Tests

**Files:**
- Create: `tests/Feature/Teams/TeamAiSettingsTest.php`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('owner can update ai settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('openrouterApiKey', 'sk-or-test-key-123')
        ->set('fastModel', 'x-ai/grok-4.1-fast')
        ->set('powerfulModel', 'anthropic/claude-sonnet-4.6')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->openrouter_api_key)->toBe('sk-or-test-key-123');
    expect($team->fast_model)->toBe('x-ai/grok-4.1-fast');
    expect($team->powerful_model)->toBe('anthropic/claude-sonnet-4.6');
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

    expect($team->fast_model)->toBe('deepseek/deepseek-v3.2:nitro');
    expect($team->powerful_model)->toBe('deepseek/deepseek-v3.2:nitro');
    expect($team->openrouter_api_key)->toBeNull();
});

test('api key can be cleared', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['openrouter_api_key' => 'old-key']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('openrouterApiKey', '')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    expect($team->fresh()->openrouter_api_key)->toBeNull();
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

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/TeamAiSettingsTest.php
```

Expected: FAIL — `openrouterApiKey`, `fastModel`, `powerfulModel` properties and `updateAiSettings` method don't exist on the component yet.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Teams/TeamAiSettingsTest.php
git commit -m "test: add ai settings tests (red)"
```

---

## Task 4: Livewire Component + View

**Files:**
- Modify: `resources/views/pages/teams/⚡edit.blade.php`

- [ ] **Step 1: Add properties and method to the PHP block**

In the `⚡edit.blade.php` file, add these properties after `public bool $isCurrentTeam = false;` (around line 28):

```php
    public string $openrouterApiKey = '';

    public string $fastModel = '';

    public string $powerfulModel = '';
```

In the `mount()` method, after `$this->populateTeamData();`, add:

```php
        $this->openrouterApiKey = $team->openrouter_api_key ?? '';
        $this->fastModel = $team->fast_model;
        $this->powerfulModel = $team->powerful_model;
```

Add this new method after the `updateMember()` method:

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

- [ ] **Step 2: Add the AI settings section to the Blade template**

In the template section, find the closing `@endif` for the delete team section (the last `@endif` before the closing `</div>` of `<div class="space-y-10">`). Add this new section just before the delete team section (before `@if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])`):

```blade
            @if ($this->permissions->canUpdateTeam)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('AI settings') }}</flux:heading>
                        <flux:subheading>{{ __('Configure your team\'s AI model preferences') }}</flux:subheading>
                    </div>

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
                </div>
            @endif
```

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/TeamAiSettingsTest.php
```

Expected: All 6 tests PASS.

- [ ] **Step 4: Run full team test suite to check for regressions**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/
```

Expected: All existing team tests + 6 new AI settings tests PASS.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/teams/⚡edit.blade.php"
git commit -m "feat: add AI settings section to team edit page"
```

---

## Task 5: Sidebar Quick-Access Link

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Replace the sidebar footer links**

In `resources/views/layouts/app/sidebar.blade.php`, replace lines 25-33:

```blade
            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
```

with:

```blade
            <flux:sidebar.nav>
                @if (auth()->user()->currentTeam())
                    <flux:sidebar.item icon="cog-6-tooth" :href="route('teams.edit', auth()->user()->currentTeam())" :current="request()->routeIs('teams.edit')" wire:navigate>
                        {{ __('Team Settings') }}
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add team settings quick-access link in sidebar"
```

---

## Task 6: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test
```

Expected: All tests pass.

- [ ] **Step 2: Verify in browser**

Open http://localhost, register/login, then:

1. Navigate to team settings (via sidebar link or `/settings/teams/{slug}`)
2. Verify AI settings section appears with 3 fields
3. Enter an API key, change model values, save — confirm toast appears
4. Reload page — confirm values persist
5. Clear API key, save — confirm it accepts empty
6. As a Member role user — confirm AI settings section is hidden
