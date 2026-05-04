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
    expect($prompt)->toContain('past-topics');
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

test('topics prompt formats user scores and treats null as not yet rated', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Highly rated topic',
        'angle' => 'Angle',
        'status' => 'available',
        'score' => 8,
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Unrated topic',
        'angle' => 'Angle',
        'status' => 'available',
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Poorly rated topic',
        'angle' => 'Angle',
        'status' => 'available',
        'score' => 2,
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Your past topics for this team (most recent 25)');
    expect($prompt)->toContain('score: 8/10');
    expect($prompt)->toContain('score: not yet rated');
    expect($prompt)->toContain('score: 2/10');
});

test('topics prompt caps the past-topics list at 25 items, newest first', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $base = now()->subHours(40);
    for ($i = 0; $i < 30; $i++) {
        $topic = Topic::create([
            'team_id' => $team->id,
            'title' => "Topic {$i}",
            'angle' => 'Angle',
            'status' => 'available',
        ]);
        $topic->created_at = $base->copy()->addHours($i);
        $topic->save();
    }

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Topic 29');
    expect($prompt)->toContain('Topic 5');
    expect($prompt)->not->toContain('Topic 4');
});

test('topics prompt past-topics block excludes deleted topics', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Topic::create([
        'team_id' => $team->id,
        'title' => 'Kept topic',
        'angle' => 'Angle',
        'status' => 'available',
    ]);
    Topic::create([
        'team_id' => $team->id,
        'title' => 'Removed topic',
        'angle' => 'Angle',
        'status' => 'deleted',
    ]);

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->toContain('Kept topic');
    expect($prompt)->not->toContain('Removed topic');
});

test('topics prompt omits the past-topics block on an empty backlog', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $prompt = ChatPromptBuilder::build('topics', $team);

    expect($prompt)->not->toContain('Your past topics for this team');
    expect($prompt)->not->toContain('past-topics');
});
