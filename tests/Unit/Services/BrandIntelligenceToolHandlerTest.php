<?php

use App\Models\Team;
use App\Models\User;
use App\Services\BrandIntelligenceToolHandler;

test('updates team setup fields', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $result = $handler->execute($team, [
        'setup' => [
            'homepage_url' => 'https://example.com',
            'brand_description' => 'We do stuff',
        ],
    ]);

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->brand_description)->toBe('We do stuff');
    expect($result)->toContain('setup');
});

test('creates positioning via updateOrCreate', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $handler->execute($team, [
        'positioning' => [
            'value_proposition' => 'We make things better',
            'target_market' => 'Everyone',
        ],
    ]);

    $positioning = $team->brandPositioning;
    expect($positioning)->not->toBeNull();
    expect($positioning->value_proposition)->toBe('We make things better');
    expect($positioning->target_market)->toBe('Everyone');
});

test('replaces all personas when personas key is present', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $team->audiencePersonas()->create(['label' => 'Old Persona', 'sort_order' => 0]);
    expect($team->audiencePersonas()->count())->toBe(1);

    $handler = new BrandIntelligenceToolHandler;
    $handler->execute($team, [
        'personas' => [
            ['label' => 'New Persona 1', 'role' => 'CTO'],
            ['label' => 'New Persona 2', 'role' => 'CEO'],
        ],
    ]);

    $personas = $team->audiencePersonas()->get();
    expect($personas)->toHaveCount(2);
    expect($personas[0]->label)->toBe('New Persona 1');
    expect($personas[1]->label)->toBe('New Persona 2');
    expect($personas[0]->sort_order)->toBe(0);
    expect($personas[1]->sort_order)->toBe(1);
});

test('creates voice profile via updateOrCreate', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $handler->execute($team, [
        'voice' => [
            'voice_analysis' => 'Professional and warm',
            'preferred_length' => 1200,
        ],
    ]);

    $voice = $team->voiceProfile;
    expect($voice)->not->toBeNull();
    expect($voice->voice_analysis)->toBe('Professional and warm');
    expect($voice->preferred_length)->toBe(1200);
});

test('handles multiple sections in one call', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $result = $handler->execute($team, [
        'setup' => ['homepage_url' => 'https://example.com'],
        'positioning' => ['value_proposition' => 'Best in class'],
    ]);

    expect($result)->toContain('setup');
    expect($result)->toContain('positioning');
    expect($team->fresh()->homepage_url)->toBe('https://example.com');
    expect($team->brandPositioning->value_proposition)->toBe('Best in class');
});

test('returns JSON with saved sections', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $handler = new BrandIntelligenceToolHandler;
    $result = $handler->execute($team, [
        'setup' => ['homepage_url' => 'https://example.com'],
    ]);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('saved');
    expect($decoded['sections'])->toBe(['setup']);
});
