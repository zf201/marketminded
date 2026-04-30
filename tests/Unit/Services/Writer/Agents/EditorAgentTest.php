<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Writer\Agents\EditorAgent;
use App\Services\Writer\Brief;

class StubbedEditorAgent extends EditorAgent
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

function briefWithResearch(): Brief
{
    return Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'Zero Party Data', 'angle' => 'Privacy', 'sources' => []],
        'research' => [
            'topic_summary' => 'Summary',
            'claims' => [
                ['id' => 'c1', 'text' => 'Claim 1', 'type' => 'fact', 'source_ids' => ['s1']],
                ['id' => 'c2', 'text' => 'Claim 2', 'type' => 'stat', 'source_ids' => ['s1']],
                ['id' => 'c3', 'text' => 'Claim 3', 'type' => 'quote', 'source_ids' => ['s1']],
            ],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
    ]);
}

function validOutlinePayload(): array
{
    return [
        'angle' => 'Privacy-first wins long-term',
        'target_length_words' => 1500,
        'sections' => [
            ['heading' => 'Intro', 'purpose' => 'Hook', 'claim_ids' => ['c1']],
            ['heading' => 'Body', 'purpose' => 'Evidence', 'claim_ids' => ['c2', 'c3']],
        ],
    ];
}

test('EditorAgent ok path: validates and writes outline to brief', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $agent = new StubbedEditorAgent(validOutlinePayload());
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasOutline())->toBeTrue();
    expect($result->brief->outline()['sections'])->toHaveCount(2);
    expect($result->summary)->toContain('2 sections');
    expect($result->summary)->toContain('1500');
});

test('EditorAgent rejects payload referencing unknown claim ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validOutlinePayload();
    $payload['sections'][0]['claim_ids'] = ['c1', 'c99'];

    $agent = new StubbedEditorAgent($payload);
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('c99');
});

test('EditorAgent rejects fewer than 2 sections', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validOutlinePayload();
    $payload['sections'] = [$payload['sections'][0]];

    $agent = new StubbedEditorAgent($payload);
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('at least 2 sections');
});

test('EditorAgent rejects section with no claim_ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validOutlinePayload();
    $payload['sections'][1]['claim_ids'] = [];

    $agent = new StubbedEditorAgent($payload);
    $result = $agent->execute(briefWithResearch(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('at least one claim_id');
});

test('EditorAgent returns error when brief has no research', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedEditorAgent(validOutlinePayload());
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('research');
});
