<?php

use App\Models\Team;
use App\Models\Topic;
use App\Services\ListAvailablePoolToolHandler;

it('returns unused items for the team', function () {
    $team = Team::factory()->create();
    Topic::factory()->for($team)->create(['used' => false, 'title' => 'Free']);
    Topic::factory()->for($team)->create(['used' => true, 'title' => 'Taken']);

    $result = ListAvailablePoolToolHandler::run($team);

    expect($result['status'])->toBe('ok');
    expect(collect($result['topics'])->pluck('title')->all())->toBe(['Free']);
});
