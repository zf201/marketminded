<?php

use App\Models\ContentCalendar;
use App\Models\Team;

it('belongs to a team', function () {
    $team = Team::factory()->create();
    $cal = ContentCalendar::create(['team_id' => $team->id]);

    expect($cal->team->id)->toBe($team->id);
});
