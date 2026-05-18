<?php

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use Carbon\Carbon;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;
    public string $month;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
        $this->month = now()->format('Y-m');
    }

    public function prevMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonth()->format('Y-m');
    }

    public function getCalendarProperty()
    {
        return ContentCalendar::firstOrCreate(['team_id' => $this->teamModel->id]);
    }

    public function getRowsProperty()
    {
        $start = Carbon::parse($this->month.'-01');
        $end = $start->copy()->endOfMonth();

        $cal = $this->calendar;
        $postingDays = $cal->posting_days ?? $this->teamModel->posting_days ?? ['mon', 'wed', 'fri'];
        $postingDayNumbers = collect($postingDays)->map(fn ($d) => [
            'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        ][$d] ?? null)->filter()->all();

        $entries = CalendarEntry::where('calendar_id', $cal->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->orderBy('scheduled_for')
            ->get()
            ->keyBy(fn ($e) => $e->scheduled_for->format('Y-m-d'));

        $rows = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! in_array($d->isoWeekday(), $postingDayNumbers)) {
                continue;
            }
            $key = $d->format('Y-m-d');
            $rows[] = ['date' => $d->copy(), 'entry' => $entries->get($key)];
        }
        return $rows;
    }

    public function render()
    {
        return $this->view()->title(__('Calendar'));
    }
}; ?>

<div class="mx-auto max-w-7xl px-6 py-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl">{{ __('Calendar') }}</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button size="sm" icon="chevron-left" wire:click="prevMonth" />
            <flux:badge size="sm" variant="pill">{{ \Carbon\Carbon::parse($month.'-01')->format('F Y') }}</flux:badge>
            <flux:button size="sm" icon="chevron-right" wire:click="nextMonth" />
            <flux:button size="sm" variant="primary" icon="arrow-down-tray" :href="route('calendar.export', ['current_team' => $teamModel, 'month' => $month])">
                {{ __('Export CSV') }}
            </flux:button>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900/40">
                <tr class="text-left">
                    <th class="px-2 py-2">{{ __('Day') }}</th>
                    <th class="px-2 py-2">{{ __('Weekday') }}</th>
                    <th class="px-2 py-2">{{ __('Idea') }}</th>
                    <th class="px-2 py-2">{{ __('Image Headline') }}</th>
                    <th class="px-2 py-2">{{ __('LinkedIn') }}</th>
                    <th class="px-2 py-2">{{ __('Instagram') }}</th>
                    <th class="px-2 py-2">{{ __('Facebook') }}</th>
                    <th class="px-2 py-2">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($this->rows as $r)
                @php $e = $r['entry']; @endphp
                <tr class="border-t border-zinc-200 align-top dark:border-zinc-700 {{ $e ? '' : 'opacity-50' }}">
                    <td class="px-2 py-2 whitespace-nowrap">{{ $r['date']->day }}</td>
                    <td class="px-2 py-2 whitespace-nowrap">{{ strtolower($r['date']->format('l')) }}</td>
                    <td class="px-2 py-2">{{ $e->title ?? '—' }}</td>
                    <td class="px-2 py-2">{{ $e->image_headline ?? '' }}</td>
                    <td class="max-w-xs whitespace-pre-line px-2 py-2">{{ $e->linkedin_copy ?? '' }}</td>
                    <td class="max-w-xs whitespace-pre-line px-2 py-2">{{ $e->instagram_copy ?? '' }}</td>
                    <td class="max-w-xs whitespace-pre-line px-2 py-2">{{ $e->facebook_copy ?? '' }}</td>
                    <td class="px-2 py-2">{{ $e->notes ?? '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
