<?php

use App\Models\ContentPiece;
use App\Models\User;
use App\Services\Writer\Agents\ProofreadAgent;
use App\Services\Writer\Brief;

class StubbedProofreadAgent extends ProofreadAgent
{
    public function __construct(string $feedback, private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($feedback, $extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120, string $baseUrl = 'https://openrouter.ai/api/v1', string $provider = 'openrouter', ?\App\Services\BraveSearchClient $braveSearchClient = null, ?callable $onToolCall = null): ?array
    {
        return $this->stubPayload;
    }
}

test('ProofreadAgent ok path: saves new snapshot, version increments', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'title' => '',
        'body' => '',
        'current_version' => 0,
    ]);
    $piece->saveSnapshot('Original Title', str_repeat('w ', 850), 'Initial draft');

    $brief = Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []],
        'research' => ['topic_summary' => 's', 'claims' => [], 'sources' => []],
        'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => []],
        'content_piece_id' => $piece->id,
    ]);

    $payload = [
        'title' => 'Original Title (revised)',
        'body' => str_repeat('better word ', 500),
        'change_description' => 'Punched up intro and trimmed conclusion',
    ];

    $agent = new StubbedProofreadAgent('Make the intro punchier', $payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeTrue();

    $piece->refresh();
    expect($piece->current_version)->toBe(2);
    expect($piece->title)->toBe('Original Title (revised)');
    expect($piece->versions()->count())->toBe(2);

    $v2 = $piece->versions()->where('version', 2)->first();
    expect($v2->change_description)->toBe('Punched up intro and trimmed conclusion');
});

test('ProofreadAgent gate: refuses when content_piece_id missing', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedProofreadAgent('feedback', [
        'title' => 't', 'body' => str_repeat('w ', 50), 'change_description' => 'x',
    ]);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('content piece');
});

test('ProofreadAgent rejects empty title or body or change_description', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create(['team_id' => $team->id, 'title' => '', 'body' => '', 'current_version' => 0]);
    $piece->saveSnapshot('t', 'b', 'init');
    $brief = Brief::fromJson(['content_piece_id' => $piece->id]);

    foreach ([
        ['title' => '', 'body' => 'b', 'change_description' => 'x'],
        ['title' => 't', 'body' => '', 'change_description' => 'x'],
        ['title' => 't', 'body' => 'b', 'change_description' => ''],
    ] as $payload) {
        $agent = new StubbedProofreadAgent('feedback', $payload);
        $result = $agent->execute($brief, $team);
        expect($result->isOk())->toBeFalse();
    }
});

test('ProofreadAgent system prompt includes the user feedback', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $piece = ContentPiece::create(['team_id' => $team->id, 'title' => 'T', 'body' => 'B', 'current_version' => 1]);
    $brief = Brief::fromJson(['content_piece_id' => $piece->id]);

    $agent = new class('Make the intro punchier') extends ProofreadAgent {
        public function exposePrompt(Brief $b, $t): string
        {
            return $this->systemPrompt($b, $t);
        }
        protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120, string $baseUrl = 'https://openrouter.ai/api/v1', string $provider = 'openrouter', ?\App\Services\BraveSearchClient $braveSearchClient = null, ?callable $onToolCall = null): ?array
        {
            return null;
        }
    };

    $prompt = $agent->exposePrompt($brief, $team);
    expect($prompt)->toContain('Make the intro punchier');
});
