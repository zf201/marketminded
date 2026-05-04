<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;

it('belongs to team, content piece, and conversation', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $post = SocialPost::factory()->create([
        'team_id' => $team->id,
        'content_piece_id' => $piece->id,
        'conversation_id' => $conv->id,
        'platform' => 'linkedin',
    ]);

    expect($post->team->id)->toBe($team->id)
        ->and($post->contentPiece->id)->toBe($piece->id)
        ->and($post->conversation->id)->toBe($conv->id);
});

it('casts hashtags as array', function () {
    $post = SocialPost::factory()->create(['hashtags' => ['ai', 'marketing']]);
    expect($post->fresh()->hashtags)->toBe(['ai', 'marketing']);
});

it('exposes social posts on content piece', function () {
    $piece = ContentPiece::factory()->create();
    SocialPost::factory()->count(3)->create(['content_piece_id' => $piece->id, 'team_id' => $piece->team_id]);
    expect($piece->socialPosts)->toHaveCount(3);
});
