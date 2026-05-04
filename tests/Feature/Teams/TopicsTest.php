<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use Livewire\Livewire;

function makeOwnerWithTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('topics page renders for an authenticated team owner', function () {
    [$user, $team] = makeOwnerWithTeam();

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertOk();
});

test('topics page shows a sources disclosure when sources are present', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'A topic with sources',
        'angle' => 'Some angle.',
        'sources' => ['https://example.com/a', 'https://example.com/b'],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('Sources (2)')
        ->assertSee('https://example.com/a')
        ->assertSee('https://example.com/b');
});

test('topics page omits the sources disclosure when sources are empty', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'A topic without sources',
        'angle' => 'Some angle.',
        'sources' => [],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertDontSee('Sources (');
});

test('topics page embeds markdown payload including sources block when sources are present', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'How to ship faster',
        'angle' => 'Practical takes from teams that ship daily.',
        'sources' => ['https://example.com/a', 'https://example.com/b'],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('aria-label="Copy as markdown"', false)
        ->assertSee('# How to ship faster', false)
        ->assertSee('Practical takes from teams that ship daily.', false)
        ->assertSee('## Sources', false)
        // URLs in @js output are JSON-encoded with escaped slashes.
        ->assertSee('- https:\/\/example.com\/a', false)
        ->assertSee('- https:\/\/example.com\/b', false);
});

test('topics page embeds markdown payload without sources block when sources are empty', function () {
    [$user, $team] = makeOwnerWithTeam();

    Topic::create([
        'team_id' => $team->id,
        'title' => 'A bare topic',
        'angle' => 'Just an angle.',
        'sources' => [],
        'status' => 'available',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::teams.topics', ['current_team' => $team])
        ->assertSee('aria-label="Copy as markdown"', false)
        ->assertSee('# A bare topic', false)
        ->assertDontSee('## Sources');
});
