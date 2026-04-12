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
