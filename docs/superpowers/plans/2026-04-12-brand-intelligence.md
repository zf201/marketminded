# Brand Intelligence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Brand Intelligence" page displaying AI-generated brand positioning, audience personas, and voice profile — with read/edit/delete capabilities and prerequisite validation.

**Architecture:** Three new Eloquent models with dedicated tables (BrandPositioning, AudiencePersona, VoiceProfile), each belonging to a Team. One inline Livewire page component handles all three sections with read/edit toggle modes and modals for persona CRUD. Prerequisite validation checks for homepage URL and OpenRouter API key before showing content.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, Pest, PostgreSQL

**Working directory:** `marketminded-laravel/` — all paths relative to this. Run commands via `docker exec -w /var/www/html marketminded-laravel-laravel.test-1`.

**CRITICAL RULES FOR ALL WORKERS:**
1. **Read Flux UI docs** (fluxui.dev/components) before writing any template code. Use the two-column settings layout pattern.
2. **Use artisan commands** (`make:model`, `make:migration`, etc.) to generate files. Never hand-write what artisan can generate.
3. **NEVER run destructive database commands** (`migrate:fresh`, `db:wipe`, `migrate:reset`, `migrate:rollback`). Only use `php artisan migrate` (forward-only). Tests use RefreshDatabase trait automatically.

---

## File Structure

### New Files

```
database/migrations/XXXX_create_brand_positionings_table.php
database/migrations/XXXX_create_audience_personas_table.php
database/migrations/XXXX_create_voice_profiles_table.php
app/Models/BrandPositioning.php
app/Models/AudiencePersona.php
app/Models/VoiceProfile.php
resources/views/pages/teams/⚡brand-intelligence.blade.php
tests/Feature/Teams/BrandIntelligenceTest.php
```

### Modified Files

```
app/Models/Team.php                                  — Add 3 relationship methods
resources/views/layouts/app/sidebar.blade.php        — Add Brand Intelligence nav item
routes/web.php                                       — Add brand.intelligence route
```

---

## Task 1: Migrations

**Files:**
- Create: `database/migrations/XXXX_create_brand_positionings_table.php`
- Create: `database/migrations/XXXX_create_audience_personas_table.php`
- Create: `database/migrations/XXXX_create_voice_profiles_table.php`

- [ ] **Step 1: Generate migrations via artisan**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration create_brand_positionings_table
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration create_audience_personas_table
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration create_voice_profiles_table
```

- [ ] **Step 2: Write the brand_positionings migration**

Replace the generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_positionings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('value_proposition')->nullable();
            $table->text('target_market')->nullable();
            $table->text('differentiators')->nullable();
            $table->text('core_problems')->nullable();
            $table->text('products_services')->nullable();
            $table->text('primary_cta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_positionings');
    }
};
```

- [ ] **Step 3: Write the audience_personas migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->text('pain_points')->nullable();
            $table->text('push')->nullable();
            $table->text('pull')->nullable();
            $table->text('anxiety')->nullable();
            $table->string('role')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_personas');
    }
};
```

- [ ] **Step 4: Write the voice_profiles migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('voice_analysis')->nullable();
            $table->text('content_types')->nullable();
            $table->text('should_avoid')->nullable();
            $table->text('should_use')->nullable();
            $table->text('style_inspiration')->nullable();
            $table->integer('preferred_length')->default(1500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_profiles');
    }
};
```

- [ ] **Step 5: Run migrations**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan migrate
```

Expected: 3 tables created successfully.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add brand intelligence tables (positionings, personas, voice profiles)"
```

---

## Task 2: Models

**Files:**
- Create: `app/Models/BrandPositioning.php`
- Create: `app/Models/AudiencePersona.php`
- Create: `app/Models/VoiceProfile.php`
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Generate models via artisan**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:model BrandPositioning
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:model AudiencePersona
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:model VoiceProfile
```

- [ ] **Step 2: Write BrandPositioning model**

Replace `app/Models/BrandPositioning.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'value_proposition', 'target_market', 'differentiators', 'core_problems', 'products_services', 'primary_cta'])]
class BrandPositioning extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
```

- [ ] **Step 3: Write AudiencePersona model**

Replace `app/Models/AudiencePersona.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'label', 'description', 'pain_points', 'push', 'pull', 'anxiety', 'role', 'sort_order'])]
class AudiencePersona extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
```

- [ ] **Step 4: Write VoiceProfile model**

Replace `app/Models/VoiceProfile.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'voice_analysis', 'content_types', 'should_avoid', 'should_use', 'style_inspiration', 'preferred_length'])]
class VoiceProfile extends Model
{
    protected $attributes = [
        'preferred_length' => 1500,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
```

- [ ] **Step 5: Add relationships to Team model**

In `app/Models/Team.php`, add these imports at the top (after existing imports):

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add these three methods after the existing `invitations()` method (after line 98):

```php
    public function brandPositioning(): HasOne
    {
        return $this->hasOne(BrandPositioning::class);
    }

    public function audiencePersonas(): HasMany
    {
        return $this->hasMany(AudiencePersona::class)->orderBy('sort_order');
    }

    public function voiceProfile(): HasOne
    {
        return $this->hasOne(VoiceProfile::class);
    }
```

- [ ] **Step 6: Commit**

```bash
git add app/Models/
git commit -m "feat: add BrandPositioning, AudiencePersona, VoiceProfile models with Team relationships"
```

---

## Task 3: Route + Sidebar

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Add the route**

In `routes/web.php`, inside the `Route::prefix('{current_team}')` group, add after the brand setup route:

```php
Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('brand', 'pages::teams.brand-setup')->name('brand.setup');
        Route::livewire('intelligence', 'pages::teams.brand-intelligence')->name('brand.intelligence');
    });
```

- [ ] **Step 2: Add sidebar nav item**

In `resources/views/layouts/app/sidebar.blade.php`, add the Brand Intelligence item after Brand Setup (after line 22):

```blade
                    <flux:sidebar.item icon="sparkles" :href="route('brand.intelligence')" :current="request()->routeIs('brand.intelligence')" wire:navigate>
                        {{ __('Brand Intelligence') }}
                    </flux:sidebar.item>
```

- [ ] **Step 3: Commit**

```bash
git add routes/web.php resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add brand intelligence route and sidebar link"
```

---

## Task 4: Tests

**Files:**
- Create: `tests/Feature/Teams/BrandIntelligenceTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Feature/Teams/BrandIntelligenceTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Models\AudiencePersona;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProfile;
use Livewire\Livewire;

// --- Page rendering ---

test('brand intelligence page can be rendered', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    $this->actingAs($user)
        ->get(route('brand.intelligence', ['current_team' => $team->slug]))
        ->assertOk();
});

test('guests cannot access brand intelligence', function () {
    $team = Team::factory()->create();

    $this->get(route('brand.intelligence', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

// --- Prerequisite validation ---

test('shows warning when homepage url is missing', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['homepage_url' => null, 'openrouter_api_key' => 'sk-test']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->assertSee('Homepage URL')
        ->assertSet('missingPrerequisites', true);
});

test('shows warning when api key is missing', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['homepage_url' => 'https://example.com', 'openrouter_api_key' => null]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->assertSee('OpenRouter API key')
        ->assertSet('missingPrerequisites', true);
});

test('no warning when prerequisites are met', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['homepage_url' => 'https://example.com', 'openrouter_api_key' => 'sk-test']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->assertSet('missingPrerequisites', false);
});

// --- Positioning CRUD ---

test('owner can save positioning', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('positioningForm.value_proposition', 'We make widgets')
        ->set('positioningForm.target_market', 'Developers')
        ->set('positioningForm.differentiators', 'Best in class')
        ->set('positioningForm.core_problems', 'Complexity')
        ->set('positioningForm.products_services', 'Widget Pro')
        ->set('positioningForm.primary_cta', 'Try free')
        ->call('savePositioning')
        ->assertHasNoErrors();

    $positioning = $team->fresh()->brandPositioning;
    expect($positioning)->not->toBeNull();
    expect($positioning->value_proposition)->toBe('We make widgets');
    expect($positioning->target_market)->toBe('Developers');
});

test('owner can update existing positioning', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    BrandPositioning::create(['team_id' => $team->id, 'value_proposition' => 'Old value']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('positioningForm.value_proposition', 'New value')
        ->call('savePositioning')
        ->assertHasNoErrors();

    expect($team->fresh()->brandPositioning->value_proposition)->toBe('New value');
});

test('member cannot save positioning', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('positioningForm.value_proposition', 'Hacked')
        ->call('savePositioning')
        ->assertForbidden();
});

// --- Persona CRUD ---

test('owner can create persona', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('personaForm.label', 'The Developer')
        ->set('personaForm.description', 'Writes code all day')
        ->set('personaForm.pain_points', 'Too many meetings')
        ->set('personaForm.push', 'Burnout')
        ->set('personaForm.pull', 'Better tools')
        ->set('personaForm.anxiety', 'Learning curve')
        ->set('personaForm.role', 'Senior Engineer')
        ->call('savePersona')
        ->assertHasNoErrors();

    $persona = $team->audiencePersonas()->first();
    expect($persona)->not->toBeNull();
    expect($persona->label)->toBe('The Developer');
    expect($persona->role)->toBe('Senior Engineer');
});

test('owner can update persona', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $persona = AudiencePersona::create(['team_id' => $team->id, 'label' => 'Old Name']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->call('editPersona', $persona->id)
        ->set('personaForm.label', 'New Name')
        ->call('savePersona')
        ->assertHasNoErrors();

    expect($persona->fresh()->label)->toBe('New Name');
});

test('owner can delete persona', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $persona = AudiencePersona::create(['team_id' => $team->id, 'label' => 'Doomed']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->call('deletePersona', $persona->id)
        ->assertHasNoErrors();

    expect(AudiencePersona::find($persona->id))->toBeNull();
});

test('member cannot create persona', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('personaForm.label', 'Hacked')
        ->call('savePersona')
        ->assertForbidden();
});

test('persona label is required', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('personaForm.label', '')
        ->call('savePersona')
        ->assertHasErrors(['personaForm.label']);
});

// --- Voice Profile CRUD ---

test('owner can save voice profile', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('voiceForm.voice_analysis', 'Professional but friendly')
        ->set('voiceForm.content_types', 'Educational, how-to')
        ->set('voiceForm.should_avoid', 'Jargon')
        ->set('voiceForm.should_use', 'Active voice')
        ->set('voiceForm.style_inspiration', 'Concise and direct')
        ->set('voiceForm.preferred_length', 2000)
        ->call('saveVoiceProfile')
        ->assertHasNoErrors();

    $voice = $team->fresh()->voiceProfile;
    expect($voice)->not->toBeNull();
    expect($voice->voice_analysis)->toBe('Professional but friendly');
    expect($voice->preferred_length)->toBe(2000);
});

test('member cannot save voice profile', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('voiceForm.voice_analysis', 'Hacked')
        ->call('saveVoiceProfile')
        ->assertForbidden();
});

test('preferred length must be between 100 and 10000', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->set('voiceForm.preferred_length', 50)
        ->call('saveVoiceProfile')
        ->assertHasErrors(['voiceForm.preferred_length']);
});

// --- Mount populates from existing data ---

test('mount populates positioning from existing data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    BrandPositioning::create(['team_id' => $team->id, 'value_proposition' => 'We rock']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->assertSet('positioningForm.value_proposition', 'We rock');
});

test('mount populates voice profile from existing data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    VoiceProfile::create(['team_id' => $team->id, 'voice_analysis' => 'Friendly tone', 'preferred_length' => 2000]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->assertSet('voiceForm.voice_analysis', 'Friendly tone')
        ->assertSet('voiceForm.preferred_length', 2000);
});

test('mount loads existing personas', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    AudiencePersona::create(['team_id' => $team->id, 'label' => 'Persona A', 'sort_order' => 0]);
    AudiencePersona::create(['team_id' => $team->id, 'label' => 'Persona B', 'sort_order' => 1]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['team' => $team])
        ->assertSet('hasPersonas', true);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/BrandIntelligenceTest.php
```

Expected: FAIL — component `pages::teams.brand-intelligence` doesn't exist yet.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Teams/BrandIntelligenceTest.php
git commit -m "test: add brand intelligence tests (red)"
```

---

## Task 5: Livewire Component (PHP class)

**Files:**
- Create: `resources/views/pages/teams/⚡brand-intelligence.blade.php`

This task creates the PHP class portion of the inline Livewire component. The Blade template follows in Task 6 (it's a large file — splitting keeps each task focused).

- [ ] **Step 1: Create the component with PHP class**

Create `resources/views/pages/teams/⚡brand-intelligence.blade.php` with this content:

```php
<?php

use App\Models\AudiencePersona;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\VoiceProfile;
use App\Support\TeamPermissions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public bool $missingPrerequisites = false;

    public array $missingItems = [];

    public bool $hasPositioning = false;

    public bool $hasPersonas = false;

    public bool $hasVoiceProfile = false;

    public bool $editingPositioning = false;

    public bool $editingVoiceProfile = false;

    public array $positioningForm = [
        'value_proposition' => '',
        'target_market' => '',
        'differentiators' => '',
        'core_problems' => '',
        'products_services' => '',
        'primary_cta' => '',
    ];

    public array $voiceForm = [
        'voice_analysis' => '',
        'content_types' => '',
        'should_avoid' => '',
        'should_use' => '',
        'style_inspiration' => '',
        'preferred_length' => 1500,
    ];

    public array $personaForm = [
        'label' => '',
        'description' => '',
        'pain_points' => '',
        'push' => '',
        'pull' => '',
        'anxiety' => '',
        'role' => '',
    ];

    public ?int $editingPersonaId = null;

    public array $personas = [];

    public ?array $positioning = null;

    public ?array $voiceProfile = null;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->checkPrerequisites();
        $this->loadData();
    }

    public function savePositioning(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'positioningForm.value_proposition' => ['nullable', 'string', 'max:10000'],
            'positioningForm.target_market' => ['nullable', 'string', 'max:10000'],
            'positioningForm.differentiators' => ['nullable', 'string', 'max:10000'],
            'positioningForm.core_problems' => ['nullable', 'string', 'max:10000'],
            'positioningForm.products_services' => ['nullable', 'string', 'max:10000'],
            'positioningForm.primary_cta' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->teamModel->brandPositioning()->updateOrCreate(
            ['team_id' => $this->teamModel->id],
            $validated['positioningForm'],
        );

        $this->editingPositioning = false;
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Positioning saved.'));
    }

    public function savePersona(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'personaForm.label' => ['required', 'string', 'max:255'],
            'personaForm.description' => ['nullable', 'string', 'max:10000'],
            'personaForm.pain_points' => ['nullable', 'string', 'max:10000'],
            'personaForm.push' => ['nullable', 'string', 'max:10000'],
            'personaForm.pull' => ['nullable', 'string', 'max:10000'],
            'personaForm.anxiety' => ['nullable', 'string', 'max:10000'],
            'personaForm.role' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingPersonaId) {
            $persona = $this->teamModel->audiencePersonas()->findOrFail($this->editingPersonaId);
            $persona->update($validated['personaForm']);
        } else {
            $sortOrder = $this->teamModel->audiencePersonas()->max('sort_order') ?? -1;
            $this->teamModel->audiencePersonas()->create(
                array_merge($validated['personaForm'], ['sort_order' => $sortOrder + 1]),
            );
        }

        $this->editingPersonaId = null;
        $this->resetPersonaForm();
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Persona saved.'));
    }

    public function editPersona(int $personaId): void
    {
        $persona = $this->teamModel->audiencePersonas()->findOrFail($personaId);

        $this->editingPersonaId = $persona->id;
        $this->personaForm = [
            'label' => $persona->label,
            'description' => $persona->description ?? '',
            'pain_points' => $persona->pain_points ?? '',
            'push' => $persona->push ?? '',
            'pull' => $persona->pull ?? '',
            'anxiety' => $persona->anxiety ?? '',
            'role' => $persona->role ?? '',
        ];
    }

    public function deletePersona(int $personaId): void
    {
        Gate::authorize('update', $this->teamModel);

        $this->teamModel->audiencePersonas()->findOrFail($personaId)->delete();
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Persona deleted.'));
    }

    public function saveVoiceProfile(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'voiceForm.voice_analysis' => ['nullable', 'string', 'max:10000'],
            'voiceForm.content_types' => ['nullable', 'string', 'max:10000'],
            'voiceForm.should_avoid' => ['nullable', 'string', 'max:10000'],
            'voiceForm.should_use' => ['nullable', 'string', 'max:10000'],
            'voiceForm.style_inspiration' => ['nullable', 'string', 'max:10000'],
            'voiceForm.preferred_length' => ['required', 'integer', 'min:100', 'max:10000'],
        ]);

        $this->teamModel->voiceProfile()->updateOrCreate(
            ['team_id' => $this->teamModel->id],
            $validated['voiceForm'],
        );

        $this->editingVoiceProfile = false;
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Voice profile saved.'));
    }

    public function startEditingPositioning(): void
    {
        $this->editingPositioning = true;
    }

    public function cancelEditingPositioning(): void
    {
        $this->editingPositioning = false;
        $this->loadData();
    }

    public function startEditingVoiceProfile(): void
    {
        $this->editingVoiceProfile = true;
    }

    public function cancelEditingVoiceProfile(): void
    {
        $this->editingVoiceProfile = false;
        $this->loadData();
    }

    public function resetPersonaForm(): void
    {
        $this->editingPersonaId = null;
        $this->personaForm = [
            'label' => '',
            'description' => '',
            'pain_points' => '',
            'push' => '',
            'pull' => '',
            'anxiety' => '',
            'role' => '',
        ];
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        return $this->view()->title(__('Brand Intelligence'));
    }

    private function checkPrerequisites(): void
    {
        $this->missingItems = [];

        if (! $this->teamModel->homepage_url) {
            $this->missingItems[] = ['label' => 'Homepage URL', 'route' => 'brand.setup'];
        }

        if (! $this->teamModel->openrouter_api_key) {
            $this->missingItems[] = ['label' => 'OpenRouter API key', 'route' => 'teams.edit'];
        }

        $this->missingPrerequisites = count($this->missingItems) > 0;
    }

    private function loadData(): void
    {
        $positioning = $this->teamModel->brandPositioning;
        $this->hasPositioning = $positioning !== null;

        if ($positioning) {
            $this->positioning = $positioning->only([
                'value_proposition', 'target_market', 'differentiators',
                'core_problems', 'products_services', 'primary_cta',
            ]);
            $this->positioningForm = [
                'value_proposition' => $positioning->value_proposition ?? '',
                'target_market' => $positioning->target_market ?? '',
                'differentiators' => $positioning->differentiators ?? '',
                'core_problems' => $positioning->core_problems ?? '',
                'products_services' => $positioning->products_services ?? '',
                'primary_cta' => $positioning->primary_cta ?? '',
            ];
        }

        $personas = $this->teamModel->audiencePersonas()->get();
        $this->hasPersonas = $personas->isNotEmpty();
        $this->personas = $personas->map(fn ($p) => [
            'id' => $p->id,
            'label' => $p->label,
            'description' => $p->description,
            'pain_points' => $p->pain_points,
            'push' => $p->push,
            'pull' => $p->pull,
            'anxiety' => $p->anxiety,
            'role' => $p->role,
            'sort_order' => $p->sort_order,
        ])->toArray();

        $voice = $this->teamModel->voiceProfile;
        $this->hasVoiceProfile = $voice !== null;

        if ($voice) {
            $this->voiceProfile = $voice->only([
                'voice_analysis', 'content_types', 'should_avoid',
                'should_use', 'style_inspiration', 'preferred_length',
            ]);
            $this->voiceForm = [
                'voice_analysis' => $voice->voice_analysis ?? '',
                'content_types' => $voice->content_types ?? '',
                'should_avoid' => $voice->should_avoid ?? '',
                'should_use' => $voice->should_use ?? '',
                'style_inspiration' => $voice->style_inspiration ?? '',
                'preferred_length' => $voice->preferred_length ?? 1500,
            ];
        }
    }
}; ?>
```

**Do NOT add the Blade template yet** — that's Task 6. For now, add a minimal template so tests can run:

```blade

<section class="w-full">
    <flux:main container class="max-w-xl lg:max-w-3xl">
        <flux:heading size="xl">{{ __('Brand Intelligence') }}</flux:heading>
        <flux:subheading>{{ __('AI-generated insights about your brand, audience, and voice.') }}</flux:subheading>

        @if ($missingPrerequisites)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
                <flux:callout.heading>{{ __('Setup required') }}</flux:callout.heading>
                <flux:callout.text>
                    @foreach ($missingItems as $item)
                        {{ __('Add your') }} <a href="{{ route($item['route'], $item['route'] === 'teams.edit' ? ['team' => $teamModel] : []) }}" class="underline" wire:navigate>{{ $item['label'] }}</a>{{ $loop->last ? '.' : ', ' }}
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @endif
    </flux:main>
</section>
```

- [ ] **Step 2: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/BrandIntelligenceTest.php
```

Expected: All 20 tests PASS.

- [ ] **Step 3: Commit**

```bash
git add "resources/views/pages/teams/⚡brand-intelligence.blade.php"
git commit -m "feat: add brand intelligence Livewire component (PHP class + minimal template)"
```

---

## Task 6: Blade Template (Full UI)

**Files:**
- Modify: `resources/views/pages/teams/⚡brand-intelligence.blade.php`

**IMPORTANT:** Before writing this template, read the Flux UI docs for the components you will use:
- https://fluxui.dev/components/callout
- https://fluxui.dev/components/modal
- https://fluxui.dev/components/card
- https://fluxui.dev/components/separator
- https://fluxui.dev/components/button
- https://fluxui.dev/components/input
- https://fluxui.dev/components/textarea

Use the idiomatic Flux two-column settings layout: heading/subheading left (w-80), content right (flex-1), `flux:separator variant="subtle"` between sections.

- [ ] **Step 1: Replace the minimal template**

Replace everything after `}; ?>` in the file with the full Blade template:

```blade

<section class="w-full">
    <flux:main container class="max-w-xl lg:max-w-3xl">
        <flux:heading size="xl">{{ __('Brand Intelligence') }}</flux:heading>
        <flux:subheading>{{ __('AI-generated insights about your brand, audience, and voice. Review and edit as needed.') }}</flux:subheading>

        {{-- Prerequisite warning --}}
        @if ($missingPrerequisites)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
                <flux:callout.heading>{{ __('Setup required') }}</flux:callout.heading>
                <flux:callout.text>
                    @foreach ($missingItems as $item)
                        {{ __('Add your') }} <a href="{{ route($item['route'], $item['route'] === 'teams.edit' ? ['team' => $teamModel] : []) }}" class="underline" wire:navigate>{{ $item['label'] }}</a>{{ $loop->last ? '.' : ', ' }}
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @endif

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

        {{-- Section 1: Positioning --}}
        @if ($hasPositioning || $editingPositioning)
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Positioning') }}</flux:heading>
                    <flux:subheading>{{ __('Your brand\'s market position and value proposition.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-6">
                    @if ($editingPositioning)
                        <flux:textarea wire:model="positioningForm.value_proposition" label="Value Proposition" rows="2" />
                        <flux:textarea wire:model="positioningForm.target_market" label="Target Market" rows="2" />
                        <flux:textarea wire:model="positioningForm.differentiators" label="Key Differentiators" rows="2" />
                        <flux:textarea wire:model="positioningForm.core_problems" label="Core Problems Solved" rows="2" />
                        <flux:textarea wire:model="positioningForm.products_services" label="Products & Services" rows="2" />
                        <flux:textarea wire:model="positioningForm.primary_cta" label="Primary CTA" rows="1" />

                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="cancelEditingPositioning">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" wire:click="savePositioning">{{ __('Save') }}</flux:button>
                        </div>
                    @else
                        @foreach ([
                            'value_proposition' => 'Value Proposition',
                            'target_market' => 'Target Market',
                            'differentiators' => 'Key Differentiators',
                            'core_problems' => 'Core Problems Solved',
                            'products_services' => 'Products & Services',
                            'primary_cta' => 'Primary CTA',
                        ] as $field => $label)
                            @if (! empty($positioning[$field]))
                                <div>
                                    <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                                    <flux:text class="mt-1">{{ $positioning[$field] }}</flux:text>
                                </div>
                            @endif
                        @endforeach

                        @if ($this->permissions->canUpdateTeam)
                            <div class="flex justify-end gap-2">
                                <flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate') }}</flux:button>
                                <flux:button variant="subtle" size="sm" wire:click="startEditingPositioning">{{ __('Edit') }}</flux:button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        {{-- Section 2: Audience Personas --}}
        @if ($hasPersonas || $hasPositioning)
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Audience Personas') }}</flux:heading>
                    <flux:subheading>{{ __('Your target audience segments. Each content piece targets one persona.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-4">
                    @foreach ($personas as $persona)
                        <flux:card class="space-y-3">
                            <div class="flex items-start justify-between">
                                <div>
                                    <flux:heading>{{ $persona['label'] }}</flux:heading>
                                    @if ($persona['role'])
                                        <flux:text class="text-sm text-zinc-400">{{ $persona['role'] }}</flux:text>
                                    @endif
                                </div>
                                @if ($this->permissions->canUpdateTeam)
                                    <div class="flex gap-1">
                                        <flux:modal.trigger name="edit-persona-modal">
                                            <flux:button variant="ghost" size="xs" icon="pencil" wire:click="editPersona({{ $persona['id'] }})" />
                                        </flux:modal.trigger>
                                        <flux:modal.trigger :name="'delete-persona-'.$persona['id']">
                                            <flux:button variant="ghost" size="xs" icon="x-mark" />
                                        </flux:modal.trigger>
                                    </div>
                                @endif
                            </div>

                            @if ($persona['description'])
                                <flux:text class="text-sm">{{ $persona['description'] }}</flux:text>
                            @endif

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ([
                                    'pain_points' => 'Pain Points',
                                    'push' => 'Push',
                                    'pull' => 'Pull',
                                    'anxiety' => 'Anxiety',
                                ] as $field => $label)
                                    @if (! empty($persona[$field]))
                                        <div>
                                            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                                            <flux:text class="mt-1 text-sm">{{ $persona[$field] }}</flux:text>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </flux:card>

                        {{-- Delete persona confirmation modal --}}
                        @if ($this->permissions->canUpdateTeam)
                            <flux:modal :name="'delete-persona-'.$persona['id']" class="min-w-[22rem]">
                                <div class="space-y-6">
                                    <div>
                                        <flux:heading size="lg">{{ __('Delete persona?') }}</flux:heading>
                                        <flux:text class="mt-2">{{ __('":name" will be permanently deleted.', ['name' => $persona['label']]) }}</flux:text>
                                    </div>
                                    <div class="flex gap-2">
                                        <flux:spacer />
                                        <flux:modal.close>
                                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                        </flux:modal.close>
                                        <flux:button variant="danger" wire:click="deletePersona({{ $persona['id'] }})">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </flux:modal>
                        @endif
                    @endforeach

                    @if ($this->permissions->canUpdateTeam)
                        <div class="flex justify-end gap-2">
                            <flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate all') }}</flux:button>
                            <flux:modal.trigger name="edit-persona-modal">
                                <flux:button variant="subtle" size="sm" icon="plus" wire:click="resetPersonaForm">{{ __('Add persona') }}</flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Section 3: Voice & Tone --}}
        @if ($hasVoiceProfile || $editingVoiceProfile)
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6 pb-10">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Voice & Tone') }}</flux:heading>
                    <flux:subheading>{{ __('How your brand sounds in writing. Guides the AI content generation.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-6">
                    @if ($editingVoiceProfile)
                        <flux:textarea wire:model="voiceForm.voice_analysis" label="Voice Analysis" rows="3" />
                        <flux:textarea wire:model="voiceForm.content_types" label="Content Types" rows="2" />
                        <flux:textarea wire:model="voiceForm.should_avoid" label="Should Avoid" rows="2" />
                        <flux:textarea wire:model="voiceForm.should_use" label="Should Use" rows="2" />
                        <flux:textarea wire:model="voiceForm.style_inspiration" label="Style Inspiration" rows="2" />
                        <flux:input wire:model="voiceForm.preferred_length" label="Preferred Length (words)" type="number" min="100" max="10000" />

                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="cancelEditingVoiceProfile">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" wire:click="saveVoiceProfile">{{ __('Save') }}</flux:button>
                        </div>
                    @else
                        @foreach ([
                            'voice_analysis' => 'Voice Analysis',
                            'content_types' => 'Content Types',
                            'should_avoid' => 'Should Avoid',
                            'should_use' => 'Should Use',
                            'style_inspiration' => 'Style Inspiration',
                        ] as $field => $label)
                            @if (! empty($voiceProfile[$field]))
                                <div>
                                    <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                                    <flux:text class="mt-1">{{ $voiceProfile[$field] }}</flux:text>
                                </div>
                            @endif
                        @endforeach

                        @if (! empty($voiceProfile['preferred_length']))
                            <div>
                                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Target Length') }}</flux:text>
                                <flux:text class="mt-1">{{ number_format($voiceProfile['preferred_length']) }} {{ __('words') }}</flux:text>
                            </div>
                        @endif

                        @if ($this->permissions->canUpdateTeam)
                            <div class="flex justify-end gap-2">
                                <flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate') }}</flux:button>
                                <flux:button variant="subtle" size="sm" wire:click="startEditingVoiceProfile">{{ __('Edit') }}</flux:button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        {{-- Persona edit/add modal --}}
        @if ($this->permissions->canUpdateTeam)
            <flux:modal name="edit-persona-modal" class="w-full max-w-lg">
                <div class="space-y-6">
                    <flux:heading size="lg">{{ $editingPersonaId ? __('Edit Persona') : __('Add Persona') }}</flux:heading>

                    <flux:input wire:model="personaForm.label" label="Label" placeholder="The Overwhelmed Engineering Lead" required />
                    <flux:input wire:model="personaForm.role" label="Role" placeholder="Senior Engineer" />
                    <flux:textarea wire:model="personaForm.description" label="Description" rows="2" placeholder="Who they are..." />
                    <flux:textarea wire:model="personaForm.pain_points" label="Pain Points" rows="2" placeholder="What problems they face..." />
                    <flux:textarea wire:model="personaForm.push" label="Push" rows="2" placeholder="What drives them to change..." />
                    <flux:textarea wire:model="personaForm.pull" label="Pull" rows="2" placeholder="What attracts them to a solution..." />
                    <flux:textarea wire:model="personaForm.anxiety" label="Anxiety" rows="2" placeholder="What holds them back..." />

                    <div class="flex gap-2">
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" wire:click="savePersona">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </flux:main>
</section>
```

- [ ] **Step 2: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/BrandIntelligenceTest.php
```

Expected: All 20 tests PASS.

- [ ] **Step 3: Run full team test suite**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/
```

Expected: All tests pass (except pre-existing starter kit failures).

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/teams/⚡brand-intelligence.blade.php"
git commit -m "feat: add brand intelligence full Blade template with read/edit modes"
```

---

## Task 7: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test
```

Expected: All tests pass (except pre-existing starter kit failures).

- [ ] **Step 2: Verify in browser**

Open http://localhost, login, then:

1. Confirm "Brand Intelligence" link appears in sidebar below Brand Setup
2. Click it — verify the page loads
3. **Without prerequisites:** Remove API key from team settings — confirm warning callout appears with link
4. **With prerequisites, no data:** Confirm bootstrap CTA card appears with disabled "Generate" button
5. **Positioning:** Click "Edit" (or manually create via adding data) — verify form appears with 6 textareas. Save — verify read mode shows fields with labels. Edit again — verify fields populate.
6. **Personas:** Click "Add persona" — verify modal opens with 7 fields. Fill in and save — verify card appears. Click edit pencil — verify modal populates. Click delete X — verify confirmation modal. Confirm delete — verify card removed.
7. **Voice & Tone:** Click "Edit" — verify form with 5 textareas + 1 number input. Save — verify read mode. Check preferred length shows formatted number.
8. **Authorization:** As a Member role — confirm Edit/Delete/Add buttons are hidden, content is readable.
