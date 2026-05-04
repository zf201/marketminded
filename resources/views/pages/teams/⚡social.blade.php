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
            ->with(['socialPosts:id,content_piece_id,platform,posted_at,status'])
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

    @if ($this->pieces->isNotEmpty())
        <div class="mx-auto w-full max-w-5xl px-6 pb-2">
            <flux:subheading>
                {{ __('Each card is the funnel for one piece — the social posts that drive readers to it. Open a card to view, copy, score, and refine the posts.') }}
            </flux:subheading>
        </div>
    @endif

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
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->pieces as $piece)
                    @php
                        $platforms = $piece->socialPosts->pluck('platform')->unique()->values();
                        $postedCount = $piece->socialPosts->whereNotNull('posted_at')->count();
                        $totalCount = $piece->socialPosts->count();
                    @endphp
                    <a href="{{ route('social.show', ['current_team' => $teamModel, 'contentPiece' => $piece]) }}" wire:navigate class="block">
                        <flux:card class="flex h-full flex-col p-4 transition hover:border-indigo-400 dark:hover:border-indigo-500">
                            <div class="flex items-start justify-between gap-3">
                                <flux:heading class="line-clamp-2">{{ $piece->title }}</flux:heading>
                                <flux:icon name="arrow-right" variant="mini" class="mt-1 size-4 shrink-0 text-zinc-400" />
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                @foreach ($platforms as $platform)
                                    <flux:badge variant="pill" size="sm">{{ ucfirst(str_replace('_', ' ', $platform)) }}</flux:badge>
                                @endforeach
                            </div>

                            <div class="mt-auto flex items-center gap-2 pt-3 text-xs text-zinc-500">
                                <flux:icon name="document-text" variant="mini" class="size-3.5" />
                                <span>{{ trans_choice('{1} 1 post|[2,*] :count posts', $totalCount, ['count' => $totalCount]) }}</span>
                                @if ($postedCount > 0)
                                    <span class="text-zinc-300 dark:text-zinc-700">•</span>
                                    <flux:icon name="check-circle" variant="mini" class="size-3.5 text-emerald-500" />
                                    <span>{{ __(':count posted', ['count' => $postedCount]) }}</span>
                                @endif
                            </div>
                        </flux:card>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
