<?php

use App\Models\Topic;
use App\Models\User;
use App\Services\ChatPromptBuilder;

test('topics type returns save_topics and fetch_url tools', function () {
    $tools = ChatPromptBuilder::tools('topics');

    $names = collect($tools)->map(fn ($t) => $t['function']['name'])->toArray();
    expect($names)->toContain('save_topics');
    expect($names)->toContain('fetch_url');
});

test('topics prompt includes tool instructions', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('save_topics');
    expect($prompt)->toContain('fetch_url');
    expect($prompt)->toContain('web search');
});

test('topics prompt includes existing backlog titles', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Existing Topic About Privacy',
        'angle' => 'An angle',
        'status' => 'available',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Existing Topic About Privacy');
    expect($prompt)->toContain('existing-topics');
});

test('topics prompt excludes deleted topics from backlog', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Available Topic',
        'angle' => 'Angle',
        'status' => 'available',
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Deleted Topic',
        'angle' => 'Angle',
        'status' => 'deleted',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Available Topic');
    expect($prompt)->not->toContain('Deleted Topic');
});

test('topics prompt still nudges when profile is thin', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('brand knowledge');
});
