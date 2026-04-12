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

    public ?int $conversationId = null;

    public string $input = '';

    public bool $isStreaming = false;

    public array $messages = [];

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;

        // Load most recent conversation for this user+team
        $conversation = Conversation::where('team_id', $current_team->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        if ($conversation) {
            $this->conversationId = $conversation->id;
            $this->loadMessages();
        }
    }

    public function sendMessage(): void
    {
        $content = trim($this->input);

        if ($content === '' || $this->isStreaming) {
            return;
        }

        if (! $this->teamModel->openrouter_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('OpenRouter API key required. Add it in Team Settings.'));
            return;
        }

        $this->input = '';
        $this->isStreaming = true;

        // Create conversation if needed
        if (! $this->conversationId) {
            $conversation = Conversation::create([
                'team_id' => $this->teamModel->id,
                'user_id' => Auth::id(),
                'title' => mb_substr($content, 0, 80),
            ]);
            $this->conversationId = $conversation->id;
        }

        // Save user message
        Message::create([
            'conversation_id' => $this->conversationId,
            'role' => 'user',
            'content' => $content,
        ]);

        $this->messages[] = ['role' => 'user', 'content' => $content];
        $this->messages[] = ['role' => 'assistant', 'content' => ''];

        // Build message history for API
        $apiMessages = collect($this->messages)
            ->filter(fn ($m) => $m['content'] !== '')
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
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
                    $this->stream('assistant-response', $chunk, true);
                }
            }
        } catch (\Throwable $e) {
            $fullContent = 'Sorry, something went wrong. Please try again.';
            $this->stream('assistant-response', $fullContent, true);
        }

        // Save assistant message
        Message::create([
            'conversation_id' => $this->conversationId,
            'role' => 'assistant',
            'content' => $fullContent,
            'model' => $this->teamModel->fast_model,
            'input_tokens' => $streamResult?->inputTokens ?? 0,
            'output_tokens' => $streamResult?->outputTokens ?? 0,
            'cost' => $streamResult?->cost ?? 0,
        ]);

        // Update the last message in our local array
        $this->messages[count($this->messages) - 1]['content'] = $fullContent;
        $this->isStreaming = false;
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->messages = [];
        $this->input = '';
    }

    public function render()
    {
        return $this->view()->title(__('Create'));
    }

    private function loadMessages(): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $this->messages = $conversation->messages
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
        <flux:heading size="xl">{{ __('Create') }}</flux:heading>
        <flux:button variant="subtle" size="sm" icon="plus" wire:click="newConversation">
            {{ __('New conversation') }}
        </flux:button>
    </div>

    {{-- Messages --}}
    <div
        class="flex-1 overflow-y-auto px-6 py-4 space-y-4"
        id="messages-container"
        x-data
        x-effect="$nextTick(() => { const el = document.getElementById('messages-container'); el.scrollTop = el.scrollHeight; })"
    >
        @if (empty($messages))
            <div class="flex h-full items-center justify-center">
                <div class="text-center">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                    <flux:heading size="lg" class="mt-4">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mt-1">{{ __('Start a conversation with your AI assistant.') }}</flux:subheading>
                </div>
            </div>
        @else
            @foreach ($messages as $index => $message)
                @if ($message['role'] === 'user')
                    <div class="flex justify-end">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-3 dark:bg-zinc-700">
                            <flux:text class="whitespace-pre-wrap">{{ $message['content'] }}</flux:text>
                        </div>
                    </div>
                @else
                    <div class="flex justify-start">
                        <div class="max-w-2xl px-4 py-3">
                            @if ($isStreaming && $index === count($messages) - 1)
                                <flux:text class="whitespace-pre-wrap" wire:stream="assistant-response">{{ $message['content'] }}</flux:text>
                            @else
                                <flux:text class="whitespace-pre-wrap">{{ $message['content'] }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>

    {{-- Input --}}
    <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
        <form wire:submit="sendMessage" class="flex items-end gap-3">
            <div class="flex-1">
                <flux:textarea
                    wire:model="input"
                    placeholder="{{ __('Type your message...') }}"
                    rows="1"
                    :disabled="$isStreaming"
                    x-data
                    x-on:keydown.enter.prevent="if (!$event.shiftKey) { $wire.sendMessage(); }"
                    x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                />
            </div>
            <flux:button
                type="submit"
                variant="primary"
                icon="paper-airplane"
                :disabled="$isStreaming"
            />
        </form>
    </div>
</div>
