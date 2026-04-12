<?php

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function newConversation(): void
    {
        $conversation = Conversation::create([
            'team_id' => $this->teamModel->id,
            'user_id' => Auth::id(),
            'title' => __('New conversation'),
        ]);

        $this->redirect(route('create.chat', ['current_team' => $this->teamModel, 'conversation' => $conversation]), navigate: true);
    }

    public function deleteConversation(int $conversationId): void
    {
        $conversation = Conversation::where('team_id', $this->teamModel->id)
            ->where('user_id', Auth::id())
            ->findOrFail($conversationId);

        $conversation->delete();

        \Flux\Flux::modal('delete-conversation-'.$conversationId)->close();
    }

    public function getConversationsProperty()
    {
        return Conversation::where('team_id', $this->teamModel->id)
            ->where('user_id', Auth::id())
            ->withCount('messages')
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Create'));
    }
}; ?>

<div>
    <div class="flex items-center justify-between px-6 py-3">
        <flux:heading size="xl">{{ __('Create') }}</flux:heading>
        <flux:button variant="primary" size="sm" icon="plus" wire:click="newConversation">
            {{ __('New conversation') }}
        </flux:button>
    </div>

    <div class="mx-auto max-w-3xl px-6 py-4">
        @if ($this->conversations->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="chat-bubble-left-right" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No conversations yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Start a new conversation with your AI assistant.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" wire:click="newConversation">
                        {{ __('New conversation') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($this->conversations as $conversation)
                    <flux:card class="flex items-center justify-between p-4 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $conversation]) }}" wire:navigate class="flex-1 min-w-0">
                            <div class="flex items-center gap-3">
                                <flux:icon name="chat-bubble-left" class="size-5 shrink-0 text-zinc-400" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:heading class="truncate">{{ $conversation->title }}</flux:heading>
                                        @if ($conversation->type)
                                            <flux:badge variant="pill" size="sm" class="shrink-0">{{ match($conversation->type) {
                                                'brand' => __('Brand'),
                                                'topics' => __('Topics'),
                                                'write' => __('Write'),
                                                default => $conversation->type,
                                            } }}</flux:badge>
                                        @endif
                                    </div>
                                    <flux:text class="text-xs text-zinc-500">
                                        {{ $conversation->messages_count }} {{ __('messages') }} &middot; {{ $conversation->updated_at->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </div>
                        </a>
                        <flux:modal.trigger :name="'delete-conversation-'.$conversation->id">
                            <flux:button variant="ghost" size="xs" icon="trash" />
                        </flux:modal.trigger>
                    </flux:card>

                    <flux:modal :name="'delete-conversation-'.$conversation->id" class="min-w-[22rem]">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Delete conversation?') }}</flux:heading>
                                <flux:text class="mt-2">{{ __('This conversation and all its messages will be permanently deleted.') }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:spacer />
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="danger" wire:click="deleteConversation({{ $conversation->id }})">
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
