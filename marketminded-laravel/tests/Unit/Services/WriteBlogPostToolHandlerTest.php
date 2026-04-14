<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\WriteBlogPostToolHandler;
use App\Services\Writer\Agent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeWriterAgent implements Agent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, Team $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub');
    }
}

function writerConvWithFullBrief(): array
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
            'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => [['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']], ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']]]],
        ],
    ]);
    return [$team, $conversation, $topic];
}

test('handler returns ok, persists brief, patches conversation_id onto piece', function () {
    [$team, $conversation, $topic] = writerConvWithFullBrief();

    // Pre-create the piece as the agent would
    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'topic_id' => $topic->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('Title', str_repeat('w ', 850), 'Initial draft');

    $newBrief = Brief::fromJson($conversation->brief)->withContentPieceId($piece->id);

    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::ok(
        $newBrief,
        ['kind' => 'content_piece', 'summary' => 'Draft created · v1', 'title' => 'Title', 'preview' => '...'],
        'Draft created · v1 · 850 words'
    );

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');

    $piece->refresh();
    expect($piece->conversation_id)->toBe($conversation->id);

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->contentPieceId())->toBe($piece->id);
});

test('handler refuses second call (retry guard)', function () {
    [$team, $conversation] = writerConvWithFullBrief();

    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], [['name' => 'write_blog_post', 'args' => []]]);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already retried');
});

test('handler propagates agent gate error', function () {
    [$team, $conversation] = writerConvWithFullBrief();

    $agent = new FakeWriterAgent;
    $agent->stubResult = AgentResult::error('Cannot write without research. Run research_topic first.');

    $handler = new WriteBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research');
});

test('toolSchema returns valid schema', function () {
    $schema = WriteBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('write_blog_post');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
