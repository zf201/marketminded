<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\Brief;

/**
 * Test subclass: overrides llmCall() to return a canned payload, so we can
 * test validate(), applyToBrief(), buildCard(), buildSummary() without HTTP.
 */
class StubbedResearchAgent extends ResearchAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $systemPrompt, array $tools, string $model, float $temperature, bool $useServerTools, ?string $apiKey, int $timeout = 120, string $baseUrl = 'https://openrouter.ai/api/v1', string $provider = 'openrouter'): ?array
    {
        return $this->stubPayload;
    }
}

function researchTopic(): array
{
    return ['id' => 1, 'title' => 'Zero Party Data', 'angle' => 'Privacy-first', 'sources' => []];
}

function validResearchPayload(): array
{
    return [
        'topic_summary' => 'Summary.',
        'claims' => [
            ['id' => 'c1', 'text' => 'Claim one.', 'type' => 'fact', 'source_ids' => ['s1']],
            ['id' => 'c2', 'text' => 'Claim two.', 'type' => 'stat', 'source_ids' => ['s2']],
            ['id' => 'c3', 'text' => 'Claim three.', 'type' => 'quote', 'source_ids' => ['s1']],
        ],
        'sources' => [
            ['id' => 's1', 'url' => 'https://a.example', 'title' => 'A'],
            ['id' => 's2', 'url' => 'https://b.example', 'title' => 'B'],
        ],
    ];
}

test('ResearchAgent ok path: validates, applies to brief, builds card and summary', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $agent = new StubbedResearchAgent(validResearchPayload());
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasResearch())->toBeTrue();
    expect($result->brief->research()['claims'])->toHaveCount(3);
    expect($result->summary)->toContain('3 claims');
    expect($result->summary)->toContain('2 sources');
    expect($result->cardPayload['summary'])->toContain('3 claims');
});

test('ResearchAgent rejects fewer than 3 claims', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $payload = validResearchPayload();
    $payload['claims'] = array_slice($payload['claims'], 0, 2);

    $agent = new StubbedResearchAgent($payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('at least 3 claims');
});

test('ResearchAgent rejects claim with missing source', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $payload = validResearchPayload();
    $payload['claims'][0]['source_ids'] = ['s99'];   // bogus

    $agent = new StubbedResearchAgent($payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('s99');
});

test('ResearchAgent rejects duplicate claim ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $payload = validResearchPayload();
    $payload['claims'][1]['id'] = 'c1';

    $agent = new StubbedResearchAgent($payload);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('duplicate');
});

test('ResearchAgent returns error if llmCall returns null (no submit tool)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $agent = new class extends ResearchAgent {
        protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120, string $baseUrl = 'https://openrouter.ai/api/v1', string $provider = 'openrouter'): ?array
        {
            return null;
        }
    };

    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('did not call the submit tool');
});

test('ResearchAgent system prompt includes topic and extraContext when provided', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => researchTopic()]);

    $agent = new class('Focus on quantitative data.') extends ResearchAgent {
        public function exposePrompt(Brief $b, Team $t): string
        {
            return $this->systemPrompt($b, $t);
        }
    };

    $prompt = $agent->exposePrompt($brief, $team);

    expect($prompt)->toContain('Zero Party Data');
    expect($prompt)->toContain('Privacy-first');
    expect($prompt)->toContain('submit_research');
    expect($prompt)->toContain('Focus on quantitative data.');
});
