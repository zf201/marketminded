<?php

use App\Models\ContentPiece;
use App\Models\User;

test('saveSnapshot creates v1 and syncs piece fields', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'status' => 'draft',
        'platform' => 'blog',
        'format' => 'pillar',
        'current_version' => 0,
    ]);

    $version = $piece->saveSnapshot('My Title', 'Heading and body text.', 'Initial draft');

    expect($piece->refresh()->current_version)->toBe(1);
    expect($piece->title)->toBe('My Title');
    expect($piece->body)->toBe('Heading and body text.');

    expect($version->version)->toBe(1);
    expect($version->title)->toBe('My Title');
    expect($version->body)->toBe('Heading and body text.');
    expect($version->change_description)->toBe('Initial draft');
    expect($version->content_piece_id)->toBe($piece->id);
});

test('saveSnapshot increments version and records change_description', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);

    $piece->saveSnapshot('First', 'Body 1', 'Initial draft');
    $piece->saveSnapshot('Second', 'Body 2', 'Punched up intro');
    $piece->saveSnapshot('Third', 'Body 3', 'Tightened section 3');

    expect($piece->refresh()->current_version)->toBe(3);
    expect($piece->title)->toBe('Third');
    expect($piece->body)->toBe('Body 3');

    $versions = $piece->versions()->reorder('version')->get();
    expect($versions)->toHaveCount(3);
    expect($versions->pluck('change_description')->all())
        ->toBe(['Initial draft', 'Punched up intro', 'Tightened section 3']);
});

test('versions relationship orders newest first by default', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);

    $piece->saveSnapshot('v1', 'b1');
    $piece->saveSnapshot('v2', 'b2');
    $piece->saveSnapshot('v3', 'b3');

    $versions = $piece->versions()->get();
    expect($versions->pluck('version')->all())->toBe([3, 2, 1]);
});
