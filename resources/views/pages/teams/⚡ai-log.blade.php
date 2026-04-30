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
        // Filter on tokens, not cost: custom providers (direct DeepSeek,
        // Moonshot, etc.) don't return a cost in usage, so cost stays 0
        // even though tokens were consumed.
        $hasUsage = fn ($q) => $q->where('role', 'assistant')
            ->where(fn ($qq) => $qq->where('input_tokens', '>', 0)->orWhere('output_tokens', '>', 0));

        $rows = collect();

        $this->teamModel->conversations()
            ->with(['messages' => fn ($q) => $q->where('role', 'assistant')])
            ->get()
            ->each(function ($conversation) use (&$rows, $hasUsage) {
                foreach ($conversation->messages as $msg) {
                    // Orchestrator turn — only count it if the parent message
                    // itself spent tokens (it can be 0 when sub-agents did all
                    // the work and the orchestrator just tool-called).
                    if ($msg->input_tokens > 0 || $msg->output_tokens > 0) {
                        $rows->push([
                            'kind' => 'chat',
                            'conversation_title' => $conversation->title ?? 'Untitled',
                            'conversation_id' => $conversation->id,
                            'model' => $msg->model,
                            'input_tokens' => $msg->input_tokens,
                            'output_tokens' => $msg->output_tokens,
                            'reasoning_tokens' => (int) ($msg->metadata['reasoning_tokens'] ?? 0),
                            'cost' => (float) $msg->cost,
                            'created_at' => $msg->created_at->diffForHumans(),
                            'created_at_raw' => $msg->created_at,
                        ]);
                    }

                    // Sub-agent turns — buried in metadata.tools[i].card.
                    foreach (($msg->metadata['tools'] ?? []) as $tool) {
                        $card = $tool['card'] ?? null;
                        if (! $card) continue;
                        $in = (int) ($card['input_tokens'] ?? 0);
                        $out = (int) ($card['output_tokens'] ?? 0);
                        if ($in === 0 && $out === 0) continue;

                        $rows->push([
                            'kind' => 'sub-agent',
                            'conversation_title' => $conversation->title ?? 'Untitled',
                            'conversation_id' => $conversation->id,
                            'model' => $card['model'] ?? $tool['name'],
                            'input_tokens' => $in,
                            'output_tokens' => $out,
                            'reasoning_tokens' => (int) ($card['reasoning_tokens'] ?? 0),
                            'cost' => (float) ($card['cost'] ?? 0),
                            'created_at' => $msg->created_at->diffForHumans(),
                            'created_at_raw' => $msg->created_at,
                            'sub_agent_label' => $tool['name'],
                        ]);
                    }
                }
            });

        $this->entries = $rows
            ->sortByDesc('created_at_raw')
            ->take(100)
            ->values()
            ->toArray();
    }

    private function loadSummary(): void
    {
        // Orchestrator side: direct from messages table.
        $orchestrator = $this->teamModel->conversations()
            ->reorder()
            ->join('messages', 'conversations.id', '=', 'messages.conversation_id')
            ->where('messages.role', 'assistant')
            ->where(fn ($q) => $q->where('messages.input_tokens', '>', 0)->orWhere('messages.output_tokens', '>', 0))
            ->where('messages.created_at', '>=', now()->subDays(30))
            ->selectRaw('SUM(messages.cost) as total_cost, COUNT(*) as total_messages, SUM(messages.input_tokens + messages.output_tokens) as total_tokens')
            ->first();

        // Sub-agent side: walk metadata. SQL JSON aggregation is fragile across
        // SQLite/Postgres, and this page is read by humans not workloads —
        // looping in PHP is simpler and fine at this scale.
        $subTokens = 0;
        $subCost = 0.0;
        $subCount = 0;
        $this->teamModel->conversations()
            ->with(['messages' => fn ($q) => $q->where('role', 'assistant')->where('created_at', '>=', now()->subDays(30))])
            ->get()
            ->each(function ($conversation) use (&$subTokens, &$subCost, &$subCount) {
                foreach ($conversation->messages as $msg) {
                    foreach (($msg->metadata['tools'] ?? []) as $tool) {
                        $card = $tool['card'] ?? null;
                        if (! $card) continue;
                        $in = (int) ($card['input_tokens'] ?? 0);
                        $out = (int) ($card['output_tokens'] ?? 0);
                        if ($in === 0 && $out === 0) continue;
                        $subTokens += $in + $out;
                        $subCost += (float) ($card['cost'] ?? 0);
                        $subCount++;
                    }
                }
            });

        $this->summary = [
            'total_cost' => (float) ($orchestrator->total_cost ?? 0) + $subCost,
            'total_messages' => (int) ($orchestrator->total_messages ?? 0) + $subCount,
            'total_tokens' => (int) ($orchestrator->total_tokens ?? 0) + $subTokens,
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
        <div class="mt-8 overflow-x-auto">
            @if (count($entries) > 0)
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Conv #') }}</flux:table.column>
                        <flux:table.column>{{ __('Source') }}</flux:table.column>
                        <flux:table.column>{{ __('Model') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Out') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Reasoning') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cost') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($entries as $entry)
                            <flux:table.row>
                                <flux:table.cell variant="strong">{{ $entry['conversation_id'] }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($entry['kind'] === 'sub-agent')
                                        <flux:badge size="sm" color="purple">{{ Str::of($entry['sub_agent_label'] ?? '')->replace('_', ' ')->title() }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('Chat') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $entry['model'] ? Str::afterLast($entry['model'], '/') : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($entry['input_tokens']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($entry['output_tokens']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $entry['reasoning_tokens'] > 0 ? number_format($entry['reasoning_tokens']) : '—' }}</flux:table.cell>
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
