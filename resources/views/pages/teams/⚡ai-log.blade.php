<?php

use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public array $entries = [];

    public array $summary = [];

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
        $this->loadSummary();
    }

    public function render()
    {
        $this->loadEntries();

        return $this->view()->title(__('AI Log'));
    }

    private function loadEntries(): void
    {
        $this->entries = $this->teamModel->conversations()
            ->with(['messages' => fn ($q) => $q->where('role', 'assistant')->where('cost', '>', 0)])
            ->whereHas('messages', fn ($q) => $q->where('role', 'assistant')->where('cost', '>', 0))
            ->get()
            ->flatMap(fn ($conversation) => $conversation->messages->map(fn ($msg) => [
                'conversation_title' => $conversation->title ?? 'Untitled',
                'conversation_id' => $conversation->id,
                'model' => $msg->model,
                'input_tokens' => $msg->input_tokens,
                'output_tokens' => $msg->output_tokens,
                'cost' => (float) $msg->cost,
                'created_at' => $msg->created_at->diffForHumans(),
                'created_at_raw' => $msg->created_at,
            ]))
            ->sortByDesc('created_at_raw')
            ->take(100)
            ->values()
            ->toArray();
    }

    private function loadSummary(): void
    {
        $thirtyDays = $this->teamModel->conversations()
            ->reorder()
            ->join('messages', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.role', 'assistant')
            ->where('messages.cost', '>', 0)
            ->where('messages.created_at', '>=', now()->subDays(30))
            ->selectRaw('SUM(messages.cost) as total_cost, COUNT(*) as total_messages, SUM(messages.input_tokens + messages.output_tokens) as total_tokens')
            ->first();

        $this->summary = [
            'total_cost' => (float) ($thirtyDays->total_cost ?? 0),
            'total_messages' => (int) ($thirtyDays->total_messages ?? 0),
            'total_tokens' => (int) ($thirtyDays->total_tokens ?? 0),
        ];
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div>
            <flux:heading size="xl">{{ __('AI Log') }}</flux:heading>
            <flux:subheading>{{ __('AI usage and spend across all conversations.') }}</flux:subheading>
        </div>
    </div>

    <div class="mx-auto max-w-5xl px-6 py-4">
        {{-- Summary cards --}}
        <div class="flex gap-4">
            <flux:card class="flex-1 text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Cost (30d)') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">${{ number_format($summary['total_cost'], 4) }}</div>
            </flux:card>
            <flux:card class="flex-1 text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('AI Messages') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ $summary['total_messages'] }}</div>
            </flux:card>
            <flux:card class="flex-1 text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Tokens') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($summary['total_tokens']) }}</div>
            </flux:card>
        </div>

        {{-- Log table --}}
        <div class="mt-8">
            @if (count($entries) > 0)
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Conversation') }}</flux:table.column>
                        <flux:table.column>{{ __('Model') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In Tokens') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Out Tokens') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cost') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($entries as $entry)
                            <flux:table.row>
                                <flux:table.cell variant="strong">{{ Str::limit($entry['conversation_title'], 40) }}</flux:table.cell>
                                <flux:table.cell>{{ $entry['model'] ? Str::afterLast($entry['model'], '/') : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($entry['input_tokens']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($entry['output_tokens']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $entry['cost'] > 0 ? '$' . number_format($entry['cost'], 4) : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $entry['created_at'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @else
                <flux:card class="py-8 text-center">
                    <flux:icon name="chart-bar" class="mx-auto text-zinc-500" />
                    <flux:text class="mt-2">{{ __('No AI usage recorded yet.') }}</flux:text>
                </flux:card>
            @endif
        </div>
    </div>
</div>
