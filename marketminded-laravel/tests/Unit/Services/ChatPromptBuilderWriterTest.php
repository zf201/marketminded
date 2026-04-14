<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\ChatPromptBuilder;

function writerContext(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy-first positioning',
        'sources' => ['Source A'],
        'status' => 'available',
    ]);

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'writer_mode' => 'autopilot',
    ]);

    return [$team, $conversation, $topic];
}

test('writer prompt includes topic, mode, and tool-order rule', function () {
    [$team, $conversation] = writerContext();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('research_topic');
    expect($prompt)->toContain('create_outline');
    expect($prompt)->toContain('write_blog_post');
    expect($prompt)->toContain('<topic>');
    expect($prompt)->toContain('Zero Party Data');
    expect($prompt)->toContain('<mode>autopilot</mode>');
});

test('checkpoint mode is reflected in prompt', function () {
    [$team, $conversation] = writerContext();
    $conversation->update(['writer_mode' => 'checkpoint']);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation->refresh());

    expect($prompt)->toContain('<mode>checkpoint</mode>');
    expect($prompt)->toContain('Pause');
});

test('current content piece is included when present', function () {
    [$team, $conversation] = writerContext();

    $piece = ContentPiece::create([
        'team_id' => $team->id,
        'conversation_id' => $conversation->id,
        'title' => 'Draft title',
        'body' => 'Draft body',
        'current_version' => 1,
    ]);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('<current-content-piece>');
    expect($prompt)->toContain('Draft title');
    expect($prompt)->toContain((string) $piece->id);
});

test('tools(writer) returns the four writer tools plus fetch_url', function () {
    $tools = ChatPromptBuilder::tools('writer');
    $names = collect($tools)->pluck('function.name')->all();

    expect($names)->toContain('research_topic');
    expect($names)->toContain('create_outline');
    expect($names)->toContain('write_blog_post');
    expect($names)->toContain('update_blog_post');
    expect($names)->toContain('fetch_url');
});

test('write type is removed', function () {
    $tools = ChatPromptBuilder::tools('write');
    expect($tools)->toBe([]);
});
