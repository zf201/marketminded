<?php

use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\ResearchTopicToolHandler;

test('execute returns ok with claim count', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy-first marketing',
        'status' => 'available',
    ]);

    $handler = new ResearchTopicToolHandler;
    $result = $handler->execute($team, 123, [
        'topic_summary' => 'How zero-party data reshapes marketing.',
        'claims' => [
            ['id' => 'c1', 'text' => 'Consumers trust brands that ask.', 'sources' => [
                ['url' => 'https://example.com/a', 'title' => 'Source A'],
            ]],
            ['id' => 'c2', 'text' => 'Third-party cookies are going away.', 'sources' => [
                ['url' => 'https://example.com/b', 'title' => 'Source B'],
            ]],
        ],
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['claim_count'])->toBe(2);
});

test('execute rejects claims missing id or text', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'T',
        'angle' => 'a',
        'status' => 'available',
    ]);

    $handler = new ResearchTopicToolHandler;
    $result = $handler->execute($team, 123, [
        'topic_summary' => 'summary',
        'claims' => [
            ['id' => 'c1'],
        ],
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('text');
});

test('toolSchema returns valid schema', function () {
    $schema = ResearchTopicToolHandler::toolSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('research_topic');
    expect($schema['function']['parameters']['required'])->toContain('claims');
    expect($schema['function']['parameters']['required'])->toContain('topic_summary');
});
