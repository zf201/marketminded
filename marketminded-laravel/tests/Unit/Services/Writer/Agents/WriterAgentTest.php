<?php

use App\Models\ContentPiece;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Services\Writer\Agents\WriterAgent;
use App\Services\Writer\Brief;

class StubbedWriterAgent extends WriterAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120): ?array
    {
        return $this->stubPayload;
    }
}

function fullBriefForWriter(Team $team): array
{
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy',
        'status' => 'available',
    ]);

    return [
        'brief' => Brief::fromJson([
            'topic' => ['id' => $topic->id, 'title' => 'Zero Party Data', 'angle' => 'Privacy', 'sources' => []],
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
        ]),
        'topic' => $topic,
    ];
}

test('WriterAgent ok path: creates ContentPiece, writes brief.content_piece_id, marks topic used', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    $body = str_repeat('word ', 850);  // > 800-word lower bound
    $payload = ['title' => 'My Title', 'body' => $body];

    $agent = new StubbedWriterAgent($payload);
    $result = $agent->execute($ctx['brief'], $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasContentPiece())->toBeTrue();

    $piece = ContentPiece::findOrFail($result->brief->contentPieceId());
    expect($piece->title)->toBe('My Title');
    expect($piece->team_id)->toBe($team->id);
    expect($piece->topic_id)->toBe($ctx['topic']->id);
    expect($piece->status)->toBe('draft');
    expect($piece->current_version)->toBe(1);

    expect($ctx['topic']->refresh()->status)->toBe('used');
});

test('WriterAgent gate: refuses when research is missing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $brief = Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []],
        // no research
        'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => [
            ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']],
            ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']],
        ]],
    ]);

    $agent = new StubbedWriterAgent(['title' => 'T', 'body' => str_repeat('w ', 850)]);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('research');
});

test('WriterAgent gate: refuses when outline is missing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $brief = Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []],
        'research' => ['topic_summary' => 's', 'claims' => [], 'sources' => []],
        // no outline
    ]);

    $agent = new StubbedWriterAgent(['title' => 'T', 'body' => str_repeat('w ', 850)]);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('outline');
});

test('WriterAgent rejects body shorter than 800 words', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    $payload = ['title' => 'T', 'body' => str_repeat('w ', 100)];

    $agent = new StubbedWriterAgent($payload);
    $result = $agent->execute($ctx['brief'], $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('800 words');
});

test('WriterAgent rejects empty title', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $ctx = fullBriefForWriter($team);

    $payload = ['title' => '', 'body' => str_repeat('w ', 850)];

    $agent = new StubbedWriterAgent($payload);
    $result = $agent->execute($ctx['brief'], $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('title');
});

test('WriterAgent uses team powerful_model', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['fast_model' => 'fast/x', 'powerful_model' => 'powerful/x']);

    $agent = new class extends WriterAgent {
        public function exposeModel(Team $t): string
        {
            return $this->model($t);
        }
    };

    expect($agent->exposeModel($team))->toBe('powerful/x');
});
