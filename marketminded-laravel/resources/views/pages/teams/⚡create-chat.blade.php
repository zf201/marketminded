<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\ChatPromptBuilder;
use App\Services\OpenRouterClient;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public Conversation $conversation;

    public string $prompt = '';

    public bool $isStreaming = false;

    public array $messages = [];

    public int $webSearchRequests = 0;

    public int $lastTokens = 0;

    public float $lastCost = 0;

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

        $this->messages[] = ['role' => 'user', 'content' => $content];
        $this->prompt = '';
        $this->isStreaming = true;
        $this->toolActivity = [];
        $this->webSearchRequests = 0;
        $this->lastTokens = 0;
        $this->lastCost = 0;

        $this->js('$wire.ask()');
    }

    public function stopStreaming(): void
    {
        \Cache::put("chat-stop-{$this->conversation->id}", true, 300);
    }

    public function ask(): void
    {
        set_time_limit(300);
        \Cache::forget("chat-stop-{$this->conversation->id}");

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
        $team = $this->teamModel;

        $toolExecutor = function (string $name, array $args) use ($brandHandler, $team): string {
            if ($name === 'update_brand_intelligence') {
                return $brandHandler->execute($team, $args);
            }
            if ($name === 'fetch_url') {
                return (new UrlFetcher)->fetch($args['url'] ?? '');
            }
            return "Unknown tool: {$name}";
        };

        $fullContent = '';
        $streamResult = null;
        $completedTools = [];

        try {
            foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor) as $item) {
                // Check stop flag
                if (\Cache::get("chat-stop-{$this->conversation->id}")) {
                    $fullContent .= "\n\n[Stopped by user]";
                    break;
                }

                if ($item instanceof ToolEvent) {
                    if ($item->status === 'started') {
                        // Show current tool as in-progress
                        $this->streamUI($completedTools, $item, $fullContent);
                    } else {
                        // Tool completed — add to completed list, clear active
                        $completedTools[] = $item;
                        $this->streamUI($completedTools, null, $fullContent);
                    }
                } elseif ($item instanceof StreamResult) {
                    $streamResult = $item;
                } else {
                    $fullContent .= $item;
                    $this->streamUI($completedTools, null, $fullContent);
                }
            }
        } catch (\Throwable $e) {
            \Log::error('Chat streaming error', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            $fullContent .= $fullContent ? "\n\n[Error: streaming interrupted]" : 'Sorry, something went wrong. Please try again.';
            $this->streamUI($completedTools, null, $fullContent);
        }

        \Cache::forget("chat-stop-{$this->conversation->id}");

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => $fullContent,
            'model' => $this->teamModel->fast_model,
            'input_tokens' => $streamResult?->inputTokens ?? 0,
            'output_tokens' => $streamResult?->outputTokens ?? 0,
            'cost' => $streamResult?->cost ?? 0,
        ]);

        $this->messages[] = ['role' => 'assistant', 'content' => $fullContent];
        $this->webSearchRequests = $streamResult?->webSearchRequests ?? 0;
        $this->lastTokens = ($streamResult?->inputTokens ?? 0) + ($streamResult?->outputTokens ?? 0);
        $this->lastCost = $streamResult?->cost ?? 0;
        $this->isStreaming = false;
    }

    public function getConversationStatsProperty(): array
    {
        $assistantMessages = $this->conversation->messages()->where('role', 'assistant');
        $lastAssistant = $this->conversation->messages()->where('role', 'assistant')->latest('id')->first();

        return [
            'context' => (int) ($lastAssistant?->input_tokens ?? 0),
            'cost' => (float) $assistantMessages->sum('cost'),
        ];
    }

    public function render()
    {
        return $this->view()->title($this->conversation->title);
    }

    private function loadMessages(): void
    {
        $messages = $this->conversation->messages;

        $this->messages = $messages
            ->map(fn (Message $m) => [
                'role' => $m->role,
                'content' => $this->cleanContent($m->content),
            ])
            ->toArray();

        // Restore stats from last assistant message
        $lastAssistant = $messages->where('role', 'assistant')->last();
        if ($lastAssistant) {
            $this->lastTokens = $lastAssistant->input_tokens + $lastAssistant->output_tokens;
            $this->lastCost = (float) $lastAssistant->cost;
        }
    }

    /**
     * Strip any content block artifacts from stored messages.
     */
    private function cleanContent(string $content): string
    {
        // Remove nested [{'type': 'text', 'text': '...'}] artifacts
        while (preg_match("/\[\{'type': 'text', 'text': ['\"](.+?)['\"]\}\]/s", $content, $matches)) {
            $content = str_replace($matches[0], $matches[1], $content);
        }

        // Also handle JSON format [{"type":"text","text":"..."}]
        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded[0]['type']) && $decoded[0]['type'] === 'text') {
            $content = collect($decoded)->pluck('text')->implode('');
        }

        return $content;
    }

    /**
     * Stream the full current UI state — completed tools + active tool + text content.
     * Always replaces the entire streamed-response element so nothing flashes or disappears.
     */
    private function streamUI(array $completedTools, ?ToolEvent $activeTool, string $content): void
    {
        $html = '';

        // Completed tools
        foreach ($completedTools as $tool) {
            if ($tool->name === 'fetch_url') {
                $url = e($tool->arguments['url'] ?? '');
                $html .= "<div class=\"text-xs text-zinc-500 mb-1\">✓ Read {$url}</div>";
            } elseif ($tool->name === 'update_brand_intelligence') {
                $result = json_decode($tool->result ?? '{}', true);
                $sections = e(implode(', ', $result['sections'] ?? []));
                $html .= "<div class=\"text-xs text-zinc-500 mb-1\">✓ Updated brand profile: {$sections}</div>";
            }
        }

        // Active tool (in progress)
        if ($activeTool) {
            if ($activeTool->name === 'fetch_url') {
                $url = e($activeTool->arguments['url'] ?? '');
                $html .= "<div class=\"text-xs text-indigo-400 mb-1\">⟳ Reading {$url}...</div>";
            } elseif ($activeTool->name === 'update_brand_intelligence') {
                $html .= "<div class=\"text-xs text-indigo-400 mb-1\">⟳ Updating brand profile...</div>";
            }
        }

        // Text content
        if ($content !== '') {
            $html .= '<div class="whitespace-pre-wrap mt-2">' . e($content) . '</div>';
        } elseif (! $activeTool && empty($completedTools)) {
            $html .= '<span class="inline-flex items-center gap-1.5 text-zinc-500"><span class="size-3.5 animate-spin">⟳</span> Thinking...</span>';
        }

        $this->stream(to: 'streamed-response', content: $html, replace: true);
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
            <flux:tooltip content="{{ __('Current context size. Longer chats send more tokens per message — start a new conversation for a new task to keep costs low.') }}" position="bottom">
                <div class="flex items-center gap-1.5 cursor-help">
                    <flux:text class="text-xs text-zinc-500">{{ number_format($this->conversationStats['context']) }} {{ __('context tokens') }}</flux:text>
                    <flux:icon name="information-circle" variant="mini" class="size-3.5 text-zinc-400" />
                </div>
            </flux:tooltip>
        @endif
    </div>

    {{-- Messages --}}
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="mb-6">
                    <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                    <div class="text-sm" wire:stream="streamed-response"><span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span></div>
                </div>
            @endif

            {{-- Message history (reversed for flex-col-reverse) --}}
            @foreach (array_reverse($messages) as $index => $message)
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
                        @if ($index === 0 && !$isStreaming && ($webSearchRequests > 0 || $lastTokens > 0))
                            <div class="mt-2 flex items-center gap-2">
                                @if ($webSearchRequests > 0)
                                    <flux:text class="text-xs text-zinc-500">{{ $webSearchRequests }} {{ __('web searches') }}</flux:text>
                                    <flux:text class="text-xs text-zinc-500">&middot;</flux:text>
                                @endif
                                @if ($lastTokens > 0)
                                    <flux:text class="text-xs text-zinc-500">{{ number_format($lastTokens) }} {{ __('tokens') }}</flux:text>
                                @endif
                                @if ($lastCost > 0)
                                    <flux:text class="text-xs text-zinc-500">&middot; ${{ number_format($lastCost, 4) }}</flux:text>
                                @endif
                            </div>
                        @endif
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
        </div>
    </div>

    {{-- Input (only shown after type is selected) --}}
    @if ($conversation->type)
        <div class="mx-auto w-full max-w-3xl px-6 pb-4 pt-2">
            @if ($isStreaming)
                <div class="flex justify-center">
                    <flux:button variant="subtle" size="sm" icon="stop" wire:click="stopStreaming">
                        {{ __('Stop') }}
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
