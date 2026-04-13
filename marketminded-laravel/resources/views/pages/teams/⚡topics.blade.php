<?php

use App\Models\Team;
use App\Models\Topic;
use Illuminate\Support\Facades\Auth;
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
    <div class="flex items-center justify-between px-6 py-3">
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

    <div class="mx-auto max-w-3xl px-6 py-4">
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
            <div class="space-y-2">
                @foreach ($this->topics as $topic)
                    <flux:card class="p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <flux:heading class="truncate">{{ $topic->title }}</flux:heading>
                                <flux:text class="mt-1 text-sm text-zinc-500">{{ $topic->angle }}</flux:text>

                                <div class="mt-3 flex items-center gap-4">
                                    {{-- Score slider --}}
                                    <div class="flex items-center gap-2">
                                        <flux:text class="text-xs text-zinc-500">{{ __('Score') }}</flux:text>
                                        <input
                                            type="range"
                                            min="1"
                                            max="10"
                                            value="{{ $topic->score ?? 5 }}"
                                            wire:change="updateScore({{ $topic->id }}, $event.target.value)"
                                            class="h-1.5 w-24 cursor-pointer accent-indigo-500"
                                        />
                                        <flux:text class="w-5 text-xs font-medium text-zinc-400">{{ $topic->score ?? '-' }}</flux:text>
                                    </div>

                                    {{-- Conversation link --}}
                                    @if ($topic->conversation_id)
                                        <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $topic->conversation_id]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300">
                                            <flux:icon name="chat-bubble-left" variant="mini" class="size-3.5" />
                                            {{ __('Chat') }}
                                        </a>
                                    @endif

                                    {{-- Status badge --}}
                                    @if ($topic->status === 'used')
                                        <flux:badge variant="pill" size="sm" color="green">{{ __('Used') }}</flux:badge>
                                    @endif
                                </div>
                            </div>

                            <flux:modal.trigger :name="'delete-topic-'.$topic->id">
                                <flux:button variant="ghost" size="xs" icon="trash" />
                            </flux:modal.trigger>
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
                @endforeach
            </div>
        @endif
    </div>
</div>
