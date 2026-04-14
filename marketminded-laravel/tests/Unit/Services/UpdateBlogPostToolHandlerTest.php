<?php

use App\Models\ContentPiece;
use App\Models\User;
use App\Services\UpdateBlogPostToolHandler;

test('update creates a new version and updates piece state', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('Original', 'Original body', 'Initial draft');

    $handler = new UpdateBlogPostToolHandler;
    $result = $handler->execute($team, 0, [
        'content_piece_id' => $piece->id,
        'title' => 'Original (revised)',
        'body' => 'Original body with a punchier intro.',
        'change_description' => 'Punched up intro',
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['version'])->toBe(2);

    $piece->refresh();
    expect($piece->current_version)->toBe(2);
    expect($piece->title)->toBe('Original (revised)');
    expect($piece->versions()->count())->toBe(2);

    $v2 = $piece->versions()->where('version', 2)->first();
    expect($v2->change_description)->toBe('Punched up intro');
    expect($v2->body)->toBe('Original body with a punchier intro.');
});

test('update rejects piece from another team', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $piece = ContentPiece::create([
        'team_id' => $userA->currentTeam->id,
        'title' => 't',
        'body' => 'b',
        'current_version' => 1,
    ]);

    $handler = new UpdateBlogPostToolHandler;
    $result = $handler->execute($userB->currentTeam, 0, [
        'content_piece_id' => $piece->id,
        'title' => 'hack',
        'body' => 'hack',
        'change_description' => 'x',
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
});

test('toolSchema returns valid schema', function () {
    $schema = UpdateBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('update_blog_post');
});
