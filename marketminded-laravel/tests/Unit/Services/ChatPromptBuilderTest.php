<?php

use App\Models\Team;
use App\Models\User;
use App\Services\ChatPromptBuilder;

test('brand type prompt includes tool instructions', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $prompt = ChatPromptBuilder::build('brand', $team);

    expect($prompt)->toContain('update_brand_intelligence');
    expect($prompt)->toContain('fetch_url');
    expect($prompt)->toContain('https://example.com');
});

test('topics type prompt includes brand profile', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com', 'brand_description' => 'We do stuff']);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('We do stuff');
    expect($prompt)->not->toContain('update_brand_intelligence');
});

test('write type prompt includes voice profile when available', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->voiceProfile()->create([
        'voice_analysis' => 'Professional and warm',
        'preferred_length' => 1500,
    ]);

    $prompt = ChatPromptBuilder::build('write', $team);

    expect($prompt)->toContain('Professional and warm');
});

test('topics type nudges when profile is thin', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('brand knowledge');
});

test('returns tools for brand type', function () {
    $tools = ChatPromptBuilder::tools('brand');

    $names = collect($tools)->map(fn ($t) => $t['function']['name'] ?? $t['type'] ?? '')->toArray();
    expect($names)->toContain('update_brand_intelligence');
    expect($names)->toContain('fetch_url');
});

test('returns no custom tools for topics type', function () {
    $tools = ChatPromptBuilder::tools('topics');
    expect($tools)->toBeEmpty();
});

test('returns no custom tools for write type', function () {
    $tools = ChatPromptBuilder::tools('write');
    expect($tools)->toBeEmpty();
});
