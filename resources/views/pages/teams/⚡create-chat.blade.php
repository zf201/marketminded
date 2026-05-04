<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\ChatPromptBuilder;
use App\Services\CreateOutlineToolHandler;
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\OpenRouterClient;
use App\Services\PickAudienceToolHandler;
use App\Services\ResearchTopicToolHandler;
use App\Services\BraveSearchClient;
use App\Services\ConversationBus;
use App\Services\StreamResult;
use App\Services\TurnStoppedException;
use App\Services\TopicToolHandler;
use App\Services\ProofreadBlogPostToolHandler;
use App\Services\SocialPostToolHandler;
use App\Services\UrlFetcher;
use App\Services\WriteBlogPostToolHandler;
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

    private function persistTurn(
        ConversationBus $bus,
        ?StreamResult $streamResult,
        bool $interrupted,
    ): void {
        $metadata = [];

        $events = $bus->events();
        if (! empty($events)) {
            $metadata['events'] = $events;
        }

        if ($streamResult && $streamResult->webSearchRequests > 0) {
            $metadata['web_searches'] = $streamResult->webSearchRequests;
        }

        $reasoning = $streamResult?->reasoningContent ?: '';
        if ($reasoning !== '') {
            $metadata['reasoning'] = $reasoning;
        }

        if ($streamResult && $streamResult->reasoningTokens > 0) {
            $metadata['reasoning_tokens'] = $streamResult->reasoningTokens;
        }

        if ($interrupted) {
            $metadata['interrupted'] = true;
        }

        // cleanContent() is called once on the full accumulated string (not per-chunk).
        $content = $this->cleanContent($bus->text());

        if ($content === '' && empty($metadata)) {
            return;
        }

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role'            => 'assistant',
            'content'         => $content,
            'model'           => $this->teamModel->fast_model,
            'input_tokens'    => $streamResult?->inputTokens ?? 0,
            'output_tokens'   => $streamResult?->outputTokens ?? 0,
            'cost'            => $streamResult?->cost ?? 0,
            'metadata'        => ! empty($metadata) ? $metadata : null,
        ]);

        if ($message) {
            $this->messages[] = [
                'role'          => 'assistant',
                'content'       => $message->content,
                'metadata'      => $message->metadata,
                'input_tokens'  => $message->input_tokens,
                'output_tokens' => $message->output_tokens,
                'cost'          => (float) $message->cost,
            ];
        }
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

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        set_time_limit(900);
        ignore_user_abort(true);

        $type = $this->conversation->type;
        $this->teamModel->refresh();
        $this->conversation->load('topic');

        if ($type === 'writer' && $this->conversation->topic && empty(($this->conversation->brief ?? [])['topic'])) {
            $topic = $this->conversation->topic;
            $brief = $this->conversation->brief ?? [];
            $brief['topic'] = [
                'id'      => $topic->id,
                'title'   => $topic->title,
                'angle'   => $topic->angle,
                'sources' => $topic->sources ?? [],
            ];
            $this->conversation->update(['brief' => $brief]);
            $this->conversation->refresh();
        }

        $systemPrompt = ChatPromptBuilder::build($type, $this->teamModel, $this->conversation);
        $tools        = ChatPromptBuilder::tools($type);

        $apiMessages = collect($this->messages)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->toArray();

        $webSearchProvider = $this->teamModel->web_search_provider ?? 'openrouter_builtin';
        $braveClient = ($webSearchProvider === 'brave' && $this->teamModel->brave_api_key)
            ? new BraveSearchClient($this->teamModel->brave_api_key)
            : null;

        $client = new OpenRouterClient(
            apiKey: $this->teamModel->ai_api_key,
            model: $this->teamModel->fast_model,
            urlFetcher: new UrlFetcher,
            maxIterations: 8,
            baseUrl: $this->teamModel->ai_api_url ?? 'https://openrouter.ai/api/v1',
            provider: $this->teamModel->ai_provider ?? 'openrouter',
            braveSearchClient: $braveClient,
        );

        $brandHandler     = new BrandIntelligenceToolHandler;
        $topicHandler     = new TopicToolHandler;
        $researchHandler  = new ResearchTopicToolHandler;
        $audienceHandler  = new PickAudienceToolHandler;
        $outlineHandler   = new CreateOutlineToolHandler;
        $styleRefHandler  = new FetchStyleReferenceToolHandler;
        $writeHandler     = new WriteBlogPostToolHandler;
        $proofreadHandler = new ProofreadBlogPostToolHandler;
        $socialHandler    = new SocialPostToolHandler;
        $team             = $this->teamModel;
        $conversation     = $this->conversation;

        $priorTurnTools = [];

        $bus = new ConversationBus($this->conversation->id);
        session()->save();

        $toolExecutor = function (string $name, array $args) use (
            $brandHandler, $topicHandler, $researchHandler, $audienceHandler, $outlineHandler,
            $styleRefHandler, $writeHandler, $proofreadHandler, $socialHandler,
            $team, $conversation, $bus, &$priorTurnTools
        ): string {
            if ($name === 'update_brand_intelligence') {
                return $brandHandler->execute($team, $args);
            }
            if ($name === 'save_topics') {
                $bus->publish('subagent_started', ['agent' => 'topics', 'title' => 'Saving topics', 'color' => 'teal']);
                $result = $topicHandler->execute($team, $conversation->id, $args);
                $saved = json_decode($result, true)['count'] ?? 0;
                $bus->publish('subagent_completed', ['agent' => 'topics', 'card' => ['summary' => "Saved {$saved} topic(s) to backlog"]]);
                return $result;
            }
            if ($name === 'fetch_url') {
                return (new UrlFetcher)->fetch($args['url'] ?? '');
            }
            if ($name === 'research_topic') {
                $result = $researchHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'pick_audience') {
                $result = $audienceHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'create_outline') {
                $result = $outlineHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'fetch_style_reference') {
                $result = $styleRefHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'write_blog_post') {
                $result = $writeHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'proofread_blog_post') {
                $result = $proofreadHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'propose_posts') {
                $piece = $conversation->contentPiece;
                if (! $piece) {
                    return json_encode(['status' => 'error', 'message' => 'No content piece is associated with this conversation.']);
                }
                return $socialHandler->propose($team, $conversation->id, $piece, $args);
            }
            if ($name === 'update_post') {
                return $socialHandler->update($team, $conversation->id, $args);
            }
            if ($name === 'delete_post') {
                return $socialHandler->delete($team, $args);
            }
            if ($name === 'replace_all_posts') {
                $piece = $conversation->contentPiece;
                if (! $piece) {
                    return json_encode(['status' => 'error', 'message' => 'No content piece is associated with this conversation.']);
                }
                return $socialHandler->replaceAll($team, $conversation->id, $piece, $args);
            }
            return "Unknown tool: {$name}";
        };

        $useServerTools = $this->teamModel->ai_provider !== 'custom'
            && $webSearchProvider === 'openrouter_builtin';
        $chatTools = $tools;
        if ($braveClient !== null) {
            $chatTools[] = BraveSearchClient::toolSchema();
        }

        try {
            $streamResult = $client->streamChatWithTools(
                systemPrompt: $systemPrompt,
                messages: $apiMessages,
                tools: $chatTools,
                toolExecutor: $toolExecutor,
                temperature: 0.7,
                useServerTools: $useServerTools,
                bus: $bus,
            );

            $this->writeChatDebugLog($systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), $streamResult, false);
            $this->persistTurn($bus, $streamResult, interrupted: false);
            $bus->publish('turn_complete');

        } catch (TurnStoppedException) {
            $this->writeChatDebugLog($systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), null, true);
            $this->persistTurn($bus, streamResult: null, interrupted: true);
            try {
                $bus->publish('turn_interrupted');
            } catch (TurnStoppedException) {
                broadcast(new \App\Events\ConversationEvent($this->conversation->id, 'turn_interrupted', []));
            }

        } catch (\Throwable $e) {
            $this->writeChatDebugLog($systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), null, true);
            $this->persistTurn($bus, streamResult: null, interrupted: true);
            \Log::error('ask() failed', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            try {
                $bus->publish('turn_error', ['message' => $e->getMessage()]);
            } catch (TurnStoppedException) {
                broadcast(new \App\Events\ConversationEvent($this->conversation->id, 'turn_error', ['message' => $e->getMessage()]));
            }
        }

        $this->loadMessages();
        $this->isStreaming = false;
    }

    private function writeChatDebugLog(
        string $systemPrompt,
        array $tools,
        array $apiMessages,
        string $responseContent,
        array $busEvents,
        ?StreamResult $streamResult,
        bool $interrupted,
    ): void {
        $entry = [
            'ts'               => now()->toIso8601String(),
            'conversation_id'  => $this->conversation->id,
            'type'             => $this->conversation->type,
            'topic_id'         => $this->conversation->topic_id,
            'team_id'          => $this->teamModel->id,
            'model'            => $this->teamModel->fast_model,
            'system_prompt'    => $systemPrompt,
            'tool_schemas'     => array_map(fn ($t) => $t['function']['name'] ?? ($t['type'] ?? 'unknown'), $tools),
            'history_sent'     => $apiMessages,
            'response_content' => $responseContent,
            'bus_events'       => array_map(fn ($e) => ['type' => $e['type'], 'agent' => $e['payload']['agent'] ?? null], $busEvents),
            'input_tokens'     => $streamResult?->inputTokens ?? 0,
            'output_tokens'    => $streamResult?->outputTokens ?? 0,
            'cost'             => (float) ($streamResult?->cost ?? 0),
            'web_searches'     => (int) ($streamResult?->webSearchRequests ?? 0),
            'interrupted'      => $interrupted,
        ];

        try {
            $path = storage_path('logs/chat-debug.log');
            file_put_contents(
                $path,
                json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable $e) {
            \Log::warning('chat-debug log write failed', ['error' => $e->getMessage()]);
        }
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
            ->map(fn (Message $m) => [
                'role' => $m->role,
                'content' => $this->cleanContent($m->content),
                'metadata' => $m->metadata,
                'input_tokens' => $m->input_tokens,
                'output_tokens' => $m->output_tokens,
                'cost' => (float) $m->cost,
            ])
            ->toArray();
    }

    public function buildItems(array $message): array
    {
        $items = [];
        $meta = $message['metadata'] ?? [];

        if (! empty($meta['reasoning'])) {
            $items[] = ['type' => 'reasoning', 'content' => $meta['reasoning']];
        }

        if (! empty($message['content'])) {
            $items[] = ['type' => 'text', 'content' => $message['content']];
        }

        $findLastAgent = function (array &$items, string $agent): ?int {
            for ($i = count($items) - 1; $i >= 0; $i--) {
                if (($items[$i]['type'] ?? '') === 'subagent' && ($items[$i]['agent'] ?? '') === $agent) {
                    return $i;
                }
            }
            return null;
        };

        foreach ($meta['events'] ?? [] as $event) {
            $type = $event['type'] ?? '';
            $p = $event['payload'] ?? [];
            $agent = $p['agent'] ?? '';

            if ($type === 'subagent_started') {
                $items[] = [
                    'type' => 'subagent', 'agent' => $agent,
                    'title' => $p['title'] ?? '', 'color' => $p['color'] ?? 'zinc',
                    'status' => 'working', 'pills' => [], 'card' => null, 'message' => null,
                ];
            } elseif ($type === 'subagent_tool_call') {
                $idx = $findLastAgent($items, $agent);
                if ($idx === null) {
                    $items[] = [
                        'type' => 'subagent', 'agent' => $agent,
                        'title' => 'Tools used', 'color' => 'zinc',
                        'status' => 'working', 'pills' => [], 'card' => null, 'message' => null,
                    ];
                    $idx = count($items) - 1;
                }
                $items[$idx]['pills'][] = str_replace('_', ' ', $p['name'] ?? '?');
            } elseif ($type === 'subagent_completed') {
                $idx = $findLastAgent($items, $agent);
                if ($idx !== null) {
                    $items[$idx]['status'] = 'done';
                    $items[$idx]['card'] = $p['card'] ?? null;
                }
            } elseif ($type === 'subagent_error') {
                $idx = $findLastAgent($items, $agent);
                if ($idx !== null) {
                    $items[$idx]['status'] = 'error';
                    $items[$idx]['message'] = $p['message'] ?? '';
                }
            }
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
            const shouldScroll = () => $wire.isStreaming || ($wire.messages && $wire.messages.length > 0);
            const scroll = () => el.scrollTop = el.scrollHeight;
            if (shouldScroll()) scroll();
            new MutationObserver(() => { if (shouldScroll()) scroll(); }).observe(el, { childList: true, subtree: true, characterData: true });
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
                                            <div x-show="open" x-cloak class="mt-2 rounded-md border border-zinc-700 bg-zinc-900/50 p-3 text-xs text-zinc-400 whitespace-pre-wrap" x-text="item.content"></div>
                                        </div>
                                    </template>

                                    {{-- Text --}}
                                    <template x-if="item.type === 'text'">
                                        <p class="whitespace-pre-wrap text-sm mb-1"
                                           :class="item.content && item.content.startsWith('Error:') ? 'text-red-400' : ''"
                                           x-text="item.content"></p>
                                    </template>

                                    {{-- Subagent: working --}}
                                    <template x-if="item.type === 'subagent' && item.status === 'working'">
                                        <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                            <div class="flex items-center gap-2">
                                                <span :class="'text-' + item.color + '-400'"><flux:icon.loading class="size-3.5" /></span>
                                                <span class="text-xs font-semibold" :class="'text-' + item.color + '-400'" x-text="item.title"></span>
                                                <span class="text-xs text-zinc-500">working…</span>
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
                                        </div>
                                    </template>

                                    {{-- Subagent: done --}}
                                    <template x-if="item.type === 'subagent' && item.status === 'done'">
                                        <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-xs font-semibold" :class="'text-' + item.color + '-400'">&#10003; <span x-text="item.title"></span></span>
                                                <template x-if="item.card && item.card.piece_id">
                                                    <a :href="pieceUrl(item.card.piece_id)" class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('Open') }} &rarr;</a>
                                                </template>
                                            </div>

                                            {{-- Pills (top-level "Tools used" rolled up) --}}
                                            <template x-if="item.pills && item.pills.length > 0">
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    <template x-for="(pill, pi) in item.pills" :key="pi">
                                                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs bg-zinc-800 text-zinc-400 border border-zinc-700" x-text="pill"></span>
                                                    </template>
                                                </div>
                                            </template>

                                            {{-- Generic summary --}}
                                            <template x-if="item.card && item.card.summary && item.card.kind !== 'content_piece'">
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
                                        </div>
                                    </template>

                                    {{-- Subagent: error --}}
                                    <template x-if="item.type === 'subagent' && item.status === 'error'">
                                        <div class="mt-2 rounded-lg border border-red-900/50 bg-zinc-900 p-3">
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

