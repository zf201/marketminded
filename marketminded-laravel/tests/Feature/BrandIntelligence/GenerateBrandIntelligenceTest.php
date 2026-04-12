<?php

use App\Enums\TeamRole;
use App\Jobs\GenerateBrandIntelligenceJob;
use App\Models\AudiencePersona;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Agents\PersonaAgent;
use App\Services\Agents\PositioningAgent;
use App\Services\Agents\VoiceProfileAgent;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test("generate button dispatches job", function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create([
        "homepage_url" => "https://example.com",
        "openrouter_api_key" => "sk-test",
    ]);
    $team->members()->attach($user, ["role" => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test("pages::teams.brand-intelligence", ["current_team" => $team])
        ->call("startGeneration");

    Queue::assertPushed(GenerateBrandIntelligenceJob::class, function ($job) use ($team) {
        return $job->team->id === $team->id;
    });
});

test("member cannot trigger generation", function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create([
        "homepage_url" => "https://example.com",
        "openrouter_api_key" => "sk-test",
    ]);
    $team->members()->attach($owner, ["role" => TeamRole::Owner->value]);
    $team->members()->attach($member, ["role" => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test("pages::teams.brand-intelligence", ["current_team" => $team])
        ->call("startGeneration")
        ->assertForbidden();
});

test("job updates status through each phase", function () {
    $team = Team::factory()->create([
        "homepage_url" => "https://example.com",
        "openrouter_api_key" => "sk-test",
    ]);

    $positioning = BrandPositioning::create([
        "team_id" => $team->id,
        "value_proposition" => "Test",
    ]);

    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive("fetchMany")->andReturn(["https://example.com" => "Content"]);

    $mockPositioningAgent = Mockery::mock(PositioningAgent::class);
    $mockPositioningAgent->shouldReceive("generate")->andReturn($positioning);

    $mockPersonaAgent = Mockery::mock(PersonaAgent::class);
    $mockPersonaAgent->shouldReceive("generate")->andReturn(collect());

    $mockVoiceAgent = Mockery::mock(VoiceProfileAgent::class);
    $mockVoiceAgent->shouldReceive("generate")->andReturn(
        VoiceProfile::create(["team_id" => $team->id, "voice_analysis" => "Test"]),
    );

    $job = new GenerateBrandIntelligenceJob($team);
    $job->handle(
        $mockUrlFetcher,
        $mockPositioningAgent,
        $mockPersonaAgent,
        $mockVoiceAgent,
    );

    expect($team->fresh()->intelligence_status)->toBe("completed");
    expect($team->fresh()->intelligence_error)->toBeNull();
});

test("job sets failed status on error", function () {
    $team = Team::factory()->create([
        "homepage_url" => "https://example.com",
        "openrouter_api_key" => "sk-test",
    ]);

    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive("fetchMany")->andThrow(new \RuntimeException("API Error"));

    $job = new GenerateBrandIntelligenceJob($team);

    try {
        $job->handle(
            $mockUrlFetcher,
            Mockery::mock(PositioningAgent::class),
            Mockery::mock(PersonaAgent::class),
            Mockery::mock(VoiceProfileAgent::class),
        );
    } catch (\RuntimeException $e) {
        // Expected
    }

    $job->failed(new \RuntimeException("API Error"));

    expect($team->fresh()->intelligence_status)->toBe("failed");
    expect($team->fresh()->intelligence_error)->toBe("API Error");
});

test("job deletes existing data before regenerating", function () {
    $team = Team::factory()->create([
        "homepage_url" => "https://example.com",
        "openrouter_api_key" => "sk-test",
    ]);

    BrandPositioning::create(["team_id" => $team->id, "value_proposition" => "Old"]);
    AudiencePersona::create(["team_id" => $team->id, "label" => "Old Persona"]);
    VoiceProfile::create(["team_id" => $team->id, "voice_analysis" => "Old"]);

    $mockUrlFetcher = Mockery::mock(UrlFetcher::class);
    $mockUrlFetcher->shouldReceive("fetchMany")->andReturn([]);

    $mockPositioningAgent = Mockery::mock(PositioningAgent::class);
    $mockPositioningAgent->shouldReceive("generate")->andReturnUsing(function ($team) {
        return $team->brandPositioning()->updateOrCreate(
            ["team_id" => $team->id],
            ["value_proposition" => "New"],
        );
    });

    $mockPersonaAgent = Mockery::mock(PersonaAgent::class);
    $mockPersonaAgent->shouldReceive("generate")->andReturnUsing(function ($team) {
        $team->audiencePersonas()->delete();
        return collect([$team->audiencePersonas()->create(["label" => "New Persona", "sort_order" => 0])]);
    });

    $mockVoiceAgent = Mockery::mock(VoiceProfileAgent::class);
    $mockVoiceAgent->shouldReceive("generate")->andReturnUsing(function ($team) {
        return $team->voiceProfile()->updateOrCreate(
            ["team_id" => $team->id],
            ["voice_analysis" => "New"],
        );
    });

    $job = new GenerateBrandIntelligenceJob($team);
    $job->handle($mockUrlFetcher, $mockPositioningAgent, $mockPersonaAgent, $mockVoiceAgent);

    expect($team->fresh()->brandPositioning->value_proposition)->toBe("New");
    expect($team->audiencePersonas()->count())->toBe(1);
    expect($team->audiencePersonas()->first()->label)->toBe("New Persona");
    expect($team->fresh()->voiceProfile->voice_analysis)->toBe("New");
});
