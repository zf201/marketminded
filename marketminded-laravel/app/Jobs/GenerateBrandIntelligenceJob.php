<?php

namespace App\Jobs;

use App\Models\AiTask;
use App\Models\Team;
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\OpenRouterClient;
use App\Services\UrlFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateBrandIntelligenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public Team $team,
        public AiTask $aiTask,
    ) {}

    public function uniqueId(): string
    {
        return "team:{$this->team->id}";
    }

    public function handle(?UrlFetcher $urlFetcher = null): void
    {
        $team = $this->team;
        $aiTask = $this->aiTask;
        $urlFetcher ??= app(UrlFetcher::class);

        $client = new OpenRouterClient(
            apiKey: $team->openrouter_api_key,
            model: $team->powerful_model,
            urlFetcher: $urlFetcher,
        );

        $aiTask->markRunning();

        $steps = $aiTask->steps()->orderBy('id')->get();
        $fetchStep = $steps->firstWhere('name', 'fetching');
        $positioningStep = $steps->firstWhere('name', 'positioning');
        $personasStep = $steps->firstWhere('name', 'personas');
        $voiceStep = $steps->firstWhere('name', 'voice_profile');

        // Step 1: Fetch URLs
        $aiTask->update(['current_step' => 'fetching']);
        $fetchStep?->markRunning($team->powerful_model);

        $urlsToFetch = array_merge(
            [$team->homepage_url],
            $team->product_urls ?? [],
            array_filter([$team->blog_url]),
            $team->style_reference_urls ?? [],
        );

        $fetchedContent = $urlFetcher->fetchMany($urlsToFetch);
        $fetchStep?->markCompleted(['iterations' => count($fetchedContent)]);
        $aiTask->update(['completed_steps' => 1]);

        if ($aiTask->fresh()->isCancelled()) {
            return;
        }

        // Step 2: Positioning
        $aiTask->update(['current_step' => 'positioning']);
        $positioning = (new PositioningAgent($client))->generate($team, $fetchedContent, $positioningStep);
        $aiTask->update(['completed_steps' => 2]);

        if ($aiTask->fresh()->isCancelled()) {
            return;
        }

        // Step 3: Personas
        $aiTask->update(['current_step' => 'personas']);
        (new PersonaAgent($client))->generate($team, $positioning, $fetchedContent, $personasStep);
        $aiTask->update(['completed_steps' => 3]);

        if ($aiTask->fresh()->isCancelled()) {
            return;
        }

        // Step 4: Voice Profile
        $aiTask->update(['current_step' => 'voice_profile']);
        (new VoiceProfileAgent($client))->generate($team, $positioning, $fetchedContent, $voiceStep);
        $aiTask->update(['completed_steps' => 4]);

        $aiTask->markCompleted();
    }

    public function failed(?\Throwable $exception): void
    {
        $this->aiTask->markFailed($exception?->getMessage() ?? 'Unknown error');
    }
}
