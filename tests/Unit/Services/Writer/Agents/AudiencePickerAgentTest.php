<?php

use App\Models\AudiencePersona;
use App\Models\Team;
use App\Models\User;
use App\Services\Writer\Agents\AudiencePickerAgent;
use App\Services\Writer\Brief;

class StubbedAudiencePickerAgent extends AudiencePickerAgent
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

function briefWithResearchForAudience(): Brief
{
    return Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'Knife maintenance', 'angle' => 'Professional kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 'Summary about knife care for professionals.',
            'claims' => [
                ['id' => 'c1', 'text' => 'Honing extends edge life by 3x.', 'type' => 'fact', 'source_ids' => ['s1']],
            ],
            'sources' => [['id' => 's1', 'url' => 'https://example.com', 'title' => 'Knife Guide']],
        ],
    ]);
}

function teamWithPersonas(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $persona = AudiencePersona::create([
        'team_id' => $team->id,
        'label' => 'Pro Chef',
        'description' => 'Works in commercial kitchens daily.',
        'pain_points' => 'Cheap tools that break under heavy use.',
        'push' => 'Needs reliable equipment.',
        'pull' => 'Wants professional-grade results.',
        'anxiety' => 'Wasting money on low-quality knives.',
        'role' => 'Executive Chef',
        'sort_order' => 1,
    ]);
    return [$team, $persona];
}

test('AudiencePickerAgent ok path mode=persona: hydrates label and summary, writes to brief', function () {
    [$team, $persona] = teamWithPersonas();

    $payload = [
        'mode' => 'persona',
        'persona_id' => $persona->id,
        'reasoning' => 'Topic clearly targets professional kitchen users.',
        'guidance_for_writer' => 'Assume daily professional use. Skip beginner explanations.',
    ];

    $agent = new StubbedAudiencePickerAgent($payload);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasAudience())->toBeTrue();

    $audience = $result->brief->audience();
    expect($audience['mode'])->toBe('persona');
    expect($audience['persona_id'])->toBe($persona->id);
    expect($audience['persona_label'])->toBe('Pro Chef');
    expect($audience['persona_summary'])->toContain('commercial kitchens');
    expect($audience['guidance_for_writer'])->toContain('beginner');

    expect($result->cardPayload['kind'])->toBe('audience');
    expect($result->summary)->toContain('persona');
});

test('AudiencePickerAgent ok path mode=educational: no persona_id in brief', function () {
    [$team] = teamWithPersonas();

    $payload = [
        'mode' => 'educational',
        'reasoning' => 'Topic is informational, no persona fits well.',
        'guidance_for_writer' => 'Write for a curious reader with no professional background.',
    ];

    $agent = new StubbedAudiencePickerAgent($payload);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
    $audience = $result->brief->audience();
    expect($audience['mode'])->toBe('educational');
    expect(isset($audience['persona_id']))->toBeFalse();
    expect($result->summary)->toContain('educational');
});

test('AudiencePickerAgent ok path mode=commentary: no persona_id in brief', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'commentary',
        'reasoning' => 'Opinion piece.',
        'guidance_for_writer' => 'Write for an informed reader.',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->audience()['mode'])->toBe('commentary');
    expect($result->summary)->toContain('commentary');
});

test('AudiencePickerAgent accepts persona_id=0 on educational mode', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'educational',
        'persona_id' => 0,
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
});

test('AudiencePickerAgent rejects missing persona_id on persona mode', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'persona',
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('persona_id');
});

test('AudiencePickerAgent rejects empty guidance_for_writer', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'educational',
        'reasoning' => 'r',
        'guidance_for_writer' => '   ',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('guidance_for_writer');
});

test('AudiencePickerAgent returns error when persona_id not found for team', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'persona',
        'persona_id' => 99999, // non-existent
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('99999');
});

test('AudiencePickerAgent returns error when brief has no research', function () {
    [$team] = teamWithPersonas();
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedAudiencePickerAgent(['mode' => 'educational', 'reasoning' => 'r', 'guidance_for_writer' => 'g']);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('research');
});
