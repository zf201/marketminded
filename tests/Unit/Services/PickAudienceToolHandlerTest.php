<?php

use App\Models\AudiencePersona;
use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\PickAudienceToolHandler;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Agents\AudiencePickerAgent;
use App\Services\Writer\Brief;

class FakeAudiencePickerAgent extends AudiencePickerAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function convWithResearchAndPersona(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $persona = AudiencePersona::create([
        'team_id' => $team->id,
        'label' => 'Pro Chef',
        'description' => 'Works in kitchens.',
        'pain_points' => 'Cheap tools.',
        'sort_order' => 1,
    ]);

    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Knives',
        'angle' => 'Pro kitchens',
        'status' => 'available',
    ]);

    $brief = [
        'topic' => ['id' => $topic->id, 'title' => 'Knives', 'angle' => 'Pro kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
    ];

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => $brief,
    ]);

    return [$team, $conversation, $persona];
}

test('handler returns skipped when team has no personas', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create(['team_id' => $team->id, 'title' => 'X', 'angle' => 'a', 'status' => 'available']);
    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id,
        'title' => 't', 'type' => 'writer', 'topic_id' => $topic->id,
        'brief' => ['topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []]],
    ]);

    $handler = new PickAudienceToolHandler;
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('skipped');
    expect($decoded['reason'])->toContain('persona');

    // brief must not be modified
    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasAudience())->toBeFalse();
});

test('handler returns ok and persists brief on agent success', function () {
    [$team, $conversation] = convWithResearchAndPersona();

    $newBrief = Brief::fromJson($conversation->brief)->withAudience([
        'mode' => 'persona',
        'persona_id' => 1,
        'persona_label' => 'Pro Chef',
        'persona_summary' => 'Works in kitchens.',
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);

    $agent = new FakeAudiencePickerAgent;
    $agent->stubResult = AgentResult::ok(
        $newBrief,
        ['kind' => 'audience', 'summary' => 'Audience: persona selected', 'mode' => 'persona', 'guidance_for_writer' => 'g'],
        'Audience: persona selected',
    );

    $handler = new PickAudienceToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toBe('Audience: persona selected');
    expect($decoded['card']['kind'])->toBe('audience');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasAudience())->toBeTrue();
});

test('handler is idempotent on duplicate in-turn call', function () {
    [$team, $conversation] = convWithResearchAndPersona();

    // Persist audience into the brief first.
    $audienceBrief = Brief::fromJson($conversation->brief)->withAudience([
        'mode' => 'educational',
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $conversation->update(['brief' => $audienceBrief->toJson()]);

    $agent = new FakeAudiencePickerAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new PickAudienceToolHandler($agent);
    $priorTurnTools = [['name' => 'pick_audience', 'args' => [], 'status' => 'ok']];
    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('already');
});

test('handler returns error from agent', function () {
    [$team, $conversation] = convWithResearchAndPersona();

    $agent = new FakeAudiencePickerAgent;
    $agent->stubResult = AgentResult::error('validation failed');

    $handler = new PickAudienceToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toBe('validation failed');
});

test('toolSchema returns valid schema', function () {
    $schema = PickAudienceToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('pick_audience');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
