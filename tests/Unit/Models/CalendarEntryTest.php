<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\Topic;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->calendar = ContentCalendar::create(['team_id' => $this->team->id]);
});

it('flips source topic to used when set on create', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => false]);

    CalendarEntry::create([
        'calendar_id' => $this->calendar->id,
        'team_id' => $this->team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Idea',
        'source_topic_id' => $topic->id,
    ]);

    expect($topic->fresh()->used)->toBeTrue();
});

it('does not unmark source when entry is deleted', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => false]);

    $entry = CalendarEntry::create([
        'calendar_id' => $this->calendar->id,
        'team_id' => $this->team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Idea',
        'source_topic_id' => $topic->id,
    ]);
    $entry->delete();

    expect($topic->fresh()->used)->toBeTrue();
});
