<?php

use App\Models\ContentPiece;
use App\Models\Team;
use App\Models\Topic;
use App\Services\MarkUsedToolHandler;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('marks a topic as used', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => false]);

    $result = MarkUsedToolHandler::run($this->team, ['type' => 'topic', 'id' => $topic->id, 'used' => true]);

    expect($result['status'])->toBe('ok');
    expect($topic->fresh()->used)->toBeTrue();
});

it('unmarks a content piece', function () {
    $piece = ContentPiece::factory()->for($this->team)->create(['used' => true]);

    $result = MarkUsedToolHandler::run($this->team, ['type' => 'content_piece', 'id' => $piece->id, 'used' => false]);

    expect($result['status'])->toBe('ok');
    expect($piece->fresh()->used)->toBeFalse();
});

it('rejects unknown type', function () {
    $result = MarkUsedToolHandler::run($this->team, ['type' => 'banana', 'id' => 1, 'used' => true]);
    expect($result['status'])->toBe('error');
});

it('rejects cross-team ids', function () {
    $otherTeam = Team::factory()->create();
    $topic = Topic::factory()->for($otherTeam)->create(['used' => false]);

    $result = MarkUsedToolHandler::run($this->team, ['type' => 'topic', 'id' => $topic->id, 'used' => true]);

    expect($result['status'])->toBe('error');
    expect($topic->fresh()->used)->toBeFalse();
});
