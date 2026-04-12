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

    public array $toolActivity = [];

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

    public function ask(): void
    {
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

        try {
            foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor) as $item) {
                if ($item instanceof ToolEvent) {
                    $this->toolActivity[] = [
                        'name' => $item->name,
                        'args' => $item->arguments,
                        'status' => $item->status,
                        'result' => $item->result,
                    ];
                    $statusHtml = $this->renderToolStatus();
                    $this->stream(to: 'tool-activity', content: $statusHtml, replace: true);
                } elseif ($item instanceof StreamResult) {
                    $streamResult = $item;
                } else {
                    $fullContent .= $item;
                    $this->stream(to: 'streamed-response', content: $fullContent, replace: true);
                }
            }
        } catch (\Throwable $e) {
            $fullContent = 'Sorry, something went wrong. Please try again.';
            $this->stream(to: 'streamed-response', content: $fullContent, replace: true);
        }

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

    public function render()
    {
        return $this->view()->title($this->conversation->title);
    }

    private function loadMessages(): void
    {
        $messages = $this->conversation->messages;

        $this->messages = $messages
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // Restore stats from last assistant message
        $lastAssistant = $messages->where('role', 'assistant')->last();
        if ($lastAssistant) {
            $this->lastTokens = $lastAssistant->input_tokens + $lastAssistant->output_tokens;
            $this->lastCost = (float) $lastAssistant->cost;
        }
    }

    private function renderToolStatus(): string
    {
        $items = '';
        foreach ($this->toolActivity as $activity) {
            if ($activity['name'] === 'fetch_url') {
                $url = $activity['args']['url'] ?? '';
                $icon = $activity['status'] === 'completed' ? '✓' : '⟳';
                $items .= "<div class=\"text-xs text-zinc-400\">{$icon} Reading {$url}</div>";
            } elseif ($activity['name'] === 'update_brand_intelligence') {
                if ($activity['status'] === 'completed') {
                    $result = json_decode($activity['result'] ?? '{}', true);
                    $sections = implode(', ', $result['sections'] ?? []);
                    $items .= "<div class=\"text-xs text-zinc-400\">✓ Updated brand profile: {$sections}</div>";
                } else {
                    $items .= "<div class=\"text-xs text-zinc-400\">⟳ Updating brand profile...</div>";
                }
            }
        }
        return $items;
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
    </div>

    {{-- Messages --}}
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="mb-6">
                    <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                    <div wire:stream="tool-activity"></div>
                    <div class="text-sm whitespace-pre-wrap" wire:stream="streamed-response">
                        <span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span>
                    </div>
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
        </div>
    @endif
</div>
