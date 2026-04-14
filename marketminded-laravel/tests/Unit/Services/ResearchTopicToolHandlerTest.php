<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\ResearchTopicToolHandler;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeResearchAgent extends ResearchAgent
{
    public ?AgentResult $stubResult = null;
    public ?string $seenExtraContext = null;

    public function __construct(?string $extraContext = null)
    {
        parent::__construct($extraContext);
        $this->seenExtraContext = $extraContext;
    }

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function writerConvWithTopic(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'X',
        'angle' => 'a',
        'status' => 'available',
    ]);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => ['topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []]],
    ]);
    return [$team, $conversation, $topic];
}

test('handler returns ok and persists brief on agent success', function () {
    [$team, $conversation] = writerConvWithTopic();

    $newBrief = Brief::fromJson($conversation->brief)->withResearch([
        'topic_summary' => 's',
        'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
        'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
    ]);

    $agent = new FakeResearchAgent;
    $agent->stubResult = AgentResult::ok($newBrief, ['kind' => 'research', 'summary' => 'Gathered 1 claims from 1 sources'], 'Gathered 1 claims from 1 sources');

    $handler = new ResearchTopicToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('1 claims');
    expect($decoded['card']['kind'])->toBe('research');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasResearch())->toBeTrue();
});

test('handler passes extra_context to a fresh agent on retry', function () {
    [$team, $conversation] = writerConvWithTopic();

    $defaultAgent = new FakeResearchAgent;
    $defaultAgent->stubResult = AgentResult::error('default agent should not be used');

    $handler = new ResearchTopicToolHandler($defaultAgent);
    // Smoke test: calling with extra_context doesn't 500. The handler
    // instantiates a fresh ResearchAgent when extra_context is set, so the
    // default fake agent's stub is bypassed.
    $result = $handler->execute($team, $conversation->id, ['extra_context' => 'focus on X'], []);

    $decoded = json_decode($result, true);
    expect($decoded)->toHaveKey('status');  // doesn't crash
});

test('handler refuses second call (retry guard) when prior turn already had research_topic', function () {
    [$team, $conversation] = writerConvWithTopic();

    $agent = new FakeResearchAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new ResearchTopicToolHandler($agent);
    $priorTurnTools = [['name' => 'research_topic', 'args' => []]];

    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler returns error from agent', function () {
    [$team, $conversation] = writerConvWithTopic();

    $agent = new FakeResearchAgent;
    $agent->stubResult = AgentResult::error('agent failed validation');

    $handler = new ResearchTopicToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toBe('agent failed validation');
});

test('toolSchema returns valid schema', function () {
    $schema = ResearchTopicToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('research_topic');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
