# Brand Setup — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Brand Setup" page where team owners/admins provide URLs, descriptions, and hints about their brand — the raw inputs that AI agents will later use to build content strategy.

**Architecture:** Nine columns added to the `teams` table (3 JSON arrays for multi-URL fields, 6 scalar fields). A new inline Livewire page component at `/{current_team}/brand` handles the form. Sidebar gets a new nav item. No new models or policies — reuses existing Team model and `update` gate.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, Pest, PostgreSQL

**Working directory:** `marketminded-laravel/` — all paths relative to this. Run commands via `docker exec -w /var/www/html marketminded-laravel-laravel.test-1`.

---

## File Structure

### New Files

```
database/migrations/XXXX_add_brand_setup_to_teams_table.php   — Migration for 9 new columns
resources/views/pages/teams/⚡brand-setup.blade.php            — Inline Livewire page component + Blade template
tests/Feature/Teams/BrandSetupTest.php                         — Pest tests for brand setup
```

### Modified Files

```
app/Models/Team.php                                            — Add fillable, casts, defaults
resources/views/layouts/app/sidebar.blade.php                  — Add Brand Setup nav item
routes/web.php                                                 — Add brand.setup route
```

---

## Task 1: Migration

**Files:**
- Create: `database/migrations/XXXX_add_brand_setup_to_teams_table.php`

- [ ] **Step 1: Generate migration via artisan**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan make:migration add_brand_setup_to_teams_table
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
            $table->string('homepage_url')->nullable()->after('powerful_model');
            $table->string('blog_url')->nullable()->after('homepage_url');
            $table->text('brand_description')->nullable()->after('blog_url');
            $table->jsonb('product_urls')->default('[]')->after('brand_description');
            $table->jsonb('competitor_urls')->default('[]')->after('product_urls');
            $table->jsonb('style_reference_urls')->default('[]')->after('competitor_urls');
            $table->text('target_audience')->nullable()->after('style_reference_urls');
            $table->string('tone_keywords')->nullable()->after('target_audience');
            $table->string('content_language', 50)->default('English')->after('tone_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'homepage_url',
                'blog_url',
                'brand_description',
                'product_urls',
                'competitor_urls',
                'style_reference_urls',
                'target_audience',
                'tone_keywords',
                'content_language',
            ]);
        });
    }
};
```

- [ ] **Step 3: Run migration**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan migrate
```

Expected: Migration runs successfully, 9 columns added to teams table.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: add brand setup columns to teams table"
```

---

## Task 2: Team Model Update

**Files:**
- Modify: `app/Models/Team.php`

- [ ] **Step 1: Update the Fillable attribute**

In `app/Models/Team.php`, change line 16:

```php
#[Fillable(['name', 'slug', 'is_personal', 'openrouter_api_key', 'fast_model', 'powerful_model'])]
```

to:

```php
#[Fillable(['name', 'slug', 'is_personal', 'openrouter_api_key', 'fast_model', 'powerful_model', 'homepage_url', 'blog_url', 'brand_description', 'product_urls', 'competitor_urls', 'style_reference_urls', 'target_audience', 'tone_keywords', 'content_language'])]
```

- [ ] **Step 2: Add defaults for JSON and language columns**

In the `$attributes` array (line 28), add defaults for the new columns:

```php
    protected $attributes = [
        'fast_model' => 'deepseek/deepseek-v3.2:nitro',
        'powerful_model' => 'deepseek/deepseek-v3.2:nitro',
        'product_urls' => '[]',
        'competitor_urls' => '[]',
        'style_reference_urls' => '[]',
        'content_language' => 'English',
    ];
```

- [ ] **Step 3: Add casts for JSON columns**

In the `casts()` method, add the 3 JSON casts:

```php
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'openrouter_api_key' => 'encrypted',
            'product_urls' => 'array',
            'competitor_urls' => 'array',
            'style_reference_urls' => 'array',
        ];
    }
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/Team.php
git commit -m "feat: add brand setup fields to Team model (fillable, casts, defaults)"
```

---

## Task 3: Route

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Add the brand setup route**

In `routes/web.php`, inside the `Route::prefix('{current_team}')` group, add the brand setup route after the dashboard route:

```php
Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('brand', 'pages::teams.brand-setup')->name('brand.setup');
    });
```

- [ ] **Step 2: Commit**

```bash
git add routes/web.php
git commit -m "feat: add brand setup route"
```

---

## Task 4: Tests

**Files:**
- Create: `tests/Feature/Teams/BrandSetupTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Feature/Teams/BrandSetupTest.php`:

```php
<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('owner can save brand setup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->set('blogUrl', 'https://example.com/blog')
        ->set('brandDescription', 'We make widgets for developers.')
        ->set('productUrls', ['https://example.com/product', 'https://example.com/about'])
        ->set('competitorUrls', ['https://competitor.com'])
        ->set('styleReferenceUrls', ['https://blog.example.com/great-post'])
        ->set('targetAudience', 'Senior developers at SaaS companies')
        ->set('toneKeywords', 'Professional, approachable')
        ->set('contentLanguage', 'English')
        ->call('saveBrandSetup')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->blog_url)->toBe('https://example.com/blog');
    expect($team->brand_description)->toBe('We make widgets for developers.');
    expect($team->product_urls)->toBe(['https://example.com/product', 'https://example.com/about']);
    expect($team->competitor_urls)->toBe(['https://competitor.com']);
    expect($team->style_reference_urls)->toBe(['https://blog.example.com/great-post']);
    expect($team->target_audience)->toBe('Senior developers at SaaS companies');
    expect($team->tone_keywords)->toBe('Professional, approachable');
    expect($team->content_language)->toBe('English');
});

test('admin can save brand setup', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->call('saveBrandSetup')
        ->assertHasNoErrors();

    expect($team->fresh()->homepage_url)->toBe('https://example.com');
});

test('member cannot save brand setup', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->call('saveBrandSetup')
        ->assertForbidden();
});

test('homepage url is required', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', '')
        ->call('saveBrandSetup')
        ->assertHasErrors(['homepageUrl']);
});

test('urls must be valid', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'not-a-url')
        ->set('blogUrl', 'also-not-a-url')
        ->set('productUrls', ['bad-url'])
        ->call('saveBrandSetup')
        ->assertHasErrors(['homepageUrl', 'blogUrl', 'productUrls.0']);
});

test('brand setup has correct defaults', function () {
    $team = Team::factory()->create();

    expect($team->homepage_url)->toBeNull();
    expect($team->blog_url)->toBeNull();
    expect($team->brand_description)->toBeNull();
    expect($team->product_urls)->toBe([]);
    expect($team->competitor_urls)->toBe([]);
    expect($team->style_reference_urls)->toBe([]);
    expect($team->target_audience)->toBeNull();
    expect($team->tone_keywords)->toBeNull();
    expect($team->content_language)->toBe('English');
});

test('optional fields can be cleared', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'blog_url' => 'https://example.com/blog',
        'brand_description' => 'Old description',
        'target_audience' => 'Old audience',
        'tone_keywords' => 'Old keywords',
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('blogUrl', '')
        ->set('brandDescription', '')
        ->set('targetAudience', '')
        ->set('toneKeywords', '')
        ->call('saveBrandSetup')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->blog_url)->toBeNull();
    expect($team->brand_description)->toBeNull();
    expect($team->target_audience)->toBeNull();
    expect($team->tone_keywords)->toBeNull();
});

test('url arrays are capped at 20 items', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    $urls = array_fill(0, 21, 'https://example.com');

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->set('productUrls', $urls)
        ->call('saveBrandSetup')
        ->assertHasErrors(['productUrls']);
});

test('mount populates form from existing team data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'blog_url' => 'https://example.com/blog',
        'brand_description' => 'A great company',
        'product_urls' => ['https://example.com/product'],
        'competitor_urls' => ['https://competitor.com'],
        'style_reference_urls' => ['https://style.example.com'],
        'target_audience' => 'Developers',
        'tone_keywords' => 'Friendly',
        'content_language' => 'Spanish',
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->assertSet('homepageUrl', 'https://example.com')
        ->assertSet('blogUrl', 'https://example.com/blog')
        ->assertSet('brandDescription', 'A great company')
        ->assertSet('productUrls', ['https://example.com/product'])
        ->assertSet('competitorUrls', ['https://competitor.com'])
        ->assertSet('styleReferenceUrls', ['https://style.example.com'])
        ->assertSet('targetAudience', 'Developers')
        ->assertSet('toneKeywords', 'Friendly')
        ->assertSet('contentLanguage', 'Spanish');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/BrandSetupTest.php
```

Expected: FAIL — the component `pages::teams.brand-setup` doesn't exist yet.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Teams/BrandSetupTest.php
git commit -m "test: add brand setup tests (red)"
```

---

## Task 5: Livewire Component + View

**Files:**
- Create: `resources/views/pages/teams/⚡brand-setup.blade.php`

- [ ] **Step 1: Create the inline Livewire component**

Create `resources/views/pages/teams/⚡brand-setup.blade.php` with this content:

```php
<?php

use App\Models\Team;
use App\Support\TeamPermissions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $homepageUrl = '';

    public string $blogUrl = '';

    public string $brandDescription = '';

    public array $productUrls = [];

    public array $competitorUrls = [];

    public array $styleReferenceUrls = [];

    public string $targetAudience = '';

    public string $toneKeywords = '';

    public string $contentLanguage = 'English';

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->homepageUrl = $team->homepage_url ?? '';
        $this->blogUrl = $team->blog_url ?? '';
        $this->brandDescription = $team->brand_description ?? '';
        $this->productUrls = $team->product_urls ?? [];
        $this->competitorUrls = $team->competitor_urls ?? [];
        $this->styleReferenceUrls = $team->style_reference_urls ?? [];
        $this->targetAudience = $team->target_audience ?? '';
        $this->toneKeywords = $team->tone_keywords ?? '';
        $this->contentLanguage = $team->content_language ?? 'English';
    }

    public function saveBrandSetup(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'homepageUrl' => ['required', 'url', 'max:255'],
            'blogUrl' => ['nullable', 'url', 'max:255'],
            'brandDescription' => ['nullable', 'string', 'max:5000'],
            'productUrls' => ['nullable', 'array', 'max:20'],
            'productUrls.*' => ['required', 'url', 'max:255'],
            'competitorUrls' => ['nullable', 'array', 'max:20'],
            'competitorUrls.*' => ['required', 'url', 'max:255'],
            'styleReferenceUrls' => ['nullable', 'array', 'max:20'],
            'styleReferenceUrls.*' => ['required', 'url', 'max:255'],
            'targetAudience' => ['nullable', 'string', 'max:5000'],
            'toneKeywords' => ['nullable', 'string', 'max:255'],
            'contentLanguage' => ['nullable', 'string', 'max:50'],
        ]);

        $this->teamModel->update([
            'homepage_url' => $validated['homepageUrl'],
            'blog_url' => $validated['blogUrl'] ?: null,
            'brand_description' => $validated['brandDescription'] ?: null,
            'product_urls' => $validated['productUrls'] ?? [],
            'competitor_urls' => $validated['competitorUrls'] ?? [],
            'style_reference_urls' => $validated['styleReferenceUrls'] ?? [],
            'target_audience' => $validated['targetAudience'] ?: null,
            'tone_keywords' => $validated['toneKeywords'] ?: null,
            'content_language' => $validated['contentLanguage'] ?: 'English',
        ]);

        Flux::toast(variant: 'success', text: __('Brand setup saved.'));
    }

    public function addProductUrl(): void
    {
        $this->productUrls[] = '';
    }

    public function removeProductUrl(int $index): void
    {
        unset($this->productUrls[$index]);
        $this->productUrls = array_values($this->productUrls);
    }

    public function addCompetitorUrl(): void
    {
        $this->competitorUrls[] = '';
    }

    public function removeCompetitorUrl(int $index): void
    {
        unset($this->competitorUrls[$index]);
        $this->competitorUrls = array_values($this->competitorUrls);
    }

    public function addStyleReferenceUrl(): void
    {
        $this->styleReferenceUrls[] = '';
    }

    public function removeStyleReferenceUrl(int $index): void
    {
        unset($this->styleReferenceUrls[$index]);
        $this->styleReferenceUrls = array_values($this->styleReferenceUrls);
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        return $this->view()->title(__('Brand Setup'));
    }
}; ?>

<x-layouts::app>
    <div class="mx-auto w-full max-w-2xl space-y-10 py-6">
        <div>
            <flux:heading size="xl">{{ __('Brand Setup') }}</flux:heading>
            <flux:subheading>{{ __('Tell us about your brand so our AI agents can build your content strategy.') }}</flux:subheading>
        </div>

        <form wire:submit="saveBrandSetup" class="space-y-10">
            {{-- Section 1: Company --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Company') }}</flux:heading>
                    <flux:subheading>{{ __('Your company\'s online presence. The homepage URL is the only required field — everything else helps the AI do a better job.') }}</flux:subheading>
                </div>

                <flux:input
                    wire:model="homepageUrl"
                    :label="__('Homepage URL')"
                    :description="__('Your main website. We\'ll crawl this to understand your brand.')"
                    type="url"
                    placeholder="https://yourcompany.com"
                    required
                />

                <flux:input
                    wire:model="blogUrl"
                    :label="__('Blog URL')"
                    :description="__('Your blog\'s index page. Helps us understand your existing content and avoid repetition.')"
                    type="url"
                    placeholder="https://yourcompany.com/blog"
                />

                <flux:textarea
                    wire:model="brandDescription"
                    :label="__('Brand Description')"
                    :description="__('A brief description of what your company does, who it serves, and what makes it different. 2-3 sentences is plenty.')"
                    placeholder="{{ __('We make project management simple for remote teams...') }}"
                    rows="3"
                />
            </div>

            {{-- Section 2: Product & Brand Pages --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Product & Brand Pages') }}</flux:heading>
                    <flux:subheading>{{ __('Links to your product pages, about page, case studies, or documentation. These help the AI understand your offerings in depth.') }}</flux:subheading>
                </div>

                <div class="space-y-3">
                    @foreach ($productUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="productUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://yourcompany.com/product"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeProductUrl({{ $index }})"
                            />
                        </div>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addProductUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 3: Competitors --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Competitors') }}</flux:heading>
                    <flux:subheading>{{ __('Competitor websites. Helps the AI differentiate your positioning and find unique angles for your content.') }}</flux:subheading>
                </div>

                <div class="space-y-3">
                    @foreach ($competitorUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="competitorUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://competitor.com"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeCompetitorUrl({{ $index }})"
                            />
                        </div>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addCompetitorUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 4: Style References --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Style References') }}</flux:heading>
                    <flux:subheading>{{ __('Articles or blogs whose writing style you admire — including your own posts if you already have an established style. These guide the AI\'s tone and writing approach and don\'t need to be in your industry.') }}</flux:subheading>
                </div>

                <div class="space-y-3">
                    @foreach ($styleReferenceUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="styleReferenceUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://example.com/great-article"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeStyleReferenceUrl({{ $index }})"
                            />
                        </div>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addStyleReferenceUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 5: Additional Context --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Additional Context') }}</flux:heading>
                    <flux:subheading>{{ __('Optional hints that help the AI understand your brand better.') }}</flux:subheading>
                </div>

                <flux:textarea
                    wire:model="targetAudience"
                    :label="__('Target Audience')"
                    :description="__('Who are you writing for? e.g., \"CTOs at mid-size SaaS companies\" or \"first-time homebuyers in their 30s\"')"
                    placeholder="{{ __('CTOs and engineering leads at B2B SaaS companies...') }}"
                    rows="2"
                />

                <flux:input
                    wire:model="toneKeywords"
                    :label="__('Tone Keywords')"
                    :description="__('Words that describe how your brand should sound. e.g., \"professional but approachable\", \"technical but not jargon-heavy\"')"
                    placeholder="{{ __('Professional, approachable, concise') }}"
                />

                <flux:input
                    wire:model="contentLanguage"
                    :label="__('Content Language')"
                    :description="__('The language your content should be written in.')"
                    placeholder="English"
                />
            </div>

            {{-- Save --}}
            @if ($this->permissions->canUpdateTeam)
                <flux:button variant="primary" type="submit">
                    {{ __('Save brand setup') }}
                </flux:button>
            @endif
        </form>
    </div>
</x-layouts::app>
```

- [ ] **Step 2: Run tests**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/BrandSetupTest.php
```

Expected: All 9 tests PASS.

- [ ] **Step 3: Run full team test suite to check for regressions**

```bash
docker exec -w /var/www/html marketminded-laravel-laravel.test-1 php artisan test tests/Feature/Teams/
```

Expected: All existing team tests + 9 new brand setup tests PASS. (Note: 6 pre-existing starter kit tests may fail with unique constraint violations — these are unrelated.)

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/teams/⚡brand-setup.blade.php"
git commit -m "feat: add brand setup page with Livewire component"
```

---

## Task 6: Sidebar Navigation

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Add Brand Setup nav item**

In `resources/views/layouts/app/sidebar.blade.php`, find the sidebar nav group (around line 16):

```blade
            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>
```

Replace it with:

```blade
            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="building-storefront" :href="route('brand.setup')" :current="request()->routeIs('brand.setup')" wire:navigate>
                        {{ __('Brand Setup') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/layouts/app/sidebar.blade.php
git commit -m "feat: add Brand Setup link to sidebar navigation"
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

1. Confirm "Brand Setup" link appears in sidebar below Dashboard
2. Click it — verify the page loads with all 5 sections
3. Enter a homepage URL and save — confirm toast appears
4. Reload page — confirm the value persists
5. Add multiple product URLs using the "Add URL" button, save and reload — confirm they persist
6. Remove a URL, save and reload — confirm it's gone
7. Fill in all optional fields, save and reload — confirm everything persists
8. Clear optional fields, save and reload — confirm they clear properly
9. As a Member role user — confirm the save button is hidden
