<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\ChatPromptBuilder;
use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\TopicToolHandler;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public Conversation $conversation;

    public string $prompt = '';

    public bool $isStreaming = false;

    public ?string $topicsMode = null;

    public array $messages = [];

    public function mount(Team $current_team, Conversation $conversation): void
    {
        $this->teamModel = $current_team;
        $this->conversation = $conversation;
        $this->loadMessages();
    }

    public function selectType(string $type): void
    {
        $this->conversation->update(['type' => $type]);
        $this->conversation->refresh();
    }

    public function selectTopicsMode(string $mode): void
    {
        $this->topicsMode = $mode;

        if ($mode === 'discover') {
            $this->prompt = __('Search for current trends and news in my industry and propose 3-5 content topics.');
            $this->submitPrompt();
        }
    }

    public function submitPrompt(): void
    {
        $content = trim($this->prompt);

        if ($content === '' || $this->isStreaming || ! $this->conversation->type) {
            return;
        }

        if (! $this->teamModel->openrouter_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('OpenRouter API key required. Add it in Team Settings.'));
            return;
        }

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => $content,
        ]);

        if ($this->conversation->title === __('New conversation')) {
            $this->conversation->update(['title' => mb_substr($content, 0, 80)]);
        }

        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
            'metadata' => null,
        ];
        $this->prompt = '';
        $this->isStreaming = true;

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        set_time_limit(300);
        ignore_user_abort(false);

        $type = $this->conversation->type;
        $this->teamModel->refresh();
        $systemPrompt = ChatPromptBuilder::build($type, $this->teamModel);
        $tools = ChatPromptBuilder::tools($type);

        $apiMessages = collect($this->messages)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->toArray();

        $client = new OpenRouterClient(
            apiKey: $this->teamModel->openrouter_api_key,
            model: $this->teamModel->fast_model,
            urlFetcher: new UrlFetcher,
            maxIterations: 8,
        );

        $brandHandler = new BrandIntelligenceToolHandler;
        $topicHandler = new TopicToolHandler;
        $team = $this->teamModel;
        $conversation = $this->conversation;

        $toolExecutor = function (string $name, array $args) use ($brandHandler, $topicHandler, $team, $conversation): string {
            if ($name === 'update_brand_intelligence') {
                return $brandHandler->execute($team, $args);
            }
            if ($name === 'save_topics') {
                return $topicHandler->execute($team, $conversation->id, $args);
            }
            if ($name === 'fetch_url') {
                return (new UrlFetcher)->fetch($args['url'] ?? '');
            }
            return "Unknown tool: {$name}";
        };

        $fullContent = '';
        $streamResult = null;
        $completedTools = [];
        $interrupted = false;

        try {
            foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor) as $item) {
                if ($item instanceof ToolEvent) {
                    if ($item->status === 'completed') {
                        $completedTools[] = $item;
                    }
                    $activeTool = $item->status === 'started' ? $item : null;
                    $this->streamUI($this->cleanContent($fullContent), $completedTools, $activeTool);
                } elseif ($item instanceof StreamResult) {
                    $streamResult = $item;
                } else {
                    $fullContent .= $item;
                    $this->streamUI($this->cleanContent($fullContent), $completedTools, null);
                }
            }
        } catch (\Throwable $e) {
            $interrupted = true;
            \Log::error('Chat streaming error', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            if (! $fullContent && empty($completedTools)) {
                $fullContent = 'Sorry, something went wrong. Please try again.';
            }
        } finally {
            // Always save the message, even if the connection was interrupted.
            // Tool side effects (e.g. Topic::create) are already committed,
            // so the message metadata must be persisted to render cards.
            ignore_user_abort(true);

            $fullContent = $this->cleanContent($fullContent);

            $metadata = [];
            if (! empty($completedTools)) {
                $metadata['tools'] = collect($completedTools)->map(fn (ToolEvent $t) => [
                    'name' => $t->name,
                    'args' => $t->arguments,
                ])->toArray();
            }
            if ($streamResult?->webSearchRequests > 0) {
                $metadata['web_searches'] = $streamResult->webSearchRequests;
            }
            if ($interrupted) {
                $metadata['interrupted'] = true;
            }

            // Only save if there's content or tool calls (avoid empty messages)
            if ($fullContent !== '' || ! empty($metadata)) {
                Message::create([
                    'conversation_id' => $this->conversation->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $this->teamModel->fast_model,
                    'input_tokens' => $streamResult?->inputTokens ?? 0,
                    'output_tokens' => $streamResult?->outputTokens ?? 0,
                    'cost' => $streamResult?->cost ?? 0,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                ]);

                $this->messages[] = [
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                    'input_tokens' => $streamResult?->inputTokens ?? 0,
                    'output_tokens' => $streamResult?->outputTokens ?? 0,
                    'cost' => $streamResult?->cost ?? 0,
                ];
            }

            $this->isStreaming = false;
        }
    }

    public function getConversationStatsProperty(): array
    {
        $lastAssistant = $this->conversation->messages()->where('role', 'assistant')->latest('id')->first();

        return [
            'context' => (int) ($lastAssistant?->input_tokens ?? 0),
        ];
    }

    public function render()
    {
        return $this->view()->title($this->conversation->title);
    }

    private function loadMessages(): void
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

        return $content;
    }

    /**
     * Stream UI: text content first, then tools below as pills, then active tool.
     */
    private function streamUI(string $content, array $completedTools, ?ToolEvent $activeTool): void
    {
        $html = '';

        // Text content first
        if ($content !== '') {
            $html .= '<div class="whitespace-pre-wrap">' . e($content) . '</div>';
        }

        // Active tool (in progress) — show below text
        if ($activeTool) {
            $label = match ($activeTool->name) {
                'fetch_url' => 'Reading ' . ($activeTool->arguments['url'] ?? ''),
                'update_brand_intelligence' => 'Updating brand profile',
                'save_topics' => 'Saving topics...',
                default => $activeTool->name,
            };
            $html .= '<div class="mt-2 flex flex-wrap items-center gap-1.5">';
            // Show completed tools as pills first
            foreach ($completedTools as $tool) {
                $html .= $this->toolPill($tool, false);
            }
            $html .= '<span class="inline-flex items-center gap-1 rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-xs text-indigo-400"><span class="animate-spin">&#8635;</span> ' . e($label) . '</span>';
            $html .= '</div>';
        } elseif (! empty($completedTools)) {
            // All tools done — show as pills below text
            $html .= '<div class="mt-2 flex flex-wrap items-center gap-1.5">';
            foreach ($completedTools as $tool) {
                $html .= $this->toolPill($tool, false);
            }
            $html .= '</div>';
        } elseif ($content === '') {
            // Nothing yet — thinking
            $html .= '<span class="inline-flex items-center gap-1.5 text-zinc-500"><span class="size-3.5 animate-spin">&#8635;</span> Thinking...</span>';
        }

        // Saved topic cards
        $html .= $this->savedTopicCards($completedTools);

        $this->stream(to: 'streamed-response', content: $html, replace: true);
    }

    private function toolPill(ToolEvent $tool, bool $active): string
    {
        $label = match ($tool->name) {
            'fetch_url' => 'Read ' . ($tool->arguments['url'] ?? ''),
            'update_brand_intelligence' => 'Updated profile: ' . implode(', ', json_decode($tool->result ?? '{}', true)['sections'] ?? []),
            'save_topics' => 'Saved ' . (json_decode($tool->result ?? '{}', true)['count'] ?? 0) . ' topics',
            default => $tool->name,
        };

        $classes = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs';
        $classes .= $active
            ? ' bg-indigo-500/10 text-indigo-400'
            : ' bg-zinc-500/10 text-zinc-500';

        $icon = $active ? '<span class="animate-spin">&#8635;</span>' : '&#10003;';

        return "<span class=\"{$classes}\">{$icon} " . e($label) . '</span>';
    }

    private function savedTopicCards(array $completedTools): string
    {
        $html = '';
        $topicsUrl = route('topics', ['current_team' => $this->teamModel]);
        foreach ($completedTools as $tool) {
            if ($tool->name !== 'save_topics') {
                continue;
            }
            $topics = $tool->arguments['topics'] ?? [];
            foreach ($topics as $topic) {
                $html .= '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">';
                $html .= '<div class="flex items-center justify-between mb-1">';
                $html .= '<span class="text-xs text-purple-400">&#10003; Saved</span>';
                $html .= '<a href="' . e($topicsUrl) . '" class="text-xs text-zinc-500 hover:text-zinc-300">Manage in Topics &rarr;</a>';
                $html .= '</div>';
                $html .= '<div class="text-sm font-semibold text-zinc-200">' . e($topic['title'] ?? '') . '</div>';
                $html .= '<div class="mt-1 text-xs text-zinc-400">' . e($topic['angle'] ?? '') . '</div>';
                $html .= '</div>';
            }
        }
        return $html;
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('create')" wire:navigate />
            <flux:heading size="lg">{{ $conversation->title }}</flux:heading>
            @if ($conversation->type)
                <flux:badge variant="pill" size="sm">{{ match($conversation->type) {
                    'brand' => __('Brand Knowledge'),
                    'topics' => __('Brainstorm'),
                    'write' => __('Write'),
                    default => $conversation->type,
                } }}</flux:badge>
            @endif
        </div>
        @if ($this->conversationStats['context'] > 0)
            <flux:tooltip content="{{ __('Current context size. Longer chats send more tokens per message -- start a new conversation for a new task to keep costs low.') }}" position="bottom">
                <div class="flex items-center gap-1.5 cursor-help">
                    <flux:text class="text-xs text-zinc-500">{{ number_format($this->conversationStats['context']) }} {{ __('context tokens') }}</flux:text>
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
            const scroll = () => el.scrollTop = el.scrollHeight;
            scroll();
            new MutationObserver(scroll).observe(el, { childList: true, subtree: true, characterData: true });
        "
    >
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="mb-6">
                    <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                    <div class="text-sm" wire:stream="streamed-response"><span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span></div>
                </div>
            @endif

            {{-- Message history (reversed for flex-col-reverse) --}}
            @foreach (array_reverse($messages) as $message)
                @if ($message['role'] === 'user')
                    <div class="mb-6 flex justify-end">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-2.5 dark:bg-zinc-700">
                            <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                @else
                    <div class="mb-6">
                        <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                        <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>

                        {{-- Tool pills + stats for every assistant message --}}
                        @if (!empty($message['metadata']['tools']) || ($message['input_tokens'] ?? 0) > 0)
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                @foreach ($message['metadata']['tools'] ?? [] as $tool)
                                    @if ($tool['name'] === 'fetch_url')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Read {{ $tool['args']['url'] ?? '' }}</span>
                                    @elseif ($tool['name'] === 'update_brand_intelligence')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Updated profile</span>
                                    @elseif ($tool['name'] === 'save_topics')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Saved {{ count($tool['args']['topics'] ?? []) }} topics</span>
                                    @endif
                                @endforeach
                                @if (!empty($message['metadata']['interrupted']))
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs text-amber-500">&#9888; {{ __('Interrupted') }}</span>
                                @endif
                                @if (!empty($message['metadata']['web_searches']))
                                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">{{ $message['metadata']['web_searches'] }} web searches</span>
                                @endif
                                @if (($message['input_tokens'] ?? 0) + ($message['output_tokens'] ?? 0) > 0)
                                    <span class="text-xs text-zinc-500">{{ number_format(($message['input_tokens'] ?? 0) + ($message['output_tokens'] ?? 0)) }} tokens</span>
                                @endif
                                @if (($message['cost'] ?? 0) > 0)
                                    <span class="text-xs text-zinc-500">&middot; ${{ number_format($message['cost'], 4) }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Saved topic cards from history --}}
                        @foreach ($message['metadata']['tools'] ?? [] as $tool)
                            @if ($tool['name'] === 'save_topics')
                                @foreach ($tool['args']['topics'] ?? [] as $topic)
                                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-purple-400">&#10003; {{ __('Saved') }}</span>
                                            <a href="{{ route('topics', ['current_team' => $teamModel]) }}" wire:navigate class="text-xs text-zinc-500 hover:text-zinc-300">{{ __('Manage in Topics') }} &rarr;</a>
                                        </div>
                                        <div class="text-sm font-semibold text-zinc-200">{{ $topic['title'] ?? '' }}</div>
                                        <div class="mt-1 text-xs text-zinc-400">{{ $topic['angle'] ?? '' }}</div>
                                    </div>
                                @endforeach
                            @endif
                        @endforeach
                    </div>
                @endif
            @endforeach

            {{-- Type selection (no type yet, no messages) --}}
            @if (!$conversation->type && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose a mode to get started.') }}</flux:subheading>

                    <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-3">
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

                        <button wire:click="selectType('write')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="pencil-square" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Write content') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Draft blog posts, social copy, emails, and more') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Topics sub-card selection --}}
            @if ($conversation->type === 'topics' && !$topicsMode && empty($messages))
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
        </div>
    </div>

    {{-- Input (only shown after type is selected) --}}
    @if ($conversation->type && !($conversation->type === 'topics' && !$topicsMode && empty($messages)))
        <div class="mx-auto w-full max-w-3xl px-6 pb-4 pt-2">
            @if ($isStreaming)
                <div class="flex justify-center">
                    <flux:button variant="danger" size="sm" icon="stop-circle" x-on:click="window.location.reload()">
                        {{ __('Stop generating') }}
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
