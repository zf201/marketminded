<?php

use App\Models\User;
use App\Services\Writer\Agents\BrandEnricherAgent;
use App\Services\Writer\Brief;

class StubbedBrandEnricherAgent extends BrandEnricherAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $systemPrompt, array $tools, string $model, float $temperature, bool $useServerTools, ?string $apiKey, int $timeout = 120, string $baseUrl = 'https://openrouter.ai/api/v1', string $provider = 'openrouter', ?\App\Services\BraveSearchClient $braveSearchClient = null, ?callable $onToolCall = null, string $reasoningEffort = 'medium'): ?array
    {
        return $this->stubPayload;
    }
}

function enricherBrief(): Brief
{
    return Brief::fromJson([
        'topic' => ['title' => 'Operativni najem', 'angle' => 'pros and cons', 'sources' => []],
        'research' => [
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'https://example.com', 'title' => 'T']],
        ],
    ]);
}

function validBrandClaimsPayload(): array
{
    return [
        'claims' => [
            ['id' => 'bc1', 'text' => 'ABmobil offers a PRIME plan.', 'type' => 'offer', 'source_ids' => ['b1']],
            ['id' => 'bc2', 'text' => 'FLEX starts at €99/mo.', 'type' => 'price', 'source_ids' => ['b1']],
        ],
        'sources' => [
            ['id' => 'b1', 'url' => 'https://abmobil.si', 'title' => 'ABmobil'],
        ],
    ];
}

test('BrandEnricherAgent ok path writes brand_claims to brief', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://abmobil.si']);

    $agent = new StubbedBrandEnricherAgent(validBrandClaimsPayload());
    $result = $agent->execute(enricherBrief(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasBrandClaims())->toBeTrue();
    expect($result->brief->brandClaims()['claims'])->toHaveCount(2);
    expect($result->summary)->toContain('2 brand claims');
});

test('BrandEnricherAgent accepts zero claims as valid (skip signal)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $agent = new StubbedBrandEnricherAgent(['claims' => [], 'sources' => []]);
    $result = $agent->execute(enricherBrief(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->brandClaims()['claims'])->toBe([]);
});

test('BrandEnricherAgent rejects claim citing unknown source', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validBrandClaimsPayload();
    $payload['claims'][0]['source_ids'] = ['b99'];

    $agent = new StubbedBrandEnricherAgent($payload);
    $result = $agent->execute(enricherBrief(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('unknown source');
});

test('BrandEnricherAgent rejects duplicate claim ids', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validBrandClaimsPayload();
    $payload['claims'][1]['id'] = 'bc1';

    $agent = new StubbedBrandEnricherAgent($payload);
    $result = $agent->execute(enricherBrief(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('duplicate claim ids');
});

test('BrandEnricherAgent hasBrandUrls reflects team config', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $agent = new BrandEnricherAgent;
    expect($agent->hasBrandUrls($team))->toBeFalse();

    $team->update(['homepage_url' => 'https://abmobil.si']);
    expect($agent->hasBrandUrls($team->refresh()))->toBeTrue();

    $team->update(['homepage_url' => null, 'product_urls' => ['https://abmobil.si/flex']]);
    expect($agent->hasBrandUrls($team->refresh()))->toBeTrue();
});
