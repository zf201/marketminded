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

    public string $prompt = '';

    public string $question = '';

    public string $answer = '';

    public array $messages = [];

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;

        $conversation = Conversation::where('team_id', $current_team->id)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        if ($conversation) {
            $this->conversationId = $conversation->id;
            $this->loadMessages();
        }
    }

    public function submitPrompt(): void
    {
        $content = trim($this->prompt);

        if ($content === '') {
            return;
        }

        if (! $this->teamModel->openrouter_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('OpenRouter API key required. Add it in Team Settings.'));
            return;
        }

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
        $this->question = $content;
        $this->prompt = '';
        $this->answer = '';

        // Trigger streaming in a separate request so the UI updates first
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
                    $this->stream(to: 'answer', content: $fullContent, replace: true);
                }
            }
        } catch (\Throwable $e) {
            $fullContent = 'Sorry, something went wrong. Please try again.';
            $this->stream(to: 'answer', content: $fullContent, replace: true);
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

        $this->messages[] = ['role' => 'assistant', 'content' => $fullContent];
        $this->answer = $fullContent;
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->messages = [];
        $this->prompt = '';
        $this->question = '';
        $this->answer = '';
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
    <div class="flex items-center justify-between px-6 py-3">
        <flux:heading size="xl">{{ __('Create') }}</flux:heading>
        <flux:button variant="subtle" size="sm" icon="plus" wire:click="newConversation">
            {{ __('New conversation') }}
        </flux:button>
    </div>

    {{-- Messages area — flex-col-reverse for auto-scroll --}}
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto flex max-w-3xl flex-col-reverse px-6 py-4">
            {{-- Streaming response (shown during ask()) --}}
            <div wire:loading wire:target="ask" class="mb-4">
                <div class="flex gap-3">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-indigo-500 text-xs font-medium text-white">AI</div>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <p class="prose prose-sm dark:prose-invert whitespace-pre-wrap" wire:stream="answer">
                            <span class="inline-flex items-center gap-1 text-zinc-400"><flux:icon.loading class="size-4" /> {{ __('Thinking...') }}</span>
                        </p>
                    </div>
                </div>
            </div>

            {{-- Message history (reversed for flex-col-reverse) --}}
            @foreach (array_reverse($messages) as $message)
                @if ($message['role'] === 'user')
                    <div class="mb-4 flex gap-3 justify-end">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-2.5 dark:bg-zinc-700">
                            <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                @else
                    <div class="mb-4 flex gap-3">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-indigo-500 text-xs font-medium text-white">AI</div>
                        <div class="min-w-0 flex-1 pt-0.5">
                            <p class="prose prose-sm dark:prose-invert whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Empty state --}}
            @if (empty($messages))
                <div class="flex h-full items-center justify-center py-20">
                    <div class="text-center">
                        <flux:icon name="chat-bubble-left-right" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                        <flux:heading size="lg" class="mt-4">{{ __('What would you like to create?') }}</flux:heading>
                        <flux:subheading class="mt-1">{{ __('Start a conversation with your AI assistant.') }}</flux:subheading>
                    </div>
                </div>
            @endif
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
                placeholder="{{ __('What would you like to create?') }}"
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
