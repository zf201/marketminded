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
