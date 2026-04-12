<?php

namespace App\Jobs;

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

    public function __construct(public Team $team) {}

    public function uniqueId(): string
    {
        return "team:{$this->team->id}";
    }

    public function handle(
        ?UrlFetcher $urlFetcher = null,
        ?PositioningAgent $positioningAgent = null,
        ?PersonaAgent $personaAgent = null,
        ?VoiceProfileAgent $voiceProfileAgent = null,
    ): void {
        $team = $this->team;
        $urlFetcher ??= app(UrlFetcher::class);

        if (! $positioningAgent || ! $personaAgent || ! $voiceProfileAgent) {
            $client = new OpenRouterClient(
                apiKey: $team->openrouter_api_key,
                model: $team->powerful_model,
                urlFetcher: $urlFetcher,
            );

            $positioningAgent ??= new PositioningAgent($client);
            $personaAgent ??= new PersonaAgent($client);
            $voiceProfileAgent ??= new VoiceProfileAgent($client);
        }

        $team->update(["intelligence_status" => "fetching", "intelligence_error" => null]);

        $urlsToFetch = array_merge(
            [$team->homepage_url],
            $team->product_urls ?? [],
            array_filter([$team->blog_url]),
            $team->style_reference_urls ?? [],
        );

        $fetchedContent = $urlFetcher->fetchMany($urlsToFetch);

        $team->update(["intelligence_status" => "positioning"]);
        $positioning = $positioningAgent->generate($team, $fetchedContent);

        $team->update(["intelligence_status" => "personas"]);
        $personaAgent->generate($team, $positioning, $fetchedContent);

        $team->update(["intelligence_status" => "voice_profile"]);
        $voiceProfileAgent->generate($team, $positioning, $fetchedContent);

        $team->update(["intelligence_status" => "completed"]);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->team->update([
            "intelligence_status" => "failed",
            "intelligence_error" => $exception?->getMessage(),
        ]);
    }
}
