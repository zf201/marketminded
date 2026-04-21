<?php

use App\Models\ContentPiece;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public ContentPiece $contentPiece;

    public ?int $selectedVersion = null;

    public function mount(Team $current_team, ContentPiece $contentPiece): void
    {
        abort_unless($contentPiece->team_id === $current_team->id, 404);

        $this->teamModel = $current_team;
        $this->contentPiece = $contentPiece;
    }

    public function selectVersion(?int $version): void
    {
        $this->selectedVersion = $version;
    }

    public function updateStatus(string $status): void
    {
        if (! in_array($status, ['draft', 'approved', 'archived'], true)) {
            return;
        }
        $this->contentPiece->update(['status' => $status]);
        $this->contentPiece->refresh();
    }

    public function restoreVersion(int $version): void
    {
        $target = $this->contentPiece->versions()->where('version', $version)->firstOrFail();
        $this->contentPiece->saveSnapshot($target->title, $target->body, "Restored from v{$version}");
        $this->contentPiece->refresh();
        $this->selectedVersion = null;

        \Flux\Flux::modal('restore-version-'.$version)->close();
    }

    public function getDisplayedProperty(): array
    {
        if ($this->selectedVersion === null) {
            return [
                'title' => $this->contentPiece->title,
                'body' => $this->contentPiece->body,
                'version' => $this->contentPiece->current_version,
                'is_current' => true,
            ];
        }

        $version = $this->contentPiece->versions()->where('version', $this->selectedVersion)->firstOrFail();

        return [
            'title' => $version->title,
            'body' => $version->body,
            'version' => $version->version,
            'is_current' => $version->version === $this->contentPiece->current_version,
        ];
    }

    public function render()
    {
        return $this->view()->title($this->contentPiece->title);
    }
}; ?>

<div>
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('content.index', ['current_team' => $teamModel])" wire:navigate />
            <flux:heading size="xl" class="truncate max-w-xl">{{ $contentPiece->title }}</flux:heading>
            <flux:badge variant="pill" size="sm" color="{{ $contentPiece->status === 'approved' ? 'green' : ($contentPiece->status === 'archived' ? 'zinc' : 'indigo') }}">
                {{ match($contentPiece->status) {
                    'draft' => __('Draft'),
                    'approved' => __('Approved'),
                    'archived' => __('Archived'),
                    default => $contentPiece->status,
                } }}
            </flux:badge>
        </div>
        <div class="flex items-center gap-2">
            @if ($contentPiece->conversation_id)
                <flux:button variant="subtle" size="sm" icon="chat-bubble-left" :href="route('create.chat', ['current_team' => $teamModel, 'conversation' => $contentPiece->conversation_id])" wire:navigate>
                    {{ __('Open conversation') }}
                </flux:button>
            @endif
            <flux:dropdown>
                <flux:button variant="subtle" size="sm" icon="ellipsis-horizontal" />
                <flux:menu>
                    <flux:menu.item wire:click="updateStatus('draft')" :checked="$contentPiece->status === 'draft'">{{ __('Mark as Draft') }}</flux:menu.item>
                    <flux:menu.item wire:click="updateStatus('approved')" :checked="$contentPiece->status === 'approved'">{{ __('Approve') }}</flux:menu.item>
                    <flux:menu.item wire:click="updateStatus('archived')" :checked="$contentPiece->status === 'archived'">{{ __('Archive') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mx-auto grid max-w-6xl grid-cols-1 gap-6 px-6 py-4 lg:grid-cols-[1fr_18rem]">
        <div>
            <div class="mb-3 flex items-center gap-2">
                <flux:badge variant="pill" size="sm">v{{ $this->displayed['version'] }}</flux:badge>
                @if (! $this->displayed['is_current'])
                    <flux:text class="text-xs text-amber-500">{{ __('Viewing a past version') }}</flux:text>
                    <flux:button size="xs" variant="subtle" wire:click="selectVersion(null)">{{ __('View current') }}</flux:button>
                @endif
            </div>

            <article class="prose prose-invert max-w-none">
                <h1>{{ $this->displayed['title'] }}</h1>
                <div class="whitespace-pre-wrap">{{ $this->displayed['body'] }}</div>
            </article>
        </div>

        <aside>
            <flux:heading size="sm" class="mb-2">{{ __('Version history') }}</flux:heading>
            <div class="space-y-2">
                @foreach ($contentPiece->versions as $v)
                    <div class="rounded-lg border border-zinc-700 bg-zinc-900 p-3 {{ $selectedVersion === $v->version ? 'ring-1 ring-indigo-500' : '' }}">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:badge variant="pill" size="sm" color="{{ $v->version === $contentPiece->current_version ? 'indigo' : 'zinc' }}">
                                    v{{ $v->version }}
                                </flux:badge>
                                @if ($v->version === $contentPiece->current_version)
                                    <flux:text class="text-xs text-indigo-400">{{ __('Current') }}</flux:text>
                                @endif
                            </div>
                            <flux:text class="text-xs text-zinc-500">{{ $v->created_at?->diffForHumans() }}</flux:text>
                        </div>
                        @if ($v->change_description)
                            <flux:text class="mt-1 text-xs text-zinc-400">{{ $v->change_description }}</flux:text>
                        @endif
                        <div class="mt-2 flex items-center gap-2">
                            <flux:button size="xs" variant="subtle" wire:click="selectVersion({{ $v->version }})">{{ __('View') }}</flux:button>
                            @if ($v->version !== $contentPiece->current_version)
                                <flux:modal.trigger :name="'restore-version-'.$v->version">
                                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left">{{ __('Restore') }}</flux:button>
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </div>

                    <flux:modal :name="'restore-version-'.$v->version" class="min-w-[22rem]">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Restore v:v?', ['v' => $v->version]) }}</flux:heading>
                                <flux:text class="mt-2">{{ __('This creates a new version with v:v\'s content, preserving history.', ['v' => $v->version]) }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:spacer />
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" wire:click="restoreVersion({{ $v->version }})">
                                    {{ __('Restore') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endforeach
            </div>
        </aside>
    </div>
</div>
