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
