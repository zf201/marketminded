<?php

use App\Models\ContentPiece;
use App\Models\Team;
use App\Models\Topic;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function render()
    {
        return $this->view()->title(__('Dashboard'));
    }

    public function getRecentTopicsProperty()
    {
        return Topic::where('team_id', $this->teamModel->id)
            ->where('status', '!=', 'deleted')
            ->latest()
            ->take(5)
            ->get();
    }

    public function getRecentContentProperty()
    {
        return ContentPiece::where('team_id', $this->teamModel->id)
            ->with('topic')
            ->orderByDesc('updated_at')
            ->take(5)
            ->get();
    }

}; ?>

<div>
    <div class="mx-auto w-full max-w-5xl px-6 py-3">
        <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
        <flux:subheading>{{ __('Welcome back. Here\'s what\'s happening.') }}</flux:subheading>
    </div>

    <div class="mx-auto max-w-5xl space-y-6 px-6 py-4">

        {{-- Quick Access --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Quick Access') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3">
                <a href="{{ route('create.start', ['current_team' => $teamModel, 'type' => 'brand']) }}" wire:navigate>
                    <flux:card class="flex flex-col gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <flux:icon name="sparkles" variant="mini" />
                        <flux:heading size="sm">{{ __('Build Brand Knowledge') }}</flux:heading>
                        <flux:text>{{ __('Update your positioning, personas, and voice profile.') }}</flux:text>
                    </flux:card>
                </a>
                <a href="{{ route('create.start', ['current_team' => $teamModel, 'type' => 'topics']) }}" wire:navigate>
                    <flux:card class="flex flex-col gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <flux:icon name="light-bulb" variant="mini" />
                        <flux:heading size="sm">{{ __('Brainstorm Topics') }}</flux:heading>
                        <flux:text>{{ __('Generate and score new content ideas for your brand.') }}</flux:text>
                    </flux:card>
                </a>
                <a href="{{ route('create.start', ['current_team' => $teamModel, 'type' => 'writer']) }}" wire:navigate>
                    <flux:card class="flex flex-col gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <flux:icon name="pencil-square" variant="mini" />
                        <flux:heading size="sm">{{ __('Write a Blog Post') }}</flux:heading>
                        <flux:text>{{ __('Start a new AI-assisted draft from a topic or brief.') }}</flux:text>
                    </flux:card>
                </a>
            </div>
        </div>

        {{-- Recent Topics + Recent Content --}}
        <div class="grid gap-6 sm:grid-cols-2">

            <div>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Recent Topics') }}</flux:heading>
                    <flux:button variant="subtle" size="sm" :href="route('topics', $teamModel)" wire:navigate>
                        {{ __('View all →') }}
                    </flux:button>
                </div>
                @if ($this->recentTopics->isEmpty())
                    <flux:card>
                        <flux:text>{{ __('No topics yet.') }}</flux:text>
                    </flux:card>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->recentTopics as $topic)
                            <a href="{{ route('topics', $teamModel) }}" wire:navigate>
                                <flux:card size="sm" class="flex items-start justify-between hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                    <div class="min-w-0">
                                        <flux:heading size="sm">{{ $topic->title }}</flux:heading>
                                        @if ($topic->angle)
                                            <flux:text class="mt-0.5 truncate">{{ Str::limit($topic->angle, 60) }}</flux:text>
                                        @endif
                                    </div>
                                    @if ($topic->score)
                                        <flux:badge variant="pill" size="sm" class="ml-3 shrink-0">{{ $topic->score }}/10</flux:badge>
                                    @endif
                                </flux:card>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Recent Content') }}</flux:heading>
                    <flux:button variant="subtle" size="sm" :href="route('content.index', $teamModel)" wire:navigate>
                        {{ __('View all →') }}
                    </flux:button>
                </div>
                @if ($this->recentContent->isEmpty())
                    <flux:card>
                        <flux:text>{{ __('No content yet.') }}</flux:text>
                    </flux:card>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->recentContent as $piece)
                            <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $piece->id]) }}" wire:navigate>
                                <flux:card size="sm" class="flex items-start justify-between hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                    <div class="min-w-0">
                                        <flux:heading size="sm">{{ $piece->title ?: __('Untitled') }}</flux:heading>
                                        <flux:text class="mt-0.5">{{ $piece->updated_at->diffForHumans() }}</flux:text>
                                    </div>
                                    @php
                                        $color = match($piece->status) {
                                            'approved' => 'green',
                                            'archived' => 'zinc',
                                            default    => 'indigo',
                                        };
                                    @endphp
                                    <flux:badge :color="$color" size="sm" class="ml-3 shrink-0">{{ ucfirst($piece->status) }}</flux:badge>
                                </flux:card>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>
    </div>
</div>
