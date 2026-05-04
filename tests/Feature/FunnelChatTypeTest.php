<?php

use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('renders create-chat with type=funnel param and shows the content piece picker', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $user->update(['current_team_id' => $team->id]);
    $piece = ContentPiece::factory()->create(['team_id' => $team->id, 'title' => 'Pickable Piece']);

    actingAs($user)
        ->get(route('create.new', ['current_team' => $team, 'type' => 'funnel']))
        ->assertOk()
        ->assertSee('Pick a content piece')
        ->assertSee('Pickable Piece');
});

it('runs propose_posts via the handler against a content piece', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);

    $handler = new \App\Services\SocialPostToolHandler();
    $result = json_decode($handler->propose($team, 0, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('saved')
        ->and(SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->count())->toBe(3);
});
