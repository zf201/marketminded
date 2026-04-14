<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\CreateOutlineToolHandler;

function makeWriterConversation(): Conversation
{
    $user = User::factory()->create();
    return Conversation::create([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'title' => 'W',
        'type' => 'writer',
    ]);
}

function addResearchMessage(Conversation $c, array $claimIds): void
{
    Message::create([
        'conversation_id' => $c->id,
        'role' => 'assistant',
        'content' => '',
        'metadata' => [
            'tools' => [[
                'name' => 'research_topic',
                'args' => [
                    'topic_summary' => 's',
                    'claims' => array_map(fn($id) => [
                        'id' => $id,
                        'text' => 't',
                        'sources' => [['url' => 'u', 'title' => 't']],
                    ], $claimIds),
                ],
            ]],
        ],
    ]);
}

test('execute accepts outline when all claim_ids exist in prior research', function () {
    $c = makeWriterConversation();
    addResearchMessage($c, ['c1', 'c2', 'c3']);

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 'Intro to Zero Party Data',
        'angle' => 'Privacy-first wins long-term',
        'target_length_words' => 1500,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'Hook', 'claim_ids' => ['c1']],
            ['heading' => 'Body', 'purpose' => 'Evidence', 'claim_ids' => ['c2', 'c3']],
        ],
    ]);

    expect(json_decode($result, true)['status'])->toBe('ok');
});

test('execute rejects outline when claim_ids are missing', function () {
    $c = makeWriterConversation();
    addResearchMessage($c, ['c1']);

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 'x',
        'angle' => 'y',
        'target_length_words' => 1200,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1', 'c9']],
        ],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('c9');
});

test('execute errors when no research_topic output exists', function () {
    $c = makeWriterConversation();

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 'x',
        'angle' => 'y',
        'target_length_words' => 1200,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
        ],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research_topic');
});

test('execute accepts outline using in-turn research_topic result (no DB message)', function () {
    $c = makeWriterConversation();
    // NOT adding a persisted research_topic message. Pass it via priorTurnTools.
    $priorTurnTools = [[
        'name' => 'research_topic',
        'args' => [
            'topic_summary' => 's',
            'claims' => [
                ['id' => 'c1', 'text' => 't', 'sources' => [['url' => 'u', 'title' => 't']]],
                ['id' => 'c2', 'text' => 't', 'sources' => [['url' => 'u', 'title' => 't']]],
            ],
        ],
    ]];

    $handler = new CreateOutlineToolHandler;
    $result = $handler->execute($c->team, $c->id, [
        'title' => 't',
        'angle' => 'a',
        'target_length_words' => 1500,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
            ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c2']],
        ],
    ], $priorTurnTools);

    expect(json_decode($result, true)['status'])->toBe('ok');
});

test('toolSchema returns valid schema', function () {
    $schema = CreateOutlineToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('create_outline');
});
