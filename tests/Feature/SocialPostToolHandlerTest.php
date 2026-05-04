<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use App\Services\ChatPromptBuilder;
use App\Services\SocialPostToolHandler;

it('exposes four tool schemas', function () {
    $names = [
        SocialPostToolHandler::proposeSchema()['function']['name'],
        SocialPostToolHandler::updateSchema()['function']['name'],
        SocialPostToolHandler::deleteSchema()['function']['name'],
        SocialPostToolHandler::replaceAllSchema()['function']['name'],
    ];

    expect($names)->toBe(['propose_posts', 'update_post', 'delete_post', 'replace_all_posts']);
});

it('propose_posts schema requires posts array of 3-6 items', function () {
    $schema = SocialPostToolHandler::proposeSchema()['function']['parameters'];
    expect($schema['properties']['posts']['minItems'])->toBe(3)
        ->and($schema['properties']['posts']['maxItems'])->toBe(6);
});

it('post item schema enumerates platforms', function () {
    $schema = SocialPostToolHandler::proposeSchema()['function']['parameters'];
    expect($schema['properties']['posts']['items']['properties']['platform']['enum'])
        ->toBe(['linkedin', 'facebook', 'instagram', 'short_video']);
});

it('propose() saves posts and returns ids', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $handler = new SocialPostToolHandler();
    $result = json_decode($handler->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h1', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h2', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h3', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('saved')
        ->and($result['ids'])->toHaveCount(3)
        ->and(SocialPost::where('content_piece_id', $piece->id)->count())->toBe(3);
});

it('propose() rejects missing [POST_URL] in body', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $result = json_decode((new SocialPostToolHandler())->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h', 'body' => 'no placeholder', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('[POST_URL]');
});

it('propose() rejects more than one short_video post', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);

    $result = json_decode((new SocialPostToolHandler())->propose($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'short_video', 'hook' => 'h', 'body' => 'b [POST_URL]', 'video_treatment' => 'v'],
            ['platform' => 'short_video', 'hook' => 'h', 'body' => 'b [POST_URL]', 'video_treatment' => 'v'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('short_video');
});

it('update() patches one post within team scope', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $post = SocialPost::factory()->create([
        'team_id' => $team->id,
        'content_piece_id' => $piece->id,
        'platform' => 'linkedin',
        'hook' => 'old hook',
    ]);

    $result = json_decode((new SocialPostToolHandler())->update($team, 0, [
        'id' => $post->id,
        'hook' => 'new hook',
    ]), true);

    expect($result['status'])->toBe('saved')
        ->and($post->fresh()->hook)->toBe('new hook');
});

it('update() refuses cross-team ids', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $teamB->id]);
    $post = SocialPost::factory()->create(['team_id' => $teamB->id, 'content_piece_id' => $piece->id]);

    $result = json_decode((new SocialPostToolHandler())->update($teamA, 0, [
        'id' => $post->id, 'hook' => 'x',
    ]), true);

    expect($result['status'])->toBe('error');
});

it('delete() soft-deletes', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $post = SocialPost::factory()->create(['team_id' => $team->id, 'content_piece_id' => $piece->id]);

    (new SocialPostToolHandler())->delete($team, ['id' => $post->id]);

    expect($post->fresh()->status)->toBe('deleted');
});

it('replaceAll() soft-deletes existing and creates new set', function () {
    $team = Team::factory()->create();
    $piece = ContentPiece::factory()->create(['team_id' => $team->id]);
    $conv = Conversation::factory()->create(['team_id' => $team->id]);
    SocialPost::factory()->count(3)->create(['team_id' => $team->id, 'content_piece_id' => $piece->id]);

    (new SocialPostToolHandler())->replaceAll($team, $conv->id, $piece, [
        'posts' => [
            ['platform' => 'linkedin', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'facebook', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
            ['platform' => 'instagram', 'hook' => 'h', 'body' => 'b [POST_URL]', 'image_prompt' => 'img'],
        ],
    ]);

    expect(SocialPost::where('content_piece_id', $piece->id)->where('status', 'active')->count())->toBe(3)
        ->and(SocialPost::where('content_piece_id', $piece->id)->where('status', 'deleted')->count())->toBe(3);
});
