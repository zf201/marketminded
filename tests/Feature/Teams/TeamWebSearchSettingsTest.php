<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('owner can set web search to openrouter built-in', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'sk-test']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('webSearchProvider', 'openrouter_builtin')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    expect($team->fresh()->web_search_provider)->toBe('openrouter_builtin');
});

test('owner can set web search to brave with api key', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'sk-test']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('webSearchProvider', 'brave')
        ->set('braveApiKey', 'BSA-test-key')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->web_search_provider)->toBe('brave');
    expect($team->brave_api_key)->toBe('BSA-test-key');
});

test('owner can disable web search', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'sk-test', 'web_search_provider' => 'brave', 'brave_api_key' => 'BSA-key']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('webSearchProvider', 'none')
        ->call('updateAiSettings')
        ->assertHasNoErrors();

    expect($team->fresh()->web_search_provider)->toBe('none');
});

test('brave api key is required when brave is selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'sk-test']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('webSearchProvider', 'brave')
        ->set('braveApiKey', '')
        ->call('updateAiSettings')
        ->assertHasErrors(['braveApiKey']);
});

test('member cannot update web search settings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('webSearchProvider', 'none')
        ->call('updateAiSettings')
        ->assertForbidden();
});

test('web search defaults to openrouter_builtin', function () {
    $team = Team::factory()->create();

    expect($team->web_search_provider)->toBe('openrouter_builtin');
    expect($team->brave_api_key)->toBeNull();
});

test('invalid web search provider is rejected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['ai_api_key' => 'sk-test']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('webSearchProvider', 'invalid')
        ->call('updateAiSettings')
        ->assertHasErrors(['webSearchProvider']);
});
