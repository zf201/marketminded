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
    $team = Team::factory()->create(['ai_api_key' => 'sk-test']);
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
    $team = Team::factory()->create(['ai_api_key' => 'sk-test']);
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

test('api key is required', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'old-key']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('aiApiKey', '')
        ->call('updateAiSettings')
        ->assertHasErrors(['aiApiKey']);
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
