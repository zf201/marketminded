<?php

use App\Models\ContentCalendar;
use App\Models\Team;

it('belongs to a team and casts posting_days as array', function () {
    $team = Team::factory()->create();
    $cal = ContentCalendar::create([
        'team_id' => $team->id,
        'posting_days' => ['mon', 'wed', 'fri'],
    ]);

    expect($cal->fresh()->posting_days)->toBe(['mon', 'wed', 'fri']);
    expect($cal->team->id)->toBe($team->id);
});
