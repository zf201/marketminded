<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\Topic;
use App\Services\CalendarEntryToolHandler;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->calendar = ContentCalendar::create(['team_id' => $this->team->id]);
});

it('propose creates entries and flips source used', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => false]);

    $result = CalendarEntryToolHandler::propose($this->team, $this->calendar, [
        'entries' => [
            [
                'scheduled_for' => '2026-05-06',
                'title' => 'Huawei headlights',
                'linkedin_copy' => 'Long LI body...',
                'source_topic_id' => $topic->id,
            ],
            [
                'scheduled_for' => '2026-05-08',
                'title' => 'Tesla Y prime',
                'linkedin_copy' => 'Another...',
            ],
        ],
    ]);

    expect($result['status'])->toBe('ok');
    expect(CalendarEntry::where('calendar_id', $this->calendar->id)->count())->toBe(2);
    expect($topic->fresh()->used)->toBeTrue();
});

it('propose enforces team scoping on source ids', function () {
    $otherTeam = Team::factory()->create();
    $otherTopic = Topic::factory()->for($otherTeam)->create();

    $result = CalendarEntryToolHandler::propose($this->team, $this->calendar, [
        'entries' => [[
            'scheduled_for' => '2026-05-06',
            'title' => 'x',
            'source_topic_id' => $otherTopic->id,
        ]],
    ]);

    expect($result['status'])->toBe('error');
    expect(CalendarEntry::count())->toBe(0);
});

it('updates a single entry by id', function () {
    $entry = $this->calendar->entries()->create([
        'team_id' => $this->team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Old',
    ]);

    $result = CalendarEntryToolHandler::update($this->team, [
        'id' => $entry->id,
        'title' => 'New title',
        'linkedin_copy' => 'LI body',
    ]);

    expect($result['status'])->toBe('ok');
    expect($entry->fresh()->title)->toBe('New title');
    expect($entry->fresh()->linkedin_copy)->toBe('LI body');
});

it('refuses to update an entry from another team', function () {
    $otherTeam = Team::factory()->create();
    $otherCal = ContentCalendar::create(['team_id' => $otherTeam->id]);
    $entry = $otherCal->entries()->create([
        'team_id' => $otherTeam->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Old',
    ]);

    $result = CalendarEntryToolHandler::update($this->team, [
        'id' => $entry->id,
        'title' => 'Hacked',
    ]);

    expect($result['status'])->toBe('error');
    expect($entry->fresh()->title)->toBe('Old');
});

it('deletes an entry by id without unmarking source', function () {
    $topic = Topic::factory()->for($this->team)->create(['used' => true]);
    $entry = $this->calendar->entries()->create([
        'team_id' => $this->team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'x',
        'source_topic_id' => $topic->id,
    ]);

    $result = CalendarEntryToolHandler::delete($this->team, ['id' => $entry->id]);

    expect($result['status'])->toBe('ok');
    expect(CalendarEntry::find($entry->id))->toBeNull();
    expect($topic->fresh()->used)->toBeTrue();
});
