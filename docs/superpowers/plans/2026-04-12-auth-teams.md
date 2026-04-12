# Auth & Teams Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build authentication, team membership with roles, and URL-prefix tenancy routing for the MarketMinded Laravel app.

**Architecture:** Livewire full-page components for all auth and team pages. Flux Pro UI components for forms, tables, and navigation. Single-database tenancy scoped via `team_id` on projects, enforced by middleware and Eloquent global scopes. Laravel Gates/Policies for role-based authorization.

**Tech Stack:** Laravel 13, Livewire 4, Flux Pro, PostgreSQL 18, Sail

**Working directory:** All paths relative to `marketminded-laravel/`

---

## File Structure

### New Files

```
app/Models/Team.php                              — Team model with members relationship
app/Models/Membership.php                        — Pivot model for team_user with role accessor
app/Enums/TeamRole.php                           — Enum: owner, admin, editor, viewer

app/Http/Middleware/ResolveTeam.php               — Resolves {team} from URL, checks membership
app/Policies/TeamPolicy.php                       — Authorization gates for team actions
app/Policies/ProjectPolicy.php                    — Authorization gates for project actions (stub)

app/Livewire/Auth/Register.php                    — Registration component
app/Livewire/Auth/Login.php                       — Login component
app/Livewire/Auth/ForgotPassword.php              — Request password reset
app/Livewire/Auth/ResetPassword.php               — Reset password form

app/Livewire/Teams/CreateTeam.php                 — Create team form
app/Livewire/Teams/TeamSettings.php               — Team settings + member management
app/Livewire/Dashboard.php                        — Team list / no-teams state

resources/views/components/layouts/guest.blade.php — Auth page layout (centered card)
resources/views/livewire/auth/register.blade.php
resources/views/livewire/auth/login.blade.php
resources/views/livewire/auth/forgot-password.blade.php
resources/views/livewire/auth/reset-password.blade.php
resources/views/livewire/teams/create-team.blade.php
resources/views/livewire/teams/team-settings.blade.php
resources/views/livewire/dashboard.blade.php

database/migrations/XXXX_create_teams_table.php
database/migrations/XXXX_create_team_user_table.php

tests/Feature/Auth/RegistrationTest.php
tests/Feature/Auth/LoginTest.php
tests/Feature/Auth/PasswordResetTest.php
tests/Feature/Teams/CreateTeamTest.php
tests/Feature/Teams/TeamMembershipTest.php
tests/Feature/Teams/TeamMiddlewareTest.php
```

### Modified Files

```
app/Models/User.php                               — Add teams() relationship
bootstrap/app.php                                  — Register ResolveTeam middleware alias
routes/web.php                                     — All route definitions
app/Providers/AppServiceProvider.php               — Register policies
```

---

## Task 1: Team Role Enum

**Files:**
- Create: `app/Enums/TeamRole.php`

- [ ] **Step 1: Create the enum**

```php
<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    public function canManageSettings(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    public function canEditProjects(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor]);
    }

    public function canRunPipelines(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Viewer => 'Viewer',
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Enums/TeamRole.php
git commit -m "feat: add TeamRole enum with permission helpers"
```

---

## Task 2: Teams Migration & Models

**Files:**
- Create: `database/migrations/2026_04_12_100000_create_teams_table.php`
- Create: `database/migrations/2026_04_12_100001_create_team_user_table.php`
- Create: `app/Models/Team.php`
- Create: `app/Models/Membership.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create teams migration**

```bash
cd marketminded-laravel && php artisan make:migration create_teams_table
```

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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
```

- [ ] **Step 2: Create team_user pivot migration**

```bash
php artisan make:migration create_team_user_table
```

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
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
```

- [ ] **Step 3: Create Team model**

```php
<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    protected $fillable = ['name', 'owner_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function addMember(User $user, TeamRole $role = TeamRole::Viewer): void
    {
        $this->members()->attach($user, ['role' => $role->value]);
    }

    public function removeMember(User $user): void
    {
        $this->members()->detach($user);
    }

    public function memberRole(User $user): ?TeamRole
    {
        $membership = $this->members()->where('user_id', $user->id)->first();

        return $membership ? TeamRole::from($membership->pivot->role) : null;
    }
}
```

- [ ] **Step 4: Create Membership pivot model**

```php
<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    protected $table = 'team_user';

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }
}
```

- [ ] **Step 5: Add teams relationship to User model**

In `app/Models/User.php`, add the import and relationship:

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }
}
```

- [ ] **Step 6: Run migrations via Sail**

```bash
./vendor/bin/sail artisan migrate
```

Expected: All migrations run successfully, including teams and team_user tables.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/ app/Models/Team.php app/Models/Membership.php app/Models/User.php
git commit -m "feat: add teams and team_user tables with Eloquent models"
```

---

## Task 3: Team Policy & Authorization

**Files:**
- Create: `app/Policies/TeamPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create TeamPolicy**

```php
<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->memberRole($user) !== null;
    }

    public function update(User $user, Team $team): bool
    {
        $role = $team->memberRole($user);

        return $role !== null && $role->canManageSettings();
    }

    public function delete(User $user, Team $team): bool
    {
        $role = $team->memberRole($user);

        return $role === TeamRole::Owner;
    }

    public function manageMembers(User $user, Team $team): bool
    {
        $role = $team->memberRole($user);

        return $role !== null && $role->canManageMembers();
    }
}
```

- [ ] **Step 2: Register the policy in AppServiceProvider**

Replace `app/Providers/AppServiceProvider.php` with:

```php
<?php

namespace App\Providers;

use App\Models\Team;
use App\Policies\TeamPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Team::class, TeamPolicy::class);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Policies/TeamPolicy.php app/Providers/AppServiceProvider.php
git commit -m "feat: add TeamPolicy with role-based authorization"
```

---

## Task 4: Team Resolution Middleware

**Files:**
- Create: `app/Http/Middleware/ResolveTeam.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Create ResolveTeam middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('team');

        if (! $team instanceof Team) {
            abort(404);
        }

        $user = $request->user();

        if (! $user || $team->memberRole($user) === null) {
            abort(403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Register middleware alias in bootstrap/app.php**

Replace `bootstrap/app.php` with:

```php
<?php

use App\Http\Middleware\ResolveTeam;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'team' => ResolveTeam::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/ResolveTeam.php bootstrap/app.php
git commit -m "feat: add ResolveTeam middleware for URL-prefix tenancy"
```

---

## Task 5: Guest Layout

**Files:**
- Create: `resources/views/components/layouts/guest.blade.php`

- [ ] **Step 1: Create the guest layout**

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800 flex items-center justify-center">
    <div class="w-full max-w-md px-6">
        <div class="mb-8 text-center">
            <flux:heading size="xl">{{ config('app.name') }}</flux:heading>
        </div>

        {{ $slot }}
    </div>

    @fluxScripts
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/layouts/guest.blade.php
git commit -m "feat: add guest layout for auth pages"
```

---

## Task 6: Registration

**Files:**
- Create: `app/Livewire/Auth/Register.php`
- Create: `resources/views/livewire/auth/register.blade.php`
- Create: `tests/Feature/Auth/RegistrationTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');
    }

    public function test_registration_requires_valid_data(): void
    {
        $response = $this->post('/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

        $this->assertGuest();
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_from_register(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/register');

        $response->assertRedirect('/');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail artisan test tests/Feature/Auth/RegistrationTest.php
```

Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Create Register Livewire component**

```php
<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Register extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|email|max:255|unique:users')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $validated = $this->validate();

        $user = User::create($validated);

        event(new Registered($user));

        Auth::login($user);

        $this->redirect('/', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
```

- [ ] **Step 4: Create register view**

```blade
<flux:card class="space-y-6">
    <div>
        <flux:heading size="lg">Create an account</flux:heading>
        <flux:text class="mt-2">Get started with MarketMinded.</flux:text>
    </div>

    <form wire:submit="register" class="space-y-6">
        <flux:input wire:model="name" label="Name" type="text" autofocus />
        <flux:input wire:model="email" label="Email" type="email" />
        <flux:input wire:model="password" label="Password" type="password" viewable />
        <flux:input wire:model="password_confirmation" label="Confirm Password" type="password" viewable />

        <flux:button type="submit" variant="primary" class="w-full">
            Create account
        </flux:button>
    </form>

    <flux:text class="text-center">
        Already have an account? <flux:button href="/login" variant="ghost" size="sm">Sign in</flux:button>
    </flux:text>
</flux:card>
```

- [ ] **Step 5: Add routes**

Replace `routes/web.php` with:

```php
<?php

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Dashboard;
use App\Livewire\Teams\CreateTeam;
use App\Livewire\Teams\TeamSettings;
use Illuminate\Support\Facades\Route;

// Guest routes
Route::middleware('guest')->group(function () {
    Route::get('/register', Register::class)->name('register');
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', function () {
        auth()->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    })->name('logout');

    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/teams/create', CreateTeam::class)->name('teams.create');

    Route::middleware('team')->prefix('/teams/{team}')->group(function () {
        Route::get('/settings', TeamSettings::class)->name('teams.settings');
    });
});
```

Note: This references components that don't exist yet. The registration tests should pass. Login/ForgotPassword/ResetPassword/Dashboard/CreateTeam/TeamSettings will be created in later tasks. The routes file is complete now so we don't have to keep editing it.

- [ ] **Step 6: Run tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Auth/RegistrationTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Auth/Register.php resources/views/livewire/auth/register.blade.php tests/Feature/Auth/RegistrationTest.php routes/web.php
git commit -m "feat: add registration with Livewire + Flux Pro"
```

---

## Task 7: Login

**Files:**
- Create: `app/Livewire/Auth/Login.php`
- Create: `resources/views/livewire/auth/login.blade.php`
- Create: `tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_login(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');
    }

    public function test_users_cannot_login_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_authenticated_user_is_redirected_from_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail artisan test tests/Feature/Auth/LoginTest.php
```

Expected: FAIL — Login component doesn't exist.

- [ ] **Step 3: Create Login Livewire component**

```php
<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->addError('email', __('auth.failed'));

            return;
        }

        session()->regenerate();

        $this->redirect('/', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
```

- [ ] **Step 4: Create login view**

```blade
<flux:card class="space-y-6">
    <div>
        <flux:heading size="lg">Sign in</flux:heading>
        <flux:text class="mt-2">Welcome back to MarketMinded.</flux:text>
    </div>

    <form wire:submit="login" class="space-y-6">
        <flux:input wire:model="email" label="Email" type="email" autofocus />
        <flux:input wire:model="password" label="Password" type="password" viewable />

        <div class="flex items-center justify-between">
            <flux:checkbox wire:model="remember" label="Remember me" />
            <flux:button href="/forgot-password" variant="ghost" size="sm">Forgot password?</flux:button>
        </div>

        <flux:button type="submit" variant="primary" class="w-full">
            Sign in
        </flux:button>
    </form>

    <flux:text class="text-center">
        Don't have an account? <flux:button href="/register" variant="ghost" size="sm">Create one</flux:button>
    </flux:text>
</flux:card>
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Auth/LoginTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Auth/Login.php resources/views/livewire/auth/login.blade.php tests/Feature/Auth/LoginTest.php
git commit -m "feat: add login with Livewire + Flux Pro"
```

---

## Task 8: Password Reset

**Files:**
- Create: `app/Livewire/Auth/ForgotPassword.php`
- Create: `resources/views/livewire/auth/forgot-password.blade.php`
- Create: `app/Livewire/Auth/ResetPassword.php`
- Create: `resources/views/livewire/auth/reset-password.blade.php`
- Create: `tests/Feature/Auth/PasswordResetTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $response->assertRedirect('/login');

            return true;
        });
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail artisan test tests/Feature/Auth/PasswordResetTest.php
```

Expected: FAIL — components don't exist.

- [ ] **Step 3: Create ForgotPassword component**

```php
<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public string $status = '';

    public function sendResetLink(): void
    {
        $this->validate();

        $status = Password::sendResetLink(['email' => $this->email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->status = __($status);
            $this->email = '';
        } else {
            $this->addError('email', __($status));
        }
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
```

- [ ] **Step 4: Create forgot-password view**

```blade
<flux:card class="space-y-6">
    <div>
        <flux:heading size="lg">Forgot password</flux:heading>
        <flux:text class="mt-2">Enter your email and we'll send a reset link.</flux:text>
    </div>

    @if ($status)
        <flux:badge color="green" class="w-full justify-center">{{ $status }}</flux:badge>
    @endif

    <form wire:submit="sendResetLink" class="space-y-6">
        <flux:input wire:model="email" label="Email" type="email" autofocus />

        <flux:button type="submit" variant="primary" class="w-full">
            Send reset link
        </flux:button>
    </form>

    <flux:text class="text-center">
        <flux:button href="/login" variant="ghost" size="sm">Back to sign in</flux:button>
    </flux:text>
</flux:card>
```

- [ ] **Step 5: Create ResetPassword component**

```php
<?php

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->validate();

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', __($status));

            $this->redirect('/login', navigate: true);
        } else {
            $this->addError('email', __($status));
        }
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
```

- [ ] **Step 6: Create reset-password view**

```blade
<flux:card class="space-y-6">
    <div>
        <flux:heading size="lg">Reset password</flux:heading>
        <flux:text class="mt-2">Choose a new password for your account.</flux:text>
    </div>

    <form wire:submit="resetPassword" class="space-y-6">
        <flux:input wire:model="email" label="Email" type="email" />
        <flux:input wire:model="password" label="New Password" type="password" viewable />
        <flux:input wire:model="password_confirmation" label="Confirm Password" type="password" viewable />

        <flux:button type="submit" variant="primary" class="w-full">
            Reset password
        </flux:button>
    </form>
</flux:card>
```

- [ ] **Step 7: Run tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Auth/PasswordResetTest.php
```

Expected: All 4 tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Auth/ForgotPassword.php app/Livewire/Auth/ResetPassword.php resources/views/livewire/auth/forgot-password.blade.php resources/views/livewire/auth/reset-password.blade.php tests/Feature/Auth/PasswordResetTest.php
git commit -m "feat: add password reset flow with Livewire + Flux Pro"
```

---

## Task 9: Dashboard (No-Teams State & Team List)

**Files:**
- Create: `app/Livewire/Dashboard.php`
- Create: `resources/views/livewire/dashboard.blade.php`

- [ ] **Step 1: Create Dashboard component**

```php
<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $teams = Auth::user()->teams()->latest()->get();

        return view('livewire.dashboard', [
            'teams' => $teams,
        ]);
    }
}
```

- [ ] **Step 2: Create dashboard view**

```blade
<x-layouts.app title="Dashboard">
    <div class="max-w-2xl mx-auto py-12 px-6">
        <div class="flex items-center justify-between mb-8">
            <flux:heading size="xl">Your Teams</flux:heading>
            <flux:button href="/teams/create" variant="primary" icon="plus">
                Create team
            </flux:button>
        </div>

        @if ($teams->isEmpty())
            <flux:card class="text-center py-12">
                <flux:heading size="lg">No teams yet</flux:heading>
                <flux:text class="mt-2">Create a team to get started, or wait to be added to one.</flux:text>
                <div class="mt-6">
                    <flux:button href="/teams/create" variant="primary">Create your first team</flux:button>
                </div>
            </flux:card>
        @else
            <div class="space-y-3">
                @foreach ($teams as $team)
                    <flux:card>
                        <div class="flex items-center justify-between">
                            <div>
                                <flux:heading size="lg">{{ $team->name }}</flux:heading>
                                <flux:badge size="sm" class="mt-1">{{ $team->pivot->role }}</flux:badge>
                            </div>
                            <flux:button href="/teams/{{ $team->id }}/settings" variant="ghost" icon="cog-6-tooth" />
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif

        <div class="mt-8 text-center">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm">Sign out</flux:button>
            </form>
        </div>
    </div>
</x-layouts.app>
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/Dashboard.php resources/views/livewire/dashboard.blade.php
git commit -m "feat: add dashboard with team list and empty state"
```

---

## Task 10: Create Team

**Files:**
- Create: `app/Livewire/Teams/CreateTeam.php`
- Create: `resources/views/livewire/teams/create-team.blade.php`
- Create: `tests/Feature/Teams/CreateTeamTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_team_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/teams/create');

        $response->assertStatus(200);
    }

    public function test_team_can_be_created(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/teams/create', [
            'name' => 'My Team',
        ]);

        $this->assertDatabaseHas('teams', [
            'name' => 'My Team',
            'owner_id' => $user->id,
        ]);

        $this->assertDatabaseHas('team_user', [
            'user_id' => $user->id,
            'role' => TeamRole::Owner->value,
        ]);
    }

    public function test_team_requires_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/teams/create', [
            'name' => '',
        ]);

        $this->assertDatabaseMissing('teams', [
            'owner_id' => $user->id,
        ]);
    }

    public function test_guest_cannot_create_team(): void
    {
        $response = $this->get('/teams/create');

        $response->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail artisan test tests/Feature/Teams/CreateTeamTest.php
```

Expected: FAIL — component doesn't exist.

- [ ] **Step 3: Create CreateTeam component**

```php
<?php

namespace App\Livewire\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateTeam extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    public function create(): void
    {
        $this->validate();

        $team = Team::create([
            'name' => $this->name,
            'owner_id' => Auth::id(),
        ]);

        $team->addMember(Auth::user(), TeamRole::Owner);

        $this->redirect("/teams/{$team->id}/settings", navigate: true);
    }

    public function render()
    {
        return view('livewire.teams.create-team');
    }
}
```

- [ ] **Step 4: Create create-team view**

```blade
<x-layouts.app title="Create Team">
    <div class="max-w-md mx-auto py-12 px-6">
        <flux:heading size="xl" class="mb-8">Create a team</flux:heading>

        <flux:card class="space-y-6">
            <form wire:submit="create" class="space-y-6">
                <flux:input wire:model="name" label="Team name" placeholder="e.g. Acme Corp" autofocus />

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary">Create team</flux:button>
                    <flux:button href="/" variant="ghost">Cancel</flux:button>
                </div>
            </form>
        </flux:card>
    </div>
</x-layouts.app>
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Teams/CreateTeamTest.php
```

Expected: All 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Teams/CreateTeam.php resources/views/livewire/teams/create-team.blade.php tests/Feature/Teams/CreateTeamTest.php
git commit -m "feat: add create team with owner membership"
```

---

## Task 11: Team Settings & Member Management

**Files:**
- Create: `app/Livewire/Teams/TeamSettings.php`
- Create: `resources/views/livewire/teams/team-settings.blade.php`
- Create: `tests/Feature/Teams/TeamMembershipTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Livewire\Teams\TeamSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function createTeamWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Test Team', 'owner_id' => $owner->id]);
        $team->addMember($owner, TeamRole::Owner);

        return [$owner, $team];
    }

    public function test_owner_can_view_team_settings(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();

        $response = $this->actingAs($owner)->get("/teams/{$team->id}/settings");

        $response->assertStatus(200);
    }

    public function test_owner_can_add_member(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();
        $newUser = User::factory()->create();

        Livewire::actingAs($owner)
            ->test(TeamSettings::class, ['team' => $team])
            ->set('memberEmail', $newUser->email)
            ->set('memberRole', TeamRole::Editor->value)
            ->call('addMember');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $newUser->id,
            'role' => TeamRole::Editor->value,
        ]);
    }

    public function test_owner_can_remove_member(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member, TeamRole::Editor);

        Livewire::actingAs($owner)
            ->test(TeamSettings::class, ['team' => $team])
            ->call('removeMember', $member->id);

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_owner_cannot_be_removed(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();

        Livewire::actingAs($owner)
            ->test(TeamSettings::class, ['team' => $team])
            ->call('removeMember', $owner->id);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_editor_cannot_manage_members(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();
        $editor = User::factory()->create();
        $team->addMember($editor, TeamRole::Editor);
        $newUser = User::factory()->create();

        Livewire::actingAs($editor)
            ->test(TeamSettings::class, ['team' => $team])
            ->set('memberEmail', $newUser->email)
            ->set('memberRole', TeamRole::Viewer->value)
            ->call('addMember')
            ->assertForbidden();

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $newUser->id,
        ]);
    }

    public function test_non_member_cannot_access_team(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->get("/teams/{$team->id}/settings");

        $response->assertStatus(403);
    }

    public function test_owner_can_change_member_role(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member, TeamRole::Viewer);

        Livewire::actingAs($owner)
            ->test(TeamSettings::class, ['team' => $team])
            ->call('changeRole', $member->id, TeamRole::Admin->value);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => TeamRole::Admin->value,
        ]);
    }

    public function test_adding_nonexistent_email_fails(): void
    {
        [$owner, $team] = $this->createTeamWithOwner();

        Livewire::actingAs($owner)
            ->test(TeamSettings::class, ['team' => $team])
            ->set('memberEmail', 'nobody@example.com')
            ->set('memberRole', TeamRole::Editor->value)
            ->call('addMember')
            ->assertHasErrors('memberEmail');

        $this->assertDatabaseCount('team_user', 1); // only owner
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/sail artisan test tests/Feature/Teams/TeamMembershipTest.php
```

Expected: FAIL — component doesn't exist.

- [ ] **Step 3: Create TeamSettings component**

```php
<?php

namespace App\Livewire\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Authorization\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TeamSettings extends Component
{
    public Team $team;

    #[Validate('required|string|email')]
    public string $memberEmail = '';

    public string $memberRole = 'viewer';

    public function mount(Team $team): void
    {
        $this->team = $team;
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->team);
        $this->validate();

        $user = User::where('email', $this->memberEmail)->first();

        if (! $user) {
            $this->addError('memberEmail', 'No user found with that email.');

            return;
        }

        if ($this->team->members()->where('user_id', $user->id)->exists()) {
            $this->addError('memberEmail', 'User is already a member.');

            return;
        }

        $this->team->addMember($user, TeamRole::from($this->memberRole));
        $this->memberEmail = '';
        $this->memberRole = 'viewer';
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manageMembers', $this->team);

        if ($userId === $this->team->owner_id) {
            return;
        }

        $user = User::findOrFail($userId);
        $this->team->removeMember($user);
    }

    public function changeRole(int $userId, string $role): void
    {
        $this->authorize('manageMembers', $this->team);

        if ($userId === $this->team->owner_id) {
            return;
        }

        $this->team->members()->updateExistingPivot($userId, [
            'role' => $role,
        ]);
    }

    public function render()
    {
        return view('livewire.teams.team-settings', [
            'members' => $this->team->members()->get(),
            'currentRole' => $this->team->memberRole(Auth::user()),
        ]);
    }
}
```

- [ ] **Step 4: Create team-settings view**

```blade
<x-layouts.app :title="$team->name . ' — Settings'">
    <div class="max-w-2xl mx-auto py-12 px-6">
        <div class="flex items-center justify-between mb-8">
            <flux:heading size="xl">{{ $team->name }}</flux:heading>
            <flux:button href="/" variant="ghost" icon="arrow-left">Back</flux:button>
        </div>

        {{-- Members --}}
        <flux:card class="mb-8">
            <flux:heading size="lg" class="mb-4">Members</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column>Role</flux:table.column>
                    @if ($currentRole?->canManageMembers())
                        <flux:table.column align="end">Actions</flux:table.column>
                    @endif
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($members as $member)
                        <flux:table.row :key="$member->id">
                            <flux:table.cell variant="strong">{{ $member->name }}</flux:table.cell>
                            <flux:table.cell>{{ $member->email }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($currentRole?->canManageMembers() && $member->id !== $team->owner_id)
                                    <flux:select
                                        wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                        size="sm"
                                    >
                                        @foreach (App\Enums\TeamRole::cases() as $role)
                                            @if ($role !== App\Enums\TeamRole::Owner)
                                                <flux:select.option
                                                    value="{{ $role->value }}"
                                                    :selected="$member->pivot->role === $role->value"
                                                >
                                                    {{ $role->label() }}
                                                </flux:select.option>
                                            @endif
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:badge size="sm">{{ $member->pivot->role }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            @if ($currentRole?->canManageMembers())
                                <flux:table.cell align="end">
                                    @if ($member->id !== $team->owner_id)
                                        <flux:button
                                            wire:click="removeMember({{ $member->id }})"
                                            wire:confirm="Remove {{ $member->name }} from this team?"
                                            variant="danger"
                                            size="xs"
                                            icon="trash"
                                        />
                                    @endif
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        {{-- Add Member --}}
        @if ($currentRole?->canManageMembers())
            <flux:card>
                <flux:heading size="lg" class="mb-4">Add member</flux:heading>

                <form wire:submit="addMember" class="flex items-end gap-3">
                    <div class="flex-1">
                        <flux:input wire:model="memberEmail" label="Email" type="email" placeholder="user@example.com" />
                    </div>
                    <div>
                        <flux:select wire:model="memberRole" label="Role">
                            <flux:select.option value="admin">Admin</flux:select.option>
                            <flux:select.option value="editor">Editor</flux:select.option>
                            <flux:select.option value="viewer">Viewer</flux:select.option>
                        </flux:select>
                    </div>
                    <flux:button type="submit" variant="primary">Add</flux:button>
                </form>
            </flux:card>
        @endif
    </div>
</x-layouts.app>
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Teams/TeamMembershipTest.php
```

Expected: All 8 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Teams/TeamSettings.php resources/views/livewire/teams/team-settings.blade.php tests/Feature/Teams/TeamMembershipTest.php
git commit -m "feat: add team settings with member management"
```

---

## Task 12: Team Middleware Tests

**Files:**
- Create: `tests/Feature/Teams/TeamMiddlewareTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_access_team_routes(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Test', 'owner_id' => $owner->id]);
        $team->addMember($owner, TeamRole::Owner);

        $response = $this->actingAs($owner)->get("/teams/{$team->id}/settings");

        $response->assertStatus(200);
    }

    public function test_non_member_gets_403(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $team = Team::create(['name' => 'Test', 'owner_id' => $owner->id]);
        $team->addMember($owner, TeamRole::Owner);

        $response = $this->actingAs($stranger)->get("/teams/{$team->id}/settings");

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['name' => 'Test', 'owner_id' => $owner->id]);
        $team->addMember($owner, TeamRole::Owner);

        $response = $this->get("/teams/{$team->id}/settings");

        $response->assertRedirect('/login');
    }

    public function test_invalid_team_id_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/teams/99999/settings');

        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Teams/TeamMiddlewareTest.php
```

Expected: All 4 tests PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Teams/TeamMiddlewareTest.php
git commit -m "test: add team middleware access control tests"
```

---

## Task 13: Run Full Test Suite & Verify

- [ ] **Step 1: Run all tests**

```bash
./vendor/bin/sail artisan test
```

Expected: All tests pass — registration (5), login (5), password reset (4), create team (4), team membership (8), team middleware (4) = 30 tests.

- [ ] **Step 2: Manually verify in browser**

Open http://localhost and verify:

1. `/register` — shows registration form, can create account
2. `/login` — shows login form, can sign in
3. `/` — shows "No teams yet" empty state after login
4. `/teams/create` — can create a team
5. `/teams/{id}/settings` — shows team settings with member list

- [ ] **Step 3: Final commit if any fixes needed**

```bash
./vendor/bin/sail artisan test
```

Expected: All 30 tests PASS. No fixes needed.
