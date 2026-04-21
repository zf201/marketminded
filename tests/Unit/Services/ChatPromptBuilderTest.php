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

test('topics type prompt includes brand profile and tool instructions', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com', 'brand_description' => 'We do stuff']);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('We do stuff');
    expect($prompt)->toContain('save_topics');
});

test('write type returns default fallback prompt', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('write', $team);

    expect($prompt)->toBe('You are a helpful AI assistant.');
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

test('returns save_topics and fetch_url tools for topics type', function () {
    $tools = ChatPromptBuilder::tools('topics');

    $names = collect($tools)->map(fn ($t) => $t['function']['name'])->toArray();
    expect($names)->toContain('save_topics');
    expect($names)->toContain('fetch_url');
});

test('returns no custom tools for write type', function () {
    $tools = ChatPromptBuilder::tools('write');
    expect($tools)->toBeEmpty();
});
