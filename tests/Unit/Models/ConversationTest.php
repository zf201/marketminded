<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

test('conversation belongs to team and user', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test conversation',
    ]);

    expect($conversation->team->id)->toBe($team->id);
    expect($conversation->user->id)->toBe($user->id);
});

test('conversation has many messages ordered by created_at', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test',
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'First',
        'created_at' => now()->subMinute(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Second',
        'created_at' => now(),
    ]);

    $messages = $conversation->messages;
    expect($messages)->toHaveCount(2);
    expect($messages->first()->content)->toBe('First');
    expect($messages->last()->content)->toBe('Second');
});

test('team has many conversations', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Chat 1',
    ]);

    expect($team->conversations)->toHaveCount(1);
});

test('Conversation topic() returns linked topic', function () {
    $user = \App\Models\User::factory()->create();
    $team = $user->currentTeam;

    $topic = \App\Models\Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy angle',
        'status' => 'available',
    ]);

    $conversation = \App\Models\Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
        'topic_id' => $topic->id,
    ]);

    expect($conversation->topic)->not->toBeNull();
    expect($conversation->topic->id)->toBe($topic->id);
});

test('Conversation contentPieces() returns linked pieces', function () {
    $user = \App\Models\User::factory()->create();
    $team = $user->currentTeam;

    $conversation = \App\Models\Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
    ]);

    \App\Models\ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'title' => 'Piece',
        'body' => 'body',
    ]);

    expect($conversation->contentPieces)->toHaveCount(1);
});

test('Conversation casts brief as array and accepts updates', function () {
    $user = \App\Models\User::factory()->create();
    $team = $user->currentTeam;

    $conversation = \App\Models\Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'W',
        'type' => 'writer',
    ]);

    expect($conversation->brief)->toBe([]);

    $conversation->update(['brief' => ['topic' => ['id' => 1, 'title' => 'X']]]);
    $conversation->refresh();

    expect($conversation->brief)->toBe(['topic' => ['id' => 1, 'title' => 'X']]);
});
