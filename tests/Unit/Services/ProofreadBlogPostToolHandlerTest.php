<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Services\ProofreadBlogPostToolHandler;
use App\Services\Writer\Agent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeProofreadAgent implements Agent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, Team $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub');
    }
}

function writerConvWithPiece(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('T', str_repeat('w ', 850), 'init');

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'brief' => ['content_piece_id' => $piece->id],
    ]);

    return [$team, $conversation, $piece];
}

test('handler returns ok and persists brief on success', function () {
    [$team, $conversation, $piece] = writerConvWithPiece();

    $brief = Brief::fromJson($conversation->brief);

    $agent = new FakeProofreadAgent;
    $agent->stubResult = AgentResult::ok(
        $brief,  // brief unchanged for proofread
        ['kind' => 'content_piece', 'summary' => 'Revised · punchier intro', 'title' => 'T'],
        'Revised · punchier intro'
    );

    $handler = new ProofreadBlogPostToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, ['feedback' => 'punchier intro'], []);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('Revised');
    expect($decoded['piece_id'])->toBe($piece->id);
});

test('proofread handler returns existing piece card on duplicate in-turn call', function () {
    [$team, $conversation, $piece] = writerConvWithPiece();

    // Ensure piece has conversation_id and a version > 1 to simulate prior proofread
    $piece->update(['conversation_id' => $conversation->id, 'current_version' => 2]);

    $agent = new FakeProofreadAgent;
    $agent->stubResult = AgentResult::error('agent should not be called on idempotent retry');

    $handler = new ProofreadBlogPostToolHandler($agent);
    $result = $handler->execute(
        $team,
        $conversation->id,
        ['feedback' => 'x'],
        [['name' => 'proofread_blog_post', 'args' => [], 'status' => 'ok']],
    );
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['piece_id'])->toBe($piece->id);
    expect($decoded['card']['title'])->toBe('T');
});

test('handler returns error when feedback is missing', function () {
    [$team, $conversation] = writerConvWithPiece();

    $handler = new ProofreadBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('feedback');
});

test('toolSchema returns valid schema', function () {
    $schema = ProofreadBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('proofread_blog_post');
    expect($schema['function']['parameters']['required'])->toContain('feedback');
});
