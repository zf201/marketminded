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
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('social.index', ['current_team' => $teamModel])" wire:navigate />
            <flux:heading size="xl" class="line-clamp-1">{{ $piece->title }}</flux:heading>
            @if ($this->posts->isNotEmpty())
                <flux:badge variant="pill" size="sm">{{ $this->posts->count() }}</flux:badge>
            @endif
        </div>
        @php $refineConv = $this->refineConversation; @endphp
        @if ($refineConv)
            <flux:button variant="primary" size="sm" icon="chat-bubble-left-right"
                :href="route('create.chat', ['current_team' => $teamModel, 'conversation' => $refineConv])" wire:navigate>
                {{ __('Refine in chat') }}
            </flux:button>
        @else
            <flux:button variant="primary" size="sm" icon="chat-bubble-left-right"
                :href="route('create.new', ['current_team' => $teamModel, 'type' => 'funnel'])" wire:navigate>
                {{ __('Build a Funnel') }}
            </flux:button>
        @endif
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        @if ($this->posts->isEmpty())
            <div class="py-20 text-center">
                <flux:subheading>{{ __('No active posts for this piece.') }}</flux:subheading>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($this->posts as $post)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 {{ $post->posted_at ? 'opacity-60' : '' }}">
                        <div class="mb-2 flex items-center justify-between">
                            <flux:badge variant="pill" size="sm">{{ ucfirst(str_replace('_', ' ', $post->platform)) }}</flux:badge>
                            @if ($post->posted_at)
                                <flux:badge variant="pill" size="sm" color="lime">{{ __('Posted') }}</flux:badge>
                            @endif
                        </div>
                        <p class="font-semibold">{{ $post->hook }}</p>
                        <div class="prose prose-sm mt-2 text-zinc-600 dark:text-zinc-300">
                            {!! str_replace('[POST_URL]', '<span class="rounded bg-amber-100 px-1 font-mono text-xs text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">[POST_URL]</span>', e($post->body)) !!}
                        </div>
                        @if (! empty($post->hashtags))
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($post->hashtags as $tag)
                                    <flux:badge variant="pill" size="sm">#{{ $tag }}</flux:badge>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-3 rounded-md bg-zinc-50 p-2 text-xs dark:bg-zinc-800/50">
                            <span class="font-semibold">{{ $post->platform === 'short_video' ? __('Video') : __('Image') }}:</span>
                            {{ $post->platform === 'short_video' ? $post->video_treatment : $post->image_prompt }}
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-1">
                                @for ($s = 1; $s <= 10; $s++)
                                    <button wire:click="updateScore({{ $post->id }}, {{ $s }})"
                                        class="size-5 rounded-full text-xs {{ $post->score && $post->score >= $s ? 'bg-indigo-500 text-white' : 'bg-zinc-100 dark:bg-zinc-700' }}">{{ $s }}</button>
                                @endfor
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:button size="xs" variant="ghost" icon="check"
                                    wire:click="togglePosted({{ $post->id }})">
                                    {{ $post->posted_at ? __('Unposted') : __('Posted') }}
                                </flux:button>
                                <flux:button size="xs" variant="ghost" icon="clipboard"
                                    x-on:click="navigator.clipboard.writeText(@js($this->copyMarkdown($post->id)))">
                                    {{ __('Copy') }}
                                </flux:button>
                                <flux:modal.trigger name="delete-social-{{ $post->id }}">
                                    <flux:button size="xs" variant="ghost" icon="trash" />
                                </flux:modal.trigger>
                            </div>
                        </div>
                    </div>

                    <flux:modal name="delete-social-{{ $post->id }}">
                        <div class="space-y-4">
                            <flux:heading>{{ __('Delete this post?') }}</flux:heading>
                            <flux:subheading>{{ __('This is a soft delete; you can rebuild the funnel any time.') }}</flux:subheading>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                <flux:button variant="danger" wire:click="deletePost({{ $post->id }})">{{ __('Delete') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endforeach
            </div>
        @endif
    </div>
</div>
