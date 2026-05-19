<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('exports the visible month as CSV in CSV-sample order', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['content_language' => 'sl']);
    $team->members()->attach($user, ['role' => 'owner']);

    $cal = ContentCalendar::create(['team_id' => $team->id]);
    CalendarEntry::create([
        'calendar_id' => $cal->id,
        'team_id' => $team->id,
        'scheduled_for' => '2026-05-06',
        'title' => 'Huawei',
        'platform' => 'linkedin',
        'image_headline' => 'Lights on the wall',
        'content' => 'LI body',
        'notes' => 'Vir: Huawei',
    ]);

    $response = actingAs($user)->get(route('calendar.export', ['current_team' => $team, 'month' => '2026-05']));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('Ideja');
    expect($csv)->toContain('Grafika Copy');
    expect($csv)->toContain('Platforma');
    expect($csv)->toContain('Vsebina');
    expect($csv)->toContain('Huawei');
    expect($csv)->toContain('Vir: Huawei');
});
