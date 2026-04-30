<?php

use App\Models\User;
use App\Services\Writer\Agents\StyleReferenceAgent;
use App\Services\Writer\Brief;

class StubbedStyleReferenceAgent extends StyleReferenceAgent
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

function briefWithOutlineForStyle(): Brief
{
    return Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'Knife maintenance', 'angle' => 'Professional kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 'Summary.',
            'claims' => [['id' => 'c1', 'text' => 'Fact.', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
        'outline' => [
            'angle' => 'Professional maintenance',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ],
    ]);
}

function validStylePayload(): array
{
    return [
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'Post One', 'why_chosen' => 'Strong hook, short paragraphs.'],
            ['url' => 'https://brand.com/post-2', 'title' => 'Post Two', 'why_chosen' => 'Direct voice, benefit-first.'],
        ],
        'reasoning' => 'These best represent the brand voice.',
    ];
}

test('StyleReferenceAgent ok path: stores body-less examples in brief', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $agent = new StubbedStyleReferenceAgent(validStylePayload());
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasStyleReference())->toBeTrue();

    $ref = $result->brief->styleReference();
    expect($ref['examples'])->toHaveCount(2);
    expect($ref['examples'][0]['url'])->toBe('https://brand.com/post-1');
    expect($ref['examples'][0]['body'])->toBe('');  // bodies fetched by handler
    expect($result->cardPayload['kind'])->toBe('style_reference');
    expect($result->summary)->toContain('2 example');
});

test('StyleReferenceAgent accepts 3 examples', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'][] = ['url' => 'https://brand.com/post-3', 'title' => 'Post Three', 'why_chosen' => 'Great rhythm.'];

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->styleReference()['examples'])->toHaveCount(3);
});

test('StyleReferenceAgent rejects fewer than 2 examples', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'] = [$payload['examples'][0]];

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('2');
});

test('StyleReferenceAgent rejects example with missing url', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'][0]['url'] = '';

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('url');
});

test('StyleReferenceAgent rejects example with missing why_chosen', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'][1]['why_chosen'] = '';

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('why_chosen');
});

test('StyleReferenceAgent returns error when brief has no outline', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedStyleReferenceAgent(validStylePayload());
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('outline');
});
