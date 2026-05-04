<?php

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->user->update(['current_team_id' => $this->team->id]);
});

it('shows pieces with active social posts', function () {
    $pieceWith = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Has Posts']);
    $pieceWithout = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Bare Piece']);
    SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $pieceWith->id, 'platform' => 'linkedin']);

    actingAs($this->user)
        ->get(route('social.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Has Posts')
        ->assertDontSee('Bare Piece');
});

it('shows empty state when no funnels exist', function () {
    actingAs($this->user)
        ->get(route('social.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Build a Funnel');
});

it('hides pieces whose only posts are deleted', function () {
    $piece = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Empty Piece']);
    SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $piece->id, 'status' => 'deleted']);

    actingAs($this->user)
        ->get(route('social.index', ['current_team' => $this->team]))
        ->assertDontSee('Empty Piece');
});
