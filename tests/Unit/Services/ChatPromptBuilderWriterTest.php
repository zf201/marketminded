<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\ChatPromptBuilder;

function writerCtx(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['homepage_url' => 'https://example.com']);

    $topic = Topic::create(['team_id' => $team->id, 'title' => 'Zero Party Data', 'angle' => 'Privacy-first', 'sources' => ['Source A'], 'status' => 'available']);

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'Writer',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => ['topic' => ['id' => $topic->id, 'title' => 'Zero Party Data', 'angle' => 'Privacy-first', 'sources' => ['Source A']]],
    ]);

    return [$team, $conversation, $topic];
}

test('writer prompt includes tool list and brief-status block', function () {
    [$team, $conversation] = writerCtx();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('research_topic');
    expect($prompt)->toContain('pick_audience');
    expect($prompt)->toContain('create_outline');
    expect($prompt)->toContain('fetch_style_reference');
    expect($prompt)->toContain('write_blog_post');
    expect($prompt)->toContain('proofread_blog_post');
    expect($prompt)->toContain('<brief-status>');
    expect($prompt)->toContain('topic: ✓');
    expect($prompt)->toContain('research: ✗');
});

test('writer prompt is dramatically shorter than the old one', function () {
    [$team, $conversation] = writerCtx();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    // Old prompt was ~20000 chars. New target ~2500. Set a generous bound.
    expect(strlen($prompt))->toBeLessThan(5000);
});

test('writer prompt always autoruns the full pipeline', function () {
    [$team, $conversation] = writerCtx();

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation);

    expect($prompt)->toContain('Pipeline order');
    expect($prompt)->toContain('pick_audience');
    expect($prompt)->toContain('fetch_style_reference');
    expect($prompt)->not->toContain('checkpoint');
    expect($prompt)->not->toContain('Pause');
});

test('writer prompt brief-status reflects research and outline when present', function () {
    [$team, $conversation] = writerCtx();
    $conversation->update(['brief' => array_merge(
        $conversation->brief,
        [
            'research' => ['topic_summary' => 's', 'claims' => array_fill(0, 8, ['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]), 'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']]],
            'outline' => ['angle' => 'a', 'target_length_words' => 1500, 'sections' => [['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']], ['heading' => 'h', 'purpose' => 'p', 'claim_ids' => ['c1']]]],
        ]
    )]);

    $prompt = ChatPromptBuilder::build('writer', $team, $conversation->refresh());

    expect($prompt)->toContain('research: ✓');
    expect($prompt)->toContain('outline: ✓');
});

test('tools(writer) returns all 7 sub-agent tools', function () {
    $tools = ChatPromptBuilder::tools('writer');
    $names = collect($tools)->pluck('function.name')->all();

    expect($names)->toContain('research_topic');
    expect($names)->toContain('pick_audience');
    expect($names)->toContain('create_outline');
    expect($names)->toContain('fetch_style_reference');
    expect($names)->toContain('write_blog_post');
    expect($names)->toContain('proofread_blog_post');
    expect($names)->not->toContain('update_blog_post');
});
