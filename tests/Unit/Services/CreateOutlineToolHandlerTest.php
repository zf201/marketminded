<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\CreateOutlineToolHandler;
use App\Services\Writer\Agent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;
use App\Models\Team;

class FakeEditorAgent implements Agent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, Team $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub');
    }
}

function writerConvWithResearch(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create(['team_id' => $team->id, 'title' => 'X', 'angle' => 'a', 'status' => 'available']);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => [
            'topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []],
            'research' => ['topic_summary' => 's', 'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]], 'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']]],
        ],
    ]);
    return [$team, $conversation];
}

test('handler returns ok and persists brief on agent success', function () {
    [$team, $conversation] = writerConvWithResearch();

    $newBrief = Brief::fromJson($conversation->brief)->withOutline([
        'angle' => 'a',
        'target_length_words' => 1500,
        'sections' => [['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']], ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']]],
    ]);

    $agent = new FakeEditorAgent;
    $agent->stubResult = AgentResult::ok($newBrief, ['kind' => 'outline', 'summary' => 'Outline ready · 2 sections · ~1500 words'], 'Outline ready · 2 sections · ~1500 words');

    $handler = new CreateOutlineToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('2 sections');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasOutline())->toBeTrue();
});

test('handler refuses second call (retry guard)', function () {
    [$team, $conversation] = writerConvWithResearch();

    $agent = new FakeEditorAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new CreateOutlineToolHandler($agent);
    $priorTurnTools = [['name' => 'create_outline', 'args' => [], 'status' => 'ok']];

    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler propagates agent error', function () {
    [$team, $conversation] = writerConvWithResearch();

    $agent = new FakeEditorAgent;
    $agent->stubResult = AgentResult::error('outline references unknown claim id: c99');

    $handler = new CreateOutlineToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('c99');
});

test('toolSchema returns valid schema', function () {
    $schema = CreateOutlineToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('create_outline');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
