<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\OpenRouterClient;
use App\Services\StreamResult;
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

    public function mount(Team $current_team, Conversation $conversation): void
    {
        $this->teamModel = $current_team;
        $this->conversation = $conversation;
        $this->loadMessages();
    }

    public function submitPrompt(): void
    {
        $content = trim($this->prompt);

        if ($content === '' || $this->isStreaming) {
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

        // Update title from first message if still default
        if ($this->conversation->title === __('New conversation')) {
            $this->conversation->update(['title' => mb_substr($content, 0, 80)]);
        }

        $this->messages[] = ['role' => 'user', 'content' => $content];
        $this->prompt = '';
        $this->isStreaming = true;

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        $apiMessages = collect($this->messages)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->toArray();

        $client = new OpenRouterClient(
            apiKey: $this->teamModel->openrouter_api_key,
            model: $this->teamModel->fast_model,
            urlFetcher: new UrlFetcher,
        );

        $fullContent = '';
        $streamResult = null;

        try {
            foreach ($client->streamChat('You are a helpful AI assistant.', $apiMessages) as $chunk) {
                if ($chunk instanceof StreamResult) {
                    $streamResult = $chunk;
                } else {
                    $fullContent .= $chunk;
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
        $this->isStreaming = false;
    }

    public function quickAction(string $action): void
    {
        $this->prompt = match ($action) {
            'brand' => "Help me build brand knowledge for my company. Analyze our positioning, audience, and voice so we can improve our copywriting performance. Ask me questions about my brand to get started.",
            'topics' => "Let's brainstorm content topics. I want fresh, relevant ideas that would resonate with my target audience. Ask me about my niche and goals so we can generate great topic ideas.",
            'write' => "I'd like to write a piece of content. Help me draft something compelling — a blog post, social media copy, or email. Ask me what I'd like to create.",
            default => '',
        };

        if ($this->prompt !== '') {
            $this->submitPrompt();
        }
    }

    public function render()
    {
        return $this->view()->title($this->conversation->title);
    }

    private function loadMessages(): void
    {
        $this->messages = $this->conversation->messages
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('create')" wire:navigate />
            <flux:heading size="lg">{{ $conversation->title }}</flux:heading>
        </div>
    </div>

    {{-- Messages --}}
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="mb-6">
                    <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                    <div class="text-sm whitespace-pre-wrap" wire:stream="streamed-response">
                        <span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span>
                    </div>
                </div>
            @endif

            {{-- Quick actions (empty conversation) --}}
            @if (empty($messages) && !$isStreaming)
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Pick a starting point or type your own message.') }}</flux:subheading>

                    <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-3">
                        <button wire:click="quickAction('brand')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="building-storefront" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Build brand knowledge') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Improve copywriting performance with deep brand understanding') }}</flux:text>
                        </button>

                        <button wire:click="quickAction('topics')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="light-bulb" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Brainstorm topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Generate fresh content ideas for your audience') }}</flux:text>
                        </button>

                        <button wire:click="quickAction('write')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="pencil-square" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Write content') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Draft blog posts, social copy, emails, and more') }}</flux:text>
                        </button>
                    </div>
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
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Input --}}
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
</div>
