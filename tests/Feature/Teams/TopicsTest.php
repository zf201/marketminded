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
