<?php

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

    public function updateScore(int $topicId, int $score): void
    {
        Topic::where('team_id', $this->teamModel->id)
            ->findOrFail($topicId)
            ->update(['score' => max(1, min(10, $score))]);
    }

    public function deleteTopic(int $topicId): void
    {
        Topic::where('team_id', $this->teamModel->id)
            ->findOrFail($topicId)
            ->update(['status' => 'deleted']);

        \Flux\Flux::modal('delete-topic-'.$topicId)->close();
    }

    public function getTopicsProperty()
    {
        return Topic::where('team_id', $this->teamModel->id)
            ->where('status', '!=', 'deleted')
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Topics'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Topics') }}</flux:heading>
            @if ($this->topics->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->topics->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create')" wire:navigate>
            {{ __('New brainstorm') }}
        </flux:button>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->topics->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="light-bulb" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No topics yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Start a Brainstorm topics conversation to discover content ideas.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create')" wire:navigate>
                        {{ __('New brainstorm') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($this->topics as $topic)
                <div class="flex flex-col">
                    <flux:card class="flex flex-1 flex-col p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <flux:heading>{{ $topic->title }}</flux:heading>
                                <flux:text class="mt-1 text-sm">{{ $topic->angle }}</flux:text>
                            </div>

                            <flux:modal.trigger :name="'delete-topic-'.$topic->id">
                                <flux:button variant="ghost" size="xs" icon="trash" />
                            </flux:modal.trigger>
                        </div>

                        @if (!empty($topic->sources))
                            <div class="mt-3 text-xs text-zinc-500" x-data="{ open: false }">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    class="inline-flex items-center gap-1 hover:text-zinc-300 transition-colors"
                                >
                                    {{ __('Sources') }} ({{ count($topic->sources) }})
                                    <svg x-bind:class="open ? 'rotate-180' : ''" class="size-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <ul x-show="open" x-cloak class="mt-2 space-y-1 rounded-md border border-zinc-700 bg-zinc-900/50 p-2 text-xs text-zinc-400">
                                    @foreach ($topic->sources as $source)
                                        <li class="break-all">
                                            @if (filter_var($source, FILTER_VALIDATE_URL))
                                                @php
                                                    $host = parse_url($source, PHP_URL_HOST) ?: $source;
                                                    $host = preg_replace('/^www\./', '', $host);
                                                @endphp
                                                <a href="{{ $source }}" target="_blank" rel="noopener noreferrer" title="{{ $source }}" class="hover:text-zinc-200 underline decoration-dotted">{{ $host }}</a>
                                            @else
                                                {{ $source }}
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mt-auto flex items-center gap-2 pt-3">
                            <flux:text class="shrink-0 text-xs text-zinc-500">{{ __('Score') }}</flux:text>
                            <input
                                type="range"
                                min="1"
                                max="10"
                                value="{{ $topic->score ?? 5 }}"
                                wire:change="updateScore({{ $topic->id }}, $event.target.value)"
                                class="h-1.5 flex-1 cursor-pointer accent-indigo-500"
                            />
                            <flux:text class="w-5 shrink-0 text-xs font-medium text-zinc-400">{{ $topic->score ?? '-' }}</flux:text>

                            @if ($topic->conversation_id)
                                <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $topic->conversation_id]) }}" wire:navigate class="ml-2 inline-flex shrink-0 items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300">
                                    <flux:icon name="chat-bubble-left" variant="mini" class="size-3.5" />
                                    {{ __('Chat') }}
                                </a>
                            @endif

                            @if ($topic->status === 'used')
                                <flux:badge variant="pill" size="sm" color="green" class="ml-2">{{ __('Used') }}</flux:badge>
                            @endif
                        </div>
                    </flux:card>

                    <flux:modal :name="'delete-topic-'.$topic->id" class="min-w-[22rem]">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Delete topic?') }}</flux:heading>
                                <flux:text class="mt-2">{{ __('This topic will be removed from your backlog.') }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:spacer />
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="deleteTopic({{ $topic->id }})">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                </div>
            @endforeach
        </div>
    @endif
    </div>
</div>
