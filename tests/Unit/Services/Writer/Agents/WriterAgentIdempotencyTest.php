<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\Brief;

class StubbedWriterAgentForIdempotency extends WriterAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120, string $baseUrl = 'https://openrouter.ai/api/v1', string $provider = 'openrouter', ?\App\Services\BraveSearchClient $braveSearchClient = null): ?array
    {
        return $this->stubPayload;
    }
}

function briefForIdempotency(Team $team, Conversation $conversation): Brief
{
    $topic = Topic::create([
        'team_id' => $team->id, 'title' => 'T', 'angle' => 'a', 'status' => 'available',
    ]);

    return Brief::fromJson([
        'topic' => ['id' => $topic->id, 'title' => 'T', 'angle' => 'a', 'sources' => []],
        'research' => [
            'topic_summary' => 'S',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
        'outline' => [
            'angle' => 'a',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ],
    ])->withConversationId($conversation->id);
}

test('WriterAgent reuses existing piece for the same conversation (no duplicate row)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id, 'title' => 't', 'type' => 'writer',
    ]);

    $brief = briefForIdempotency($team, $conversation);
    $body = str_repeat('word ', 850);
    $agent = new StubbedWriterAgentForIdempotency(['title' => 'T1', 'body' => $body]);

    $first = $agent->execute($brief, $team);
    expect($first->isOk())->toBeTrue();
    $pieceId = $first->brief->contentPieceId();

    // Second call: same conversation, same agent stub. Must NOT create a new row.
    $second = $agent->execute($first->brief, $team);
    expect($second->isOk())->toBeTrue();
    expect($second->brief->contentPieceId())->toBe($pieceId);

    expect(ContentPiece::where('conversation_id', $conversation->id)->count())->toBe(1);

    $piece = ContentPiece::findOrFail($pieceId);
    expect($piece->current_version)->toBe(1); // idempotent: no extra version on re-call
    expect($piece->title)->toBe('T1');
});

test('WriterAgent recovers an orphan piece with current_version=0 and writes v1', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id, 'title' => 't', 'type' => 'writer',
    ]);

    // Simulate a previous run that created the row but crashed before saveSnapshot
    $orphan = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'title' => '', 'body' => '', 'current_version' => 0,
    ]);

    $brief = briefForIdempotency($team, $conversation);
    $agent = new StubbedWriterAgentForIdempotency(['title' => 'Recovered', 'body' => str_repeat('w ', 850)]);

    $result = $agent->execute($brief, $team);
    expect($result->isOk())->toBeTrue();
    expect($result->brief->contentPieceId())->toBe($orphan->id);

    $orphan->refresh();
    expect($orphan->current_version)->toBe(1);
    expect($orphan->title)->toBe('Recovered');
    expect(ContentPiece::where('conversation_id', $conversation->id)->count())->toBe(1);
});
