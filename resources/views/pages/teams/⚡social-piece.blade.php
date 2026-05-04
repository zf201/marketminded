<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;
    public ContentPiece $piece;

    public function mount(Team $current_team, ContentPiece $contentPiece): void
    {
        abort_unless($contentPiece->team_id === $current_team->id, 404);
        $this->teamModel = $current_team;
        $this->piece = $contentPiece;
    }

    public function getPostsProperty()
    {
        return SocialPost::where('content_piece_id', $this->piece->id)
            ->where('status', 'active')
            ->orderBy('position')
            ->get();
    }

    public function getRefineConversationProperty(): ?Conversation
    {
        return Conversation::where('team_id', $this->teamModel->id)
            ->where('type', 'funnel')
            ->where('content_piece_id', $this->piece->id)
            ->latest()
            ->first();
    }

    public function updateScore(int $postId, int $score): void
    {
        SocialPost::where('team_id', $this->teamModel->id)
            ->findOrFail($postId)
            ->update(['score' => max(1, min(10, $score))]);
    }

    public function togglePosted(int $postId): void
    {
        $post = SocialPost::where('team_id', $this->teamModel->id)->findOrFail($postId);
        $post->update(['posted_at' => $post->posted_at ? null : now()]);
    }

    public function deletePost(int $postId): void
    {
        SocialPost::where('team_id', $this->teamModel->id)
            ->findOrFail($postId)
            ->update(['status' => 'deleted']);
        \Flux\Flux::modal('delete-social-'.$postId)->close();
    }

    public function copyMarkdown(int $postId): string
    {
        $post = SocialPost::where('team_id', $this->teamModel->id)->findOrFail($postId);
        $platformLabel = match ($post->platform) {
            'linkedin' => 'LinkedIn',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'short_video' => 'Short-form Video',
            default => $post->platform,
        };
        $tags = is_array($post->hashtags) && $post->hashtags
            ? '#'.implode(' #', $post->hashtags)
            : '';
        $visualLabel = $post->platform === 'short_video' ? 'Video' : 'Image';
        $visualValue = $post->platform === 'short_video' ? $post->video_treatment : $post->image_prompt;

        return "**{$platformLabel}**\n\n{$post->hook}\n\n{$post->body}\n\n{$tags}\n\n---\n{$visualLabel}: {$visualValue}\n";
    }

    public function render()
    {
        return $this->view()->title($this->piece->title);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-6 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('social.index', ['current_team' => $teamModel])" wire:navigate />
            <flux:heading size="xl" class="truncate">{{ $piece->title }}</flux:heading>
            @if ($this->posts->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->posts->count() }}</flux:badge>
            @endif
        </div>
        @php $refineConv = $this->refineConversation; @endphp
        @if ($refineConv)
            <flux:button variant="primary" icon="chat-bubble-left-right"
                :href="route('create.chat', ['current_team' => $teamModel, 'conversation' => $refineConv])" wire:navigate>
                {{ __('Refine in chat') }}
            </flux:button>
        @else
            <flux:button variant="primary" icon="plus"
                :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
                {{ __('Build a Funnel') }}
            </flux:button>
        @endif
    </div>

    @if ($this->posts->isNotEmpty())
        <div class="mx-auto w-full max-w-7xl px-6 pb-2">
            <flux:subheading>
                {{ __('Score posts 1–10 to teach the AI what good looks like. Mark a post Posted once it\'s live. Use Refine in chat to ask for changes — the AI knows about the existing posts.') }}
            </flux:subheading>
        </div>
    @endif

    <div class="mx-auto max-w-7xl px-6 py-4">
        @if ($this->posts->isEmpty())
            <div class="py-20 text-center">
                <flux:icon name="megaphone" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="lg" class="mt-4">{{ __('No active posts for this piece') }}</flux:heading>
                <flux:subheading class="mt-1">{{ __('Build a funnel to generate social posts that drive traffic back to this piece.') }}</flux:subheading>
                <div class="mt-6">
                    <flux:button variant="primary" icon="plus" :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
                        {{ __('Build a Funnel') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->posts as $post)
                    @php
                        $platformLabel = match ($post->platform) {
                            'linkedin' => 'LinkedIn',
                            'facebook' => 'Facebook',
                            'instagram' => 'Instagram',
                            'short_video' => 'Short-form Video',
                            default => $post->platform,
                        };
                        $platformIcon = match ($post->platform) {
                            'short_video' => 'film',
                            default => 'megaphone',
                        };
                        $tags = is_array($post->hashtags) && $post->hashtags
                            ? '#'.implode(' #', $post->hashtags)
                            : '';
                        $visualLabel = $post->platform === 'short_video' ? __('Video') : __('Image');
                        $visualValue = $post->platform === 'short_video' ? $post->video_treatment : $post->image_prompt;
                        $markdown = "**{$platformLabel}**\n\n{$post->hook}\n\n{$post->body}\n\n{$tags}\n\n---\n{$visualLabel}: {$visualValue}\n";
                    @endphp
                    <div class="flex flex-col">
                        <flux:card class="flex flex-1 flex-col p-4 {{ $post->posted_at ? 'opacity-70' : '' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <flux:icon :name="$platformIcon" variant="mini" class="size-4 text-zinc-400" />
                                    <flux:badge variant="pill" size="sm">{{ $platformLabel }}</flux:badge>
                                    @if ($post->posted_at)
                                        <flux:badge variant="pill" size="sm" color="green">{{ __('Posted') }}</flux:badge>
                                    @endif
                                </div>
                                <flux:modal.trigger :name="'delete-social-'.$post->id">
                                    <flux:button variant="ghost" size="xs" icon="trash" />
                                </flux:modal.trigger>
                            </div>

                            <div class="mt-3">
                                <flux:heading size="sm">{{ $post->hook }}</flux:heading>
                                <div class="prose prose-sm mt-2 max-w-none whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{!! str_replace('[POST_URL]', '<span class="rounded bg-amber-100 px-1 font-mono text-xs text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">[POST_URL]</span>', e($post->body)) !!}</div>
                            </div>

                            @if (! empty($post->hashtags))
                                <div class="mt-3 flex flex-wrap gap-1">
                                    @foreach ($post->hashtags as $tag)
                                        <flux:badge variant="pill" size="sm">#{{ $tag }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-3 rounded-md border border-zinc-200 bg-zinc-50 p-2 text-xs dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="font-semibold">{{ $visualLabel }}:</span>
                                {{ $visualValue }}
                            </div>

                            <div class="mt-auto flex items-center gap-2 pt-3">
                                <flux:text class="shrink-0 text-xs text-zinc-500">{{ __('Score') }}</flux:text>
                                <input
                                    type="range"
                                    min="1"
                                    max="10"
                                    value="{{ $post->score ?? 5 }}"
                                    wire:change="updateScore({{ $post->id }}, $event.target.value)"
                                    class="h-1.5 flex-1 cursor-pointer accent-indigo-500"
                                />
                                <flux:text class="w-5 shrink-0 text-xs font-medium text-zinc-400">{{ $post->score ?? '-' }}</flux:text>

                                <button
                                    type="button"
                                    wire:click="togglePosted({{ $post->id }})"
                                    aria-label="{{ $post->posted_at ? __('Mark as unposted') : __('Mark as posted') }}"
                                    class="ml-2 inline-flex items-center text-xs {{ $post->posted_at ? 'text-emerald-500 hover:text-emerald-400' : 'text-zinc-500 hover:text-zinc-300' }}"
                                >
                                    <flux:icon name="check-circle" variant="mini" class="size-4" />
                                </button>

                                <div
                                    x-data="{ copied: false, md: @js($markdown) }"
                                    class="ml-1 inline-flex"
                                >
                                    <button
                                        type="button"
                                        aria-label="Copy as markdown"
                                        @click="navigator.clipboard.writeText(md).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                                        class="inline-flex items-center text-xs text-zinc-500 hover:text-zinc-300"
                                    >
                                        <svg x-show="!copied" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m9 5h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.5c0-.621.504-1.125 1.125-1.125h7.5c.621 0 1.125.504 1.125 1.125v8.625"/>
                                        </svg>
                                        <svg x-show="copied" x-cloak class="size-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    </button>
                                </div>

                                @if ($post->conversation_id)
                                    <a href="{{ route('create.chat', ['current_team' => $teamModel, 'conversation' => $post->conversation_id]) }}" wire:navigate class="ml-1 inline-flex shrink-0 items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300">
                                        <flux:icon name="chat-bubble-left" variant="mini" class="size-3.5" />
                                        {{ __('Chat') }}
                                    </a>
                                @endif
                            </div>
                        </flux:card>

                        <flux:modal :name="'delete-social-'.$post->id" class="min-w-[22rem]">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Delete this post?') }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('This is a soft delete; you can rebuild the funnel any time.') }}</flux:text>
                                </div>
                                <div class="flex gap-2">
                                    <flux:spacer />
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" wire:click="deletePost({{ $post->id }})">
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
