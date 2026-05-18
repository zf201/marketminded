<?php

use App\Models\Conversation;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function getConversationsProperty()
    {
        return Conversation::where('team_id', $this->teamModel->id)
            ->where('type', 'planner')
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Planner'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Planner') }}</flux:heading>
            @if ($this->conversations->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->conversations->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'planner'])" wire:navigate>
            {{ __('Start Planning') }}
        </flux:button>
    </div>

    <div class="mx-auto w-full max-w-5xl px-6 pb-2">
        <flux:subheading>
            {{ __('Plan a month of social posts in a single shared calendar. The planner pulls from your unused topics, posts, and content pieces — and brainstorms fresh ideas for empty days.') }}
        </flux:subheading>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->conversations->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="calendar-days" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No planner sessions yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Start a planning conversation to fill out the calendar.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'planner'])" wire:navigate>
                        {{ __('Start Planning') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->conversations as $c)
                    <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $c]) }}" wire:navigate class="block">
                        <flux:card class="flex h-full flex-col p-4 transition hover:border-indigo-400 dark:hover:border-indigo-500">
                            <flux:heading class="line-clamp-2">{{ $c->title ?? __('Untitled planner session') }}</flux:heading>
                            <div class="mt-auto pt-3 text-xs text-zinc-500">
                                {{ $c->updated_at->diffForHumans() }}
                            </div>
                        </flux:card>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
