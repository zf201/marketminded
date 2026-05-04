<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Jobs\RunConversationTurn;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public ?Conversation $conversation = null;

    public ?string $type = null;

    public string $prompt = '';

    public bool $isStreaming = false;

    public ?string $topicsMode = null;

    public ?int $topicId = null;

    public ?string $topicTitle = null;

    public bool $freeForm = false;

    public ?int $contentPieceId = null;

    public ?string $contentPieceTitle = null;

    public ?string $funnelGuidance = null;

    public array $messages = [];

    public function mount(Team $current_team, ?Conversation $conversation = null): void
    {
        $this->teamModel = $current_team;
        $this->conversation = $conversation;
        $this->type = $conversation?->type ?? request('type') ?: null;

        if ($conversation) {
            $this->loadMessages();
            $this->topicId = $conversation->topic_id;
            $this->topicTitle = $conversation->topic?->title;
            $this->contentPieceId = $conversation->content_piece_id;
            $this->contentPieceTitle = $conversation->contentPiece?->title;
            $brief = is_array($conversation->brief ?? null) ? $conversation->brief : [];
            $this->funnelGuidance = $brief['funnel_guidance'] ?? null;
        }
    }

    public function selectFunnelContentPiece(int $contentPieceId): void
    {
        $piece = \App\Models\ContentPiece::where('team_id', $this->teamModel->id)->findOrFail($contentPieceId);
        $this->contentPieceId = $piece->id;
        $this->contentPieceTitle = $piece->title;
        $this->prompt = __("Build a funnel of social posts that drive traffic back to: :title", ['title' => $piece->title]);

        if ($this->conversation) {
            $brief = is_array($this->conversation->brief) ? $this->conversation->brief : [];
            if ($this->funnelGuidance) {
                $brief['funnel_guidance'] = $this->funnelGuidance;
            }
            $this->conversation->update([
                'content_piece_id' => $piece->id,
                'brief' => $brief,
            ]);
            $this->conversation->refresh();
        }
    }

    public function setFunnelGuidance(string $guidance): void
    {
        $this->funnelGuidance = trim($guidance) ?: null;
        if ($this->conversation) {
            $brief = is_array($this->conversation->brief) ? $this->conversation->brief : [];
            $brief['funnel_guidance'] = $this->funnelGuidance;
            $this->conversation->update(['brief' => $brief]);
        }
    }

    public function selectType(string $type): void
    {
        $this->type = $type;

        if ($this->conversation) {
            $this->conversation->update(['type' => $type]);
            $this->conversation->refresh();
        }
    }

    public function selectTopicsMode(string $mode): void
    {
        $this->topicsMode = $mode;

        if ($mode === 'discover') {
            $this->prompt = __('Search for current trends and news in my industry and propose 3-5 content topics.');
            $this->submitPrompt();
        }
    }

    public function selectWriterTopic(int $topicId): void
    {
        $topic = \App\Models\Topic::where('team_id', $this->teamModel->id)
            ->where('status', 'available')
            ->findOrFail($topicId);

        $this->topicId = $topic->id;
        $this->topicTitle = $topic->title;
        $this->prompt = __("Let's write a blog post about: :title", ['title' => $topic->title]);

        if ($this->conversation) {
            $this->conversation->update(['topic_id' => $topic->id]);
            $this->conversation->refresh();
        }
    }

    public function selectFreeForm(): void
    {
        $this->freeForm = true;
        $this->topicTitle = null;
    }

    public function stop(): void
    {
        if (! $this->conversation) {
            $this->isStreaming = false;
            return;
        }

        \Illuminate\Support\Facades\Cache::put('conv-stop:' . $this->conversation->id, true, 60);
    }

    public function submitPrompt(): void
    {
        $content = trim($this->prompt);

        if ($content === '' || $this->isStreaming || ! $this->type) {
            return;
        }

        if (! $this->teamModel->ai_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('API key required. Add it in Team Settings.'));
            return;
        }

        if (! $this->conversation) {
            $brief = $this->funnelGuidance ? ['funnel_guidance' => $this->funnelGuidance] : [];
            $this->conversation = Conversation::create([
                'team_id' => $this->teamModel->id,
                'user_id' => Auth::id(),
                'title' => mb_substr($content, 0, 80),
                'type' => $this->type,
                'topic_id' => $this->topicId ?: null,
                'content_piece_id' => $this->contentPieceId ?: null,
                'brief' => $brief,
            ]);
            $url = route('create.chat', ['current_team' => $this->teamModel, 'conversation' => $this->conversation]);
            $this->js("history.replaceState(null, '', '" . addslashes($url) . "')");
        } elseif ($this->conversation->title === __('New conversation')) {
            $this->conversation->update(['title' => mb_substr($content, 0, 80)]);
        }

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => $content,
        ]);

        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
            'metadata' => null,
        ];
        $this->messages[] = [
            'role' => 'assistant',
            'content' => '',
            'metadata' => null,
            'is_live' => true,
        ];
        $this->prompt = '';
        $this->isStreaming = true;

        RunConversationTurn::dispatch($this->teamModel->id, $this->conversation->id)
            ->onConnection('database_writer')
            ->onQueue('writer');
    }

    public function finishTurn(): void
    {
        if (! $this->conversation) {
            $this->isStreaming = false;
            return;
        }

        $this->conversation->refresh();
        $this->conversation->load('messages');
        $this->loadMessages();
        $this->isStreaming = false;
    }

    public function getConversationStatsProperty(): array
    {
        if (! $this->conversation) {
            return ['context' => 0];
        }

        $lastAssistant = $this->conversation->messages()->where('role', 'assistant')->latest('id')->first();

        // Last-turn token usage: input (history sent to model) + output
        // (what the model produced, including reasoning tokens for R1-class).
        // This is what the user pays for on the most recent turn — clearer
        // than just the input side, especially with reasoning models.
        return [
            'context' => (int) (($lastAssistant?->input_tokens ?? 0) + ($lastAssistant?->output_tokens ?? 0)),
        ];
    }

    public function render()
    {
        return $this->view()->title($this->conversation?->title ?? __('New conversation'));
    }

    public function loadMessages(): void
    {
        $this->messages = $this->conversation->messages
            ->map(function (Message $m) {
                $meta = $m->metadata;
                if (is_array($meta) && ! empty($meta['events'])) {
                    $meta['events'] = array_values(array_filter(
                        $meta['events'],
                        fn ($ev) => ! in_array(
                            $ev['type'] ?? '',
                            ['reasoning_chunk', 'subagent_reasoning_chunk'],
                            true,
                        ),
                    ));
                }
                return [
                    'role' => $m->role,
                    'content' => $this->cleanContent($m->content),
                    'metadata' => $meta,
                    'input_tokens' => $m->input_tokens,
                    'output_tokens' => $m->output_tokens,
                    'cost' => (float) $m->cost,
                ];
            })
            ->toArray();
    }

    public function buildItems(array $message): array
    {
        $items = [];
        $meta = $message['metadata'] ?? [];

        if (! empty($meta['reasoning'])) {
            $items[] = ['type' => 'reasoning', 'content' => $meta['reasoning']];
        }

        $events = $meta['events'] ?? [];
        $hasTextChunks = false;
        foreach ($events as $ev) {
            if (($ev['type'] ?? '') === 'text_chunk') { $hasTextChunks = true; break; }
        }

        $findLastSubagent = function (array $items, string $agent): ?int {
            for ($i = count($items) - 1; $i >= 0; $i--) {
                if (($items[$i]['type'] ?? '') === 'subagent' && ($items[$i]['agent'] ?? '') === $agent) {
                    return $i;
                }
            }
            return null;
        };
        $findPillById = function (array $items, string $id): ?int {
            for ($i = count($items) - 1; $i >= 0; $i--) {
                if (($items[$i]['type'] ?? '') === 'tool_pill' && ($items[$i]['id'] ?? '') === $id) {
                    return $i;
                }
            }
            return null;
        };

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            $p = $event['payload'] ?? [];
            $agent = $p['agent'] ?? '';

            if ($type === 'text_chunk') {
                $content = $p['content'] ?? '';
                $lastIdx = count($items) - 1;
                if ($lastIdx >= 0 && ($items[$lastIdx]['type'] ?? '') === 'text') {
                    $items[$lastIdx]['content'] .= $content;
                } else {
                    $items[] = ['type' => 'text', 'content' => $content];
                }
            } elseif ($type === 'subagent_started') {
                $items[] = [
                    'type' => 'subagent', 'agent' => $agent,
                    'title' => $p['title'] ?? '', 'color' => $p['color'] ?? 'zinc',
                    'status' => 'working', 'pills' => [], 'card' => null, 'message' => null,
                ];
            } elseif ($type === 'subagent_tool_call') {
                if ($agent === 'main') {
                    $items[] = [
                        'type' => 'tool_pill',
                        'id' => $p['id'] ?? '',
                        'name' => str_replace('_', ' ', $p['name'] ?? '?'),
                        'status' => $p['status'] ?? 'running',
                        'detail' => $p['detail'] ?? null,
                    ];
                } else {
                    $idx = $findLastSubagent($items, $agent);
                    if ($idx !== null) {
                        $items[$idx]['pills'][] = str_replace('_', ' ', $p['name'] ?? '?');
                    }
                }
            } elseif ($type === 'subagent_tool_call_status') {
                $idx = $findPillById($items, $p['id'] ?? '');
                if ($idx !== null) {
                    $items[$idx]['status'] = $p['status'] ?? 'ok';
                    if (! empty($p['error'])) $items[$idx]['error'] = $p['error'];
                }
            } elseif ($type === 'subagent_completed') {
                $idx = $findLastSubagent($items, $agent);
                if ($idx !== null) {
                    $items[$idx]['status'] = 'done';
                    $items[$idx]['card'] = $p['card'] ?? null;
                }
            } elseif ($type === 'subagent_error') {
                $idx = $findLastSubagent($items, $agent);
                if ($idx !== null) {
                    $items[$idx]['status'] = 'error';
                    $items[$idx]['message'] = $p['message'] ?? '';
                }
            }
        }

        // Backwards compat: messages from before we persisted text_chunk events
        // won't have inline text. Append the full content as one text item.
        if (! $hasTextChunks && ! empty($message['content'])) {
            $items[] = ['type' => 'text', 'content' => $message['content']];
        }

        foreach ($items as &$it) {
            if (($it['type'] ?? '') === 'subagent' && ($it['status'] ?? '') === 'working') {
                $it['status'] = 'done';
            }
        }
        unset($it);

        $hasStats = ! empty($meta['interrupted'])
            || ! empty($meta['web_searches'])
            || ($message['input_tokens'] ?? 0) + ($message['output_tokens'] ?? 0) > 0
            || ($message['cost'] ?? 0) > 0;
        if ($hasStats) {
            $items[] = [
                'type' => 'stats',
                'interrupted' => ! empty($meta['interrupted']),
                'web_searches' => $meta['web_searches'] ?? 0,
                'input_tokens' => $message['input_tokens'] ?? 0,
                'output_tokens' => $message['output_tokens'] ?? 0,
                'cost' => $message['cost'] ?? 0,
            ];
        }

        return $items;
    }

    private function cleanContent(string $content): string
    {
        $maxPasses = 5;
        for ($i = 0; $i < $maxPasses; $i++) {
            $cleaned = preg_replace(
                "/\[\{['\"]type['\"]: ['\"]text['\"], ['\"]text['\"]: ['\"](.+?)['\"]\}\]/s",
                '$1',
                $content
            );
            if ($cleaned === $content) {
                break;
            }
            $content = $cleaned;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded[0]['type']) && $decoded[0]['type'] === 'text') {
            $content = collect($decoded)->pluck('text')->implode('');
        }

        $content = str_replace(["\\'", '\\"', '\\n'], ["'", '"', "\n"], $content);

        // Collapse runs of 3+ newlines (multiple blank lines) down to a single
        // blank line. These appear when the model emits text chunks between
        // tool calls and each chunk starts/ends with its own newlines.
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('create')" wire:navigate />
            <flux:heading size="lg">{{ $conversation?->title ?? __('New conversation') }}</flux:heading>
            @if ($type)
                <flux:badge variant="pill" size="sm">{{ match($type) {
                    'brand' => __('Brand Knowledge'),
                    'topics' => __('Brainstorm'),
                    'writer' => __('Writer'),
                    'funnel' => __('Funnel'),
                    default => $type,
                } }}</flux:badge>
            @endif
        </div>
        @if ($this->conversationStats['context'] > 0)
            <flux:tooltip content="{{ __('Tokens used on the last turn (input + output). Longer chats grow the input each turn — start a new conversation when switching tasks to keep costs low.') }}" position="bottom">
                <div class="flex items-center gap-1.5 cursor-help">
                    <flux:text class="text-xs text-zinc-500">{{ number_format($this->conversationStats['context']) }} {{ __('last-turn tokens') }}</flux:text>
                    <flux:icon name="information-circle" variant="mini" class="size-3.5 text-zinc-400" />
                </div>
            </flux:tooltip>
        @endif
    </div>

    {{-- Messages --}}
    <div
        class="flex-1 overflow-y-auto"
        id="chat-scroll"
        x-data
        x-init="
            const el = $el;
            let stick = true;
            const atBottom = () => (el.scrollHeight - el.scrollTop - el.clientHeight) < 40;
            const scroll = () => el.scrollTop = el.scrollHeight;
            el.addEventListener('scroll', () => { stick = atBottom(); });
            scroll();
            stick = true;
            new MutationObserver(() => { if (stick) scroll(); }).observe(el, { childList: true, subtree: true, characterData: true });
        "
    >
        @php
            $pieceUrlTemplate = $conversation
                ? route('content.show', ['current_team' => $teamModel, 'contentPiece' => '__PIECE_ID__'])
                : '';
        @endphp
        <div class="mx-auto flex w-full max-w-5xl flex-col-reverse px-6 py-4">
            {{-- Unified message rendering (live + history) --}}
            @foreach (array_reverse($messages) as $idx => $message)
                @if ($message['role'] === 'user')
                    <div class="mb-6 flex justify-end" wire:key="user-{{ $idx }}-{{ md5($message['content']) }}">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-2.5 dark:bg-zinc-700">
                            <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                @else
                    @php
                        $isLive = ! empty($message['is_live']);
                        $items = $isLive ? [] : $this->buildItems($message);
                        $key = $isLive ? 'msg-live' : ('msg-' . $idx . '-' . md5(json_encode([$message['content'] ?? '', $message['metadata'] ?? null])));
                    @endphp
                    <div class="mb-6"
                        wire:key="{{ $key }}"
                        x-data="conversationStream({{ $conversation?->id ?? 0 }}, @js($items), {{ $isLive ? 'true' : 'false' }}, '{{ $pieceUrlTemplate }}')"
                    >
                        <div class="mb-1.5 flex items-center gap-2">
                            <flux:badge variant="pill" color="indigo" size="sm">AI</flux:badge>
                            <flux:icon.loading class="size-3.5 text-zinc-500" x-show="live && items.length === 0" />
                        </div>
                        <div class="text-sm">
                            <template x-if="live && items.length === 0">
                                <span class="inline-flex items-center gap-1.5 text-zinc-500">
                                    <flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}
                                </span>
                            </template>
                            <template x-for="(item, i) in items" :key="i">
                                <div>
                                    {{-- Reasoning (collapsible) --}}
                                    <template x-if="item.type === 'reasoning'">
                                        <div x-data="{ open: false }" class="mb-2">
                                            <button @click="open = !open" class="flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300 transition-colors">
                                                <svg x-bind:class="open ? 'rotate-180' : ''" class="size-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                {{ __('Reasoning') }}
                                            </button>
                                            <template x-if="item.streaming && item.content && !open">
                                                <div class="mt-1 rounded-md border border-zinc-700 bg-zinc-900/50 px-2.5 py-1.5">
                                                    <p class="truncate text-xs italic text-zinc-500"
                                                       x-text="(item.content.split('\n').filter(l => l.trim()).pop() || '')"></p>
                                                </div>
                                            </template>
                                            <div x-show="open" x-cloak class="mt-2 rounded-md border border-zinc-700 bg-zinc-900/50 p-3 text-xs text-zinc-400 whitespace-pre-wrap" x-text="item.content"></div>
                                        </div>
                                    </template>

                                    {{-- Text --}}
                                    <template x-if="item.type === 'text'">
                                        <p class="whitespace-pre-wrap text-sm mb-1"
                                           :class="item.content && item.content.startsWith('Error:') ? 'text-red-400' : ''"
                                           x-text="item.content"></p>
                                    </template>

                                    {{-- Inline tool pill (main agent) --}}
                                    <template x-if="item.type === 'tool_pill'">
                                        <span class="my-1 inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-xs border"
                                              :class="{
                                                'bg-zinc-800 text-zinc-300 border-zinc-700': item.status === 'running' || item.status === 'ok',
                                                'bg-red-500/10 text-red-300 border-red-500/30': item.status === 'error',
                                              }">
                                            <template x-if="item.status === 'running'">
                                                <flux:icon.loading class="size-3" />
                                            </template>
                                            <template x-if="item.status === 'ok'">
                                                <span class="text-emerald-400">&#10003;</span>
                                            </template>
                                            <template x-if="item.status === 'error'">
                                                <span class="text-red-400">&#9888;</span>
                                            </template>
                                            <span x-text="item.name"></span>
                                            <template x-if="item.detail">
                                                <span class="text-zinc-500 truncate max-w-xs">: <span x-text="item.detail"></span></span>
                                            </template>
                                        </span>
                                    </template>

                                    {{-- Subagent: working --}}
                                    <template x-if="item.type === 'subagent' && item.status === 'working'">
                                        <div class="mt-2 mb-4 rounded-lg border border-zinc-700 bg-zinc-900 p-3"
                                             x-data="{ reasoningOpen: false }">
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="flex items-center gap-2">
                                                    <span :class="'text-' + item.color + '-400'"><flux:icon.loading class="size-3.5" /></span>
                                                    <span class="text-xs font-semibold" :class="'text-' + item.color + '-400'" x-text="item.title"></span>
                                                    <span class="text-xs text-zinc-500">working…</span>
                                                </div>
                                                <template x-if="item.reasoning">
                                                    <button @click="reasoningOpen = !reasoningOpen" class="flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300 transition-colors">
                                                        <svg x-bind:class="reasoningOpen ? 'rotate-180' : ''" class="size-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                        {{ __('Reasoning') }}
                                                    </button>
                                                </template>
                                            </div>
                                            <template x-if="item.pills && item.pills.length > 0">
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    <template x-for="(pill, pi) in item.pills" :key="pi">
                                                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs bg-zinc-800 text-zinc-300 border border-zinc-700">
                                                            <svg class="size-3 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                                                            <span x-text="pill"></span>
                                                        </span>
                                                    </template>
                                                </div>
                                            </template>
                                            <template x-if="item.reasoning && !reasoningOpen">
                                                <p class="mt-2 truncate text-xs italic text-zinc-500"
                                                   x-text="(item.reasoning.split('\n').filter(l => l.trim()).pop() || '')"></p>
                                            </template>
                                            <template x-if="item.reasoning && reasoningOpen">
                                                <div x-cloak class="mt-2 rounded-md border border-zinc-700 bg-zinc-900/50 p-3 text-xs text-zinc-400 whitespace-pre-wrap" x-text="item.reasoning"></div>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Subagent: done --}}
                                    <template x-if="item.type === 'subagent' && item.status === 'done'">
                                        <div class="mt-2 mb-4 rounded-lg border border-zinc-700 bg-zinc-900 p-3"
                                             x-data="{ reasoningOpen: false }">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-xs font-semibold" :class="'text-' + item.color + '-400'">&#10003; <span x-text="item.title"></span></span>
                                                <div class="flex items-center gap-2">
                                                    <template x-if="item.card && item.card.reasoning">
                                                        <button @click="reasoningOpen = !reasoningOpen" class="flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-300 transition-colors">
                                                            <svg x-bind:class="reasoningOpen ? 'rotate-180' : ''" class="size-3.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                            {{ __('Reasoning') }}
                                                        </button>
                                                    </template>
                                                    <template x-if="item.card && item.card.piece_id">
                                                        <a :href="pieceUrl(item.card.piece_id)" class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('Open') }} &rarr;</a>
                                                    </template>
                                                    <template x-if="item.card && item.card.social_url">
                                                        <a :href="item.card.social_url" class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('View posts') }} &rarr;</a>
                                                    </template>
                                                    <template x-if="item.card && item.card.topics_url">
                                                        <a :href="item.card.topics_url" class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('View topics') }} &rarr;</a>
                                                    </template>
                                                </div>
                                            </div>

                                            <template x-if="item.card && item.card.reasoning && reasoningOpen">
                                                <div x-cloak class="mt-2 rounded-md border border-zinc-700 bg-zinc-900/50 p-3 text-xs text-zinc-400 whitespace-pre-wrap" x-text="item.card.reasoning"></div>
                                            </template>

                                            {{-- Pills (top-level "Tools used" rolled up) --}}
                                            <template x-if="item.pills && item.pills.length > 0">
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    <template x-for="(pill, pi) in item.pills" :key="pi">
                                                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs bg-zinc-800 text-zinc-400 border border-zinc-700" x-text="pill"></span>
                                                    </template>
                                                </div>
                                            </template>

                                            {{-- Generic summary (only when card has no rich kind) --}}
                                            <template x-if="item.card && item.card.summary && !item.card.kind">
                                                <p class="mt-1 text-xs text-zinc-400" x-text="item.card.summary"></p>
                                            </template>

                                            {{-- content_piece (writer/proofread) --}}
                                            <template x-if="item.card && item.card.kind === 'content_piece'">
                                                <div class="mt-1">
                                                    <div class="text-xs text-zinc-500" x-text="item.card.summary"></div>
                                                    <div class="text-sm font-semibold text-zinc-200 mt-1" x-text="item.card.title"></div>
                                                    <div class="mt-1 text-xs text-zinc-400 line-clamp-3" x-text="item.card.preview"></div>
                                                </div>
                                            </template>

                                            {{-- research card --}}
                                            <template x-if="item.card && item.card.kind === 'research'">
                                                <ul class="mt-1 list-disc pl-5">
                                                    <template x-for="(c, ci) in (item.card.claims || []).slice(0, 5)" :key="ci">
                                                        <li class="text-xs text-zinc-400" x-text="c.text"></li>
                                                    </template>
                                                </ul>
                                            </template>
                                            <template x-if="item.card && item.card.kind === 'research' && (item.card.claims || []).length > 5">
                                                <div class="mt-1 text-xs text-zinc-500">…and <span x-text="item.card.claims.length - 5"></span> more</div>
                                            </template>

                                            {{-- audience card --}}
                                            <template x-if="item.card && item.card.kind === 'audience'">
                                                <div class="mt-1 text-xs text-zinc-400" x-text="item.card.guidance_for_writer || ''"></div>
                                            </template>

                                            {{-- outline card --}}
                                            <template x-if="item.card && item.card.kind === 'outline'">
                                                <ul class="mt-1 list-disc pl-5">
                                                    <template x-for="(s, si) in (item.card.sections || [])" :key="si">
                                                        <li class="text-xs text-zinc-400" x-text="s.heading"></li>
                                                    </template>
                                                </ul>
                                            </template>

                                            {{-- style_reference card --}}
                                            <template x-if="item.card && item.card.kind === 'style_reference'">
                                                <ul class="mt-1 list-none">
                                                    <template x-for="(ex, ei) in (item.card.examples || [])" :key="ei">
                                                        <li class="text-xs text-zinc-400">· <span x-text="ex.title || ''"></span></li>
                                                    </template>
                                                </ul>
                                            </template>

                                            {{-- topics card --}}
                                            <template x-if="item.card && item.card.kind === 'topics'">
                                                <div class="mt-1">
                                                    <div class="text-xs text-zinc-500" x-text="item.card.summary"></div>
                                                    <ul class="mt-1 list-disc pl-5">
                                                        <template x-for="(title, ti) in (item.card.titles || [])" :key="ti">
                                                            <li class="text-xs text-zinc-300" x-text="title"></li>
                                                        </template>
                                                    </ul>
                                                </div>
                                            </template>

                                            {{-- brand_update card --}}
                                            <template x-if="item.card && item.card.kind === 'brand_update'">
                                                <div class="mt-1">
                                                    <div class="text-xs text-zinc-500" x-text="item.card.summary"></div>
                                                    <template x-if="(item.card.sections || []).length > 0">
                                                        <div class="mt-1 flex flex-wrap gap-1">
                                                            <template x-for="(section, si) in item.card.sections" :key="si">
                                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs bg-sky-500/10 text-sky-300 border border-sky-500/30 capitalize" x-text="section"></span>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>

                                            {{-- social_posts card --}}
                                            <template x-if="item.card && item.card.kind === 'social_posts'">
                                                <div class="mt-2 space-y-2">
                                                    <template x-for="(post, pi) in (item.card.posts || [])" :key="pi">
                                                        <div class="rounded-md border border-zinc-700 bg-zinc-900/50 p-2">
                                                            <div class="flex items-center gap-2 mb-1">
                                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-pink-500/10 text-pink-300 border border-pink-500/30" x-text="post.platform"></span>
                                                                <span class="text-xs font-semibold text-zinc-200 truncate" x-text="post.hook"></span>
                                                            </div>
                                                            <p class="text-xs text-zinc-400 line-clamp-2" x-text="post.preview"></p>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Subagent: error --}}
                                    <template x-if="item.type === 'subagent' && item.status === 'error'">
                                        <div class="mt-2 mb-4 rounded-lg border border-red-900/50 bg-zinc-900 p-3">
                                            <span class="text-xs text-red-400">&#9888; <span x-text="item.title || item.agent"></span> failed</span>
                                            <p class="mt-1 text-xs text-zinc-500" x-text="item.message || ''"></p>
                                        </div>
                                    </template>

                                    {{-- Stats footer --}}
                                    <template x-if="item.type === 'stats'">
                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            <template x-if="item.interrupted">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs text-amber-500">&#9888; {{ __('Interrupted') }}</span>
                                            </template>
                                            <template x-if="item.web_searches > 0">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500"><span x-text="item.web_searches"></span> web searches</span>
                                            </template>
                                            <template x-if="(item.input_tokens + item.output_tokens) > 0">
                                                <span class="text-xs text-zinc-500"><span x-text="(item.input_tokens + item.output_tokens).toLocaleString()"></span> tokens</span>
                                            </template>
                                            <template x-if="item.cost > 0">
                                                <span class="text-xs text-zinc-500">&middot; $<span x-text="item.cost.toFixed(4)"></span></span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Type selection (no type yet, no messages) --}}
            @if (!$type && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose a mode to get started.') }}</flux:subheading>

                    <div class="grid w-full max-w-4xl gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <button wire:click="selectType('brand')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="building-storefront" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Build brand knowledge') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Improve copywriting performance with deep brand understanding') }}</flux:text>
                        </button>

                        <button wire:click="selectType('topics')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="light-bulb" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Brainstorm topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Generate fresh content ideas for your audience') }}</flux:text>
                        </button>

                        <button wire:click="selectType('writer')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="document-text" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Write a blog post') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Produce a cornerstone blog post grounded in one of your topics') }}</flux:text>
                        </button>

                        <button wire:click="selectType('funnel')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="megaphone" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Build a Funnel') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Turn a content piece into 3–6 social posts that drive traffic back to it') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Topics sub-card selection --}}
            @if ($type === 'topics' && !$topicsMode && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('How would you like to brainstorm?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose how to discover topics.') }}</flux:subheading>

                    <div class="grid w-full max-w-xl gap-3 sm:grid-cols-2">
                        <button wire:click="selectTopicsMode('discover')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="magnifying-glass" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Auto-discover topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Research trends and discover topics for your brand automatically') }}</flux:text>
                        </button>

                        <button wire:click="selectTopicsMode('conversation')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="chat-bubble-left" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Start a conversation') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Guide the brainstorming with your own direction') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Writer: Topic picker (required before mode) --}}
            @if ($type === 'writer' && !$topicId && !$freeForm && empty($messages))
                @php
                    $availableTopics = \App\Models\Topic::where('team_id', $teamModel->id)
                        ->where('status', 'available')
                        ->latest()
                        ->get();
                @endphp

                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('Pick a topic for this blog post') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('The writer grounds the post in one of your topics.') }}</flux:subheading>

                    <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                        <button wire:click="selectFreeForm" class="group cursor-pointer rounded-xl border border-dashed border-zinc-300 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-600 dark:hover:border-indigo-500">
                            <flux:heading size="sm">{{ __('Free form') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Write about anything — no topic required.') }}</flux:text>
                        </button>
                        @foreach ($availableTopics as $t)
                            <button wire:click="selectWriterTopic({{ $t->id }})" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                                <flux:heading size="sm">{{ $t->title }}</flux:heading>
                                <flux:text class="mt-1 text-xs">{{ $t->angle }}</flux:text>
                            </button>
                        @endforeach
                    </div>
                    @if ($availableTopics->isEmpty())
                        <p class="mt-4 text-sm text-zinc-500">
                            {{ __('No topics yet.') }}
                            <a href="{{ route('topics', ['current_team' => $teamModel]) }}" class="text-indigo-400 hover:underline" wire:navigate>{{ __('Brainstorm topics') }}</a>
                        </p>
                    @endif
                </div>
            @endif

            {{-- Funnel: Content piece picker (required before input) --}}
            @if ($type === 'funnel' && !$contentPieceId && empty($messages))
                @php
                    $availablePieces = \App\Models\ContentPiece::where('team_id', $teamModel->id)
                        ->latest()->get();
                @endphp
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('Pick a content piece') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('The funnel will drive traffic back to this piece.') }}</flux:subheading>
                    @if ($availablePieces->isEmpty())
                        <p class="text-sm text-zinc-500">
                            {{ __('No content pieces yet.') }}
                            <a href="{{ route('content.index', ['current_team' => $teamModel]) }}" class="text-indigo-400 hover:underline" wire:navigate>{{ __('Browse Content') }}</a>
                        </p>
                    @else
                        <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                            @foreach ($availablePieces as $cp)
                                <button wire:click="selectFunnelContentPiece({{ $cp->id }})" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                                    <flux:heading size="sm">{{ $cp->title }}</flux:heading>
                                    <flux:text class="mt-1 text-xs line-clamp-2">{{ mb_substr(strip_tags($cp->body ?? ''), 0, 160) }}</flux:text>
                                </button>
                            @endforeach
                        </div>
                        <div class="mt-6 w-full max-w-2xl">
                            <flux:input
                                wire:model.blur="funnelGuidance"
                                placeholder="{{ __('Optional: angle for the funnel (e.g., focus on the founder story)') }}" />
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>

    {{-- Input (only shown after type is selected) --}}
    @if ($type
        && !($type === 'topics' && !$topicsMode && empty($messages))
        && !($type === 'writer' && !$topicId && !$freeForm && empty($messages))
        && !($type === 'funnel' && !$contentPieceId && empty($messages)))
        @if ($type === 'writer' && ($topicTitle || $freeForm))
            <div class="mx-auto w-full max-w-5xl px-6 pb-1">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    <flux:icon name="document-text" class="inline size-3.5 -mt-0.5 mr-0.5" />
                    @if ($freeForm && !$topicTitle)
                        {{ __('Free writing') }}
                    @else
                        {{ __('Writing about: :title', ['title' => $topicTitle ?? '']) }}
                    @endif
                </p>
            </div>
        @endif
        @if ($type === 'funnel' && $contentPieceTitle)
            <div class="mx-auto w-full max-w-5xl px-6 pb-1">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    <flux:icon name="megaphone" class="inline size-3.5 -mt-0.5 mr-0.5" />
                    {{ __('Funnel for: :title', ['title' => $contentPieceTitle]) }}
                </p>
            </div>
        @endif

        <div class="mx-auto w-full max-w-5xl px-6 pb-4 pt-2">
            @if ($isStreaming)
                <div class="flex justify-center" x-data="{ stopping: false }">
                    <flux:button variant="danger" size="sm" icon="stop-circle"
                        :disabled="false"
                        x-bind:disabled="stopping"
                        x-on:click="
                            stopping = true;
                            fetch('{{ route('conversations.stop', ['conversation' => $conversation->id]) }}', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                                credentials: 'same-origin',
                            });
                        ">
                        <span x-text="stopping ? '{{ __('Stopping…') }}' : '{{ __('Stop generating') }}'"></span>
                    </flux:button>
                </div>
            @else
                <form wire:submit="submitPrompt">
                    <flux:composer
                        wire:model="prompt"
                        submit="enter"
                        label="Message"
                        label:sr-only
                        placeholder="{{ __('Type your message...') }}"
                        rows="1"
                        max-rows="6"
                    >
                        <x-slot name="actionsTrailing">
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" />
                        </x-slot>
                    </flux:composer>
                </form>
            @endif
        </div>
    @endif
</div>
