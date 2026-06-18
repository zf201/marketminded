<?php

use App\Models\Team;
use App\Services\ChatPromptBuilder;

it('builds a planner prompt with required sections', function () {
    $team = Team::factory()->create([
        'content_language' => 'sl',
    ]);

    $prompt = ChatPromptBuilder::build('planner', $team);

    expect($prompt)->toContain('content calendar');
    expect($prompt)->toContain('propose_entries');
    expect($prompt)->toContain('update_entry');
    expect($prompt)->toContain('move_entry');
    expect($prompt)->toContain('mark_used');
    expect($prompt)->toContain('<brand-profile>');
});

it('injects the calendar steer into the planner brand profile', function () {
    $team = Team::factory()->create([
        'calendar_steer' => 'Lead with value; sell rarely.',
    ]);

    $prompt = ChatPromptBuilder::build('planner', $team);

    expect($prompt)->toContain('Content guidance');
    expect($prompt)->toContain('Lead with value; sell rarely.');
});

it('lists planner tools', function () {
    $tools = ChatPromptBuilder::tools('planner');
    $names = collect($tools)->map(fn ($t) => $t['function']['name'])->all();

    expect($names)->toContain('propose_entries');
    expect($names)->toContain('update_entry');
    expect($names)->toContain('move_entry');
    expect($names)->toContain('mark_used');
    expect($names)->toContain('list_available_pool');
    expect($names)->not->toContain('delete_entry');
});
