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
            ->whereHas('socialPosts')
            ->with(['socialPosts:id,content_piece_id,platform'])
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title(__('Social'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">{{ __('Social') }}</flux:heading>
            @if ($this->pieces->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->pieces->count() }}</flux:badge>
            @endif
        </div>
        <flux:button variant="primary" size="sm" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
            {{ __('Build a Funnel') }}
        </flux:button>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->pieces->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="megaphone" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No funnels yet') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Pick a content piece and turn it into a set of social posts.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
                        {{ __('Build a Funnel') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->pieces as $piece)
                    <a href="{{ route('social.show', ['current_team' => $teamModel, 'contentPiece' => $piece]) }}" wire:navigate class="block rounded-xl border border-zinc-200 p-4 transition hover:border-indigo-400 dark:border-zinc-700 dark:hover:border-indigo-500">
                        <flux:heading size="sm" class="line-clamp-2">{{ $piece->title }}</flux:heading>
                        <div class="mt-2 flex items-center gap-2 text-xs text-zinc-500">
                            <span>{{ trans_choice('{1} 1 post|[2,*] :count posts', $piece->socialPosts->count(), ['count' => $piece->socialPosts->count()]) }}</span>
                            <span class="text-zinc-300">•</span>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($piece->socialPosts->pluck('platform')->unique() as $platform)
                                    <flux:badge variant="pill" size="sm">{{ $platform }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
