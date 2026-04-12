<?php

use App\Enums\TeamRole;
use App\Jobs\GenerateBrandIntelligenceJob;
use App\Models\AiTask;
use App\Models\AiTaskStep;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProfile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function createAiTask(Team $team): AiTask
{
    $task = AiTask::create([
        'team_id' => $team->id,
        'type' => 'brand_intelligence',
        'label' => 'Generate Brand Intelligence',
        'status' => 'pending',
        'total_steps' => 4,
    ]);

    $task->steps()->createMany([
        ['name' => 'fetching', 'label' => 'Fetching URLs'],
        ['name' => 'positioning', 'label' => 'Analyzing positioning'],
        ['name' => 'personas', 'label' => 'Building personas'],
        ['name' => 'voice_profile', 'label' => 'Defining voice profile'],
    ]);

    return $task;
}

test('job sets failed status on error', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $aiTask = createAiTask($team);
    $job = new GenerateBrandIntelligenceJob($team, $aiTask);
    $job->failed(new \RuntimeException('API Error'));

    expect($aiTask->fresh()->status)->toBe('failed');
    expect($aiTask->fresh()->error)->toBe('API Error');
});

test('cancelled task marks pending steps as skipped', function () {
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'openrouter_api_key' => 'sk-test',
    ]);

    $aiTask = createAiTask($team);
    $aiTask->markCancelled();

    expect($aiTask->fresh()->status)->toBe('cancelled');
    expect($aiTask->steps()->where('status', 'skipped')->count())->toBe(4);
});

test('ai task tracks totals from steps', function () {
    $team = Team::factory()->create();
    $aiTask = createAiTask($team);

    $steps = $aiTask->steps;
    $steps[0]->markCompleted(['input_tokens' => 100, 'output_tokens' => 50, 'cost' => 0.001, 'iterations' => 1]);
    $steps[1]->markCompleted(['input_tokens' => 200, 'output_tokens' => 100, 'cost' => 0.002, 'iterations' => 3]);

    $aiTask->markCompleted();

    expect($aiTask->fresh()->total_tokens)->toBe(450);
    expect((float) $aiTask->fresh()->total_cost)->toEqual(0.003);
});

test('ai task step records model and timestamps', function () {
    $team = Team::factory()->create();
    $aiTask = createAiTask($team);

    $step = $aiTask->steps->first();
    $step->markRunning('deepseek/deepseek-v3.2:nitro');

    expect($step->fresh()->status)->toBe('running');
    expect($step->fresh()->model)->toBe('deepseek/deepseek-v3.2:nitro');
    expect($step->fresh()->started_at)->not->toBeNull();

    $step->markCompleted(['input_tokens' => 500, 'output_tokens' => 200, 'cost' => 0.003, 'iterations' => 2]);

    expect($step->fresh()->status)->toBe('completed');
    expect($step->fresh()->input_tokens)->toBe(500);
    expect($step->fresh()->completed_at)->not->toBeNull();
});
