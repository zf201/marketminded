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

    public function deleteEntry(int $entryId): void
    {
        CalendarEntry::where('id', $entryId)
            ->where('team_id', $this->teamModel->id)
            ->delete();
        \Flux\Flux::modal('entry-'.$entryId)->close();
    }

    public function getDaysProperty()
    {
        $start = Carbon::parse($this->month.'-01');
        $end = $start->copy()->endOfMonth();

        $entriesByDay = CalendarEntry::where('calendar_id', $this->calendar->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($e) => $e->scheduled_for->format('Y-m-d'));

        return $entriesByDay
            ->map(fn ($entries, $key) => [
                'date' => Carbon::parse($key),
                'entries' => $entries,
            ])
            ->values();
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

    @if ($this->days->isEmpty())
        <div class="mt-16 text-center">
            <flux:icon name="calendar" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="lg" class="mt-4">{{ __('No entries this month') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Start a planner conversation to add posts.') }}</flux:subheading>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'planner'])" wire:navigate>
                    {{ __('Start Planning') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="mt-6 space-y-10">
            @foreach ($this->days as $day)
                <section class="pt-3">
                    <div class="flex items-baseline gap-3 border-b border-zinc-200 pb-2 dark:border-zinc-700">
                        <flux:heading size="lg">{{ $day['date']->format('j') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ strtolower($day['date']->format('l, F')) }}</flux:text>
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($day['entries'] as $e)
                            @php
                                $sourceUrl = null;
                                $sourceLabel = null;
                                if ($e->source_content_piece_id) {
                                    $sourceUrl = route('content.show', ['current_team' => $teamModel, 'contentPiece' => $e->source_content_piece_id]);
                                    $sourceLabel = __('blog');
                                } elseif ($e->source_social_post_id && $e->sourceSocialPost) {
                                    $sourceUrl = route('social.show', ['current_team' => $teamModel, 'contentPiece' => $e->sourceSocialPost->content_piece_id]);
                                    $sourceLabel = __('social');
                                } elseif ($e->source_topic_id) {
                                    $sourceUrl = route('topics', ['current_team' => $teamModel]);
                                    $sourceLabel = __('topic');
                                }
                                $preview = trim((string) ($e->content ?? ''));
                                $preview = mb_substr($preview, 0, 140) . (mb_strlen((string) $e->content) > 140 ? '…' : '');
                            @endphp
                            <flux:card class="flex flex-col p-4">
                                <div class="flex items-start justify-between gap-2">
                                    <flux:heading class="line-clamp-2 text-base">{{ $e->title }}</flux:heading>
                                    @if ($e->platform)
                                        <flux:badge variant="pill" size="sm">{{ ucfirst($e->platform) }}</flux:badge>
                                    @endif
                                </div>

                                @if ($e->image_headline)
                                    <flux:text class="mt-2 text-xs text-zinc-500">{{ __('Image:') }} {{ $e->image_headline }}</flux:text>
                                @endif

                                @if ($preview)
                                    <flux:text class="mt-2 line-clamp-3 text-sm text-zinc-400">{{ $preview }}</flux:text>
                                @endif

                                <div class="mt-auto flex items-center justify-between pt-3 text-xs">
                                    @if ($sourceUrl)
                                        <a href="{{ $sourceUrl }}" wire:navigate class="inline-flex items-center gap-1 text-indigo-500 hover:underline">
                                            <flux:icon name="link" variant="mini" class="size-3" />
                                            {{ $sourceLabel }}
                                        </a>
                                    @else
                                        <span></span>
                                    @endif
                                    <div class="flex items-center gap-1">
                                        <flux:modal.trigger :name="'entry-'.$e->id">
                                            <flux:button size="xs" variant="ghost" icon="eye">{{ __('View') }}</flux:button>
                                        </flux:modal.trigger>
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="deleteEntry({{ $e->id }})"
                                            wire:confirm="{{ __('Delete this calendar entry?') }}"
                                            class="text-zinc-500 hover:text-red-500"
                                        />
                                    </div>
                                </div>
                            </flux:card>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    @foreach ($this->days as $day)
        @foreach ($day['entries'] as $e)
            @php $r = ['date' => $day['date']]; @endphp
            <flux:modal :name="'entry-'.$e->id" class="min-w-[32rem] max-w-2xl" wire:key="modal-{{ $e->id }}">
                <div class="space-y-4">
                    <div>
                        <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ $r['date']->format('l, F j') }} · {{ $e->platform ?: __('no platform') }}</flux:text>
                        <flux:heading size="lg" class="mt-1">{{ $e->title }}</flux:heading>
                    </div>

                    @if ($e->image_headline)
                        <div>
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Image headline') }}</flux:text>
                            <div class="mt-1 text-sm">{{ $e->image_headline }}</div>
                        </div>
                    @endif

                    @if ($e->image_prompt)
                        <div>
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Image prompt') }}</flux:text>
                            <div class="mt-1 text-sm text-zinc-400">{{ $e->image_prompt }}</div>
                        </div>
                    @endif

                    @if ($e->content)
                        <div>
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Content') }}</flux:text>
                            <div class="mt-1 whitespace-pre-line text-sm">{{ $e->content }}</div>
                        </div>
                    @endif

                    @if ($e->notes)
                        <div>
                            <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Notes') }}</flux:text>
                            <div class="mt-1 whitespace-pre-line text-sm text-zinc-400">{{ $e->notes }}</div>
                        </div>
                    @endif
                </div>
            </flux:modal>
        @endforeach
    @endforeach
</div>
