<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\TopicToolHandler;

test('saves topics to the database', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test',
        'type' => 'topics',
    ]);

    $handler = new TopicToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'topics' => [
            [
                'title' => 'Why Zero-Party Data Matters',
                'angle' => 'Privacy-first positioning advantage',
                'sources' => ['Reuters article', 'HubSpot study'],
            ],
            [
                'title' => 'The Hidden Cost of Free Analytics',
                'angle' => 'Connects to privacy messaging',
            ],
        ],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('saved');
    expect($decoded['count'])->toBe(2);
    expect($decoded['titles'])->toContain('Why Zero-Party Data Matters');
    expect($decoded['titles'])->toContain('The Hidden Cost of Free Analytics');

    $topics = $team->topics()->get();
    expect($topics)->toHaveCount(2);
    expect($topics[0]->status)->toBe('available');
    expect($topics[0]->conversation_id)->toBe($conversation->id);
    expect($topics[0]->sources)->toBe(['Reuters article', 'HubSpot study']);
    expect($topics[0]->score)->toBeNull();
});

test('saves topics without sources', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Test',
        'type' => 'topics',
    ]);

    $handler = new TopicToolHandler;
    $handler->execute($team, $conversation->id, [
        'topics' => [
            ['title' => 'A Topic', 'angle' => 'An angle'],
        ],
    ]);

    $topic = $team->topics()->first();
    expect($topic->sources)->toBe([]);
});

test('toolSchema returns valid schema', function () {
    $schema = TopicToolHandler::toolSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('save_topics');
    expect($schema['function']['parameters']['properties']['topics'])->not->toBeEmpty();
});
