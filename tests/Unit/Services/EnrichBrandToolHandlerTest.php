<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\EnrichBrandToolHandler;
use App\Services\Writer\Agents\BrandEnricherAgent;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Brief;

class FakeBrandEnricherAgent extends BrandEnricherAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function enricherConv(array $briefData = []): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $defaultBrief = [
        'topic' => ['title' => 'X', 'angle' => 'a', 'sources' => []],
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
        'brief' => array_merge($defaultBrief, $briefData),
    ]);
    return [$team, $conversation];
}

test('handler refuses to run before research is in the brief', function () {
    [$team, $conversation] = enricherConv(['research' => null]);
    $team->update(['homepage_url' => 'https://abmobil.si']);
    // Drop research from brief by overwriting cleanly.
    $brief = $conversation->brief;
    unset($brief['research']);
    $conversation->update(['brief' => $brief]);

    $handler = new EnrichBrandToolHandler(new FakeBrandEnricherAgent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research');
});

test('handler reports skipped when team has no brand URLs', function () {
    [$team, $conversation] = enricherConv();

    $handler = new EnrichBrandToolHandler(new FakeBrandEnricherAgent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('skipped');
    expect($decoded['message'])->toContain('No homepage');
});

test('handler reports skipped when agent returns zero claims', function () {
    [$team, $conversation] = enricherConv();
    $team->update(['homepage_url' => 'https://abmobil.si']);

    $brief = Brief::fromJson($conversation->brief)->withBrandClaims(['claims' => [], 'sources' => []]);
    $agent = new FakeBrandEnricherAgent;
    $agent->stubResult = AgentResult::ok($brief, ['kind' => 'brand_claims'], 'No brand pages...');

    $handler = new EnrichBrandToolHandler($agent);
    $result = $handler->execute($team->refresh(), $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('skipped');
});

test('handler returns ok and persists brand_claims when agent succeeds', function () {
    [$team, $conversation] = enricherConv();
    $team->update(['homepage_url' => 'https://abmobil.si']);

    $brief = Brief::fromJson($conversation->brief)->withBrandClaims([
        'claims' => [['id' => 'bc1', 'text' => 'X', 'type' => 'offer', 'source_ids' => ['b1']]],
        'sources' => [['id' => 'b1', 'url' => 'https://abmobil.si', 'title' => 'T']],
    ]);
    $agent = new FakeBrandEnricherAgent;
    $agent->stubResult = AgentResult::ok($brief, ['kind' => 'brand_claims'], 'Extracted 1 brand claims from 1 brand sources');

    $handler = new EnrichBrandToolHandler($agent);
    $result = $handler->execute($team->refresh(), $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('1 brand claims');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasBrandClaims())->toBeTrue();
});

test('handler refuses second call (retry guard) in same turn', function () {
    [$team, $conversation] = enricherConv();
    $team->update(['homepage_url' => 'https://abmobil.si']);

    $handler = new EnrichBrandToolHandler(new FakeBrandEnricherAgent);
    $priorTurnTools = [['name' => 'enrich_brand_context', 'args' => [], 'status' => 'ok']];

    $result = $handler->execute($team->refresh(), $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('Already ran');
});

test('toolSchema returns valid schema', function () {
    $schema = EnrichBrandToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('enrich_brand_context');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
