<?php

use App\Models\ContentPiece;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
    }

    public function getPiecesProperty()
    {
        return ContentPiece::where('team_id', $this->teamModel->id)
            ->with('topic')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Content'));
    }
}; ?>

<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Content') }}</flux:heading>
            @if ($this->pieces->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->pieces->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create')" wire:navigate>
            {{ __('New blog post') }}
        </flux:button>
    </div>

    @if ($this->pieces->isEmpty())
        <div class="py-20 text-center">
            <flux:icon name="document-text" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="lg" class="mt-4">{{ __('No content yet') }}</flux:heading>
            <flux:subheading class="mt-1">{{ __('Write your first blog post from a Topic.') }}</flux:subheading>
            <div class="mt-6">
                <flux:button variant="primary" icon="plus" :href="route('create')" wire:navigate>
                    {{ __('New blog post') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="grid gap-2 px-6 py-4 sm:grid-cols-2">
            @foreach ($this->pieces as $piece)
                <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $piece->id]) }}" wire:navigate>
                    <flux:card class="flex flex-col p-4 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <flux:heading class="truncate">{{ $piece->title ?: __('Untitled') }}</flux:heading>
                                <flux:text class="mt-1 text-xs text-zinc-500">
                                    v{{ $piece->current_version }}
                                    &middot; {{ $piece->updated_at->diffForHumans() }}
                                    @if ($piece->topic)
                                        &middot; {{ $piece->topic->title }}
                                    @endif
                                </flux:text>
                            </div>
                            <flux:badge variant="pill" size="sm" color="{{ $piece->status === 'approved' ? 'green' : ($piece->status === 'archived' ? 'zinc' : 'indigo') }}">
                                {{ match($piece->status) {
                                    'draft' => __('Draft'),
                                    'approved' => __('Approved'),
                                    'archived' => __('Archived'),
                                    default => $piece->status,
                                } }}
                            </flux:badge>
                        </div>
                        <flux:text class="mt-2 text-sm text-zinc-400 line-clamp-3">{{ mb_substr(strip_tags($piece->body), 0, 200) }}</flux:text>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
