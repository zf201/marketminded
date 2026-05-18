<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
});

it('renders entries in the current month', function () {
    $cal = ContentCalendar::create(['team_id' => $this->team->id]);
    $date = now()->startOfMonth()->addDays(5);
    CalendarEntry::create([
        'calendar_id' => $cal->id,
        'team_id' => $this->team->id,
        'scheduled_for' => $date->format('Y-m-d'),
        'title' => 'Test entry',
    ]);

    actingAs($this->user)
        ->get(route('calendar.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Test entry');
});

it('shows the export button', function () {
    actingAs($this->user)
        ->get(route('calendar.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Export CSV');
});
