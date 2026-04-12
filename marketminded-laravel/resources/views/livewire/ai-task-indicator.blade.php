<?php

use App\Models\AiTask;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Flux\Flux;

new class extends Component
{
    public int $runningCount = 0;

    public array $recentTasks = [];

    public bool $hasActive = false;

    public function mount(): void
    {
        $this->loadTasks();
    }

    public function render()
    {
        $this->loadTasks();

        return $this->view();
    }

    public function cancelTask(int $taskId): void
    {
        $team = Auth::user()?->currentTeam;
        $task = $team?->aiTasks()->findOrFail($taskId);

        if ($task->isActive()) {
            $task->markCancelled();
            Flux::toast(variant: 'success', text: __('Task cancelled.'));
        }

        $this->loadTasks();
    }

    private function loadTasks(): void
    {
        $team = Auth::user()?->currentTeam;

        if (! $team) {
            return;
        }

        $this->runningCount = $team->aiTasks()->running()->count();
        $this->hasActive = $this->runningCount > 0;

        $this->recentTasks = $team->aiTasks()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'label' => $task->label,
                'status' => $task->status,
                'current_step' => $task->current_step,
                'completed_steps' => $task->completed_steps,
                'total_steps' => $task->total_steps,
                'total_tokens' => $task->total_tokens,
                'total_cost' => $task->total_cost,
                'error' => $task->error,
                'created_at' => $task->created_at->diffForHumans(),
                'duration' => $task->completed_at && $task->started_at
                    ? $task->started_at->diffInSeconds($task->completed_at) . 's'
                    : null,
            ])
            ->toArray();
    }
}; ?>


<div wire:poll.{{ $hasActive ? '5s' : '30s' }}>
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" size="sm" class="relative">
            <flux:icon name="sparkles" class="{{ $hasActive ? 'text-indigo-400 animate-pulse' : 'text-zinc-500' }}" variant="mini" />
            @if ($runningCount > 0)
                <flux:badge color="indigo" size="sm" class="absolute -right-1 -top-1">{{ $runningCount }}</flux:badge>
            @endif
        </flux:button>

        <flux:menu class="w-80">
            <div class="px-3 py-2">
                <flux:heading size="sm">{{ __('AI Tasks') }}</flux:heading>
            </div>

            <flux:menu.separator />

            @forelse ($recentTasks as $task)
                <div class="px-3 py-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                <flux:icon.loading class="size-4 text-indigo-400" />
                            @elseif ($task['status'] === 'completed')
                                <flux:icon name="check-circle" class="text-green-500" variant="micro" />
                            @elseif ($task['status'] === 'failed')
                                <flux:icon name="x-circle" class="text-red-400" variant="micro" />
                            @else
                                <flux:icon name="minus-circle" class="text-zinc-500" variant="micro" />
                            @endif
                            <flux:text class="text-sm font-medium">{{ $task['label'] }}</flux:text>
                        </div>

                        @if ($task['status'] === 'running' || $task['status'] === 'pending')
                            <flux:button variant="ghost" size="xs" wire:click="cancelTask({{ $task['id'] }})">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif
                    </div>

                    @if ($task['status'] === 'running')
                        <flux:text class="mt-1 text-xs text-zinc-400">
                            {{ __('Step :completed/:total', ['completed' => $task['completed_steps'], 'total' => $task['total_steps']]) }}
                        </flux:text>
                    @elseif ($task['status'] === 'completed')
                        <flux:text class="mt-1 text-xs text-zinc-400">
                            {{ $task['duration'] }} · {{ number_format($task['total_tokens']) }} tokens · ${{ number_format((float) $task['total_cost'], 4) }}
                        </flux:text>
                    @elseif ($task['status'] === 'failed')
                        <flux:text class="mt-1 text-xs text-red-400">{{ Str::limit($task['error'], 80) }}</flux:text>
                    @else
                        <flux:text class="mt-1 text-xs text-zinc-500">{{ $task['created_at'] }}</flux:text>
                    @endif
                </div>

                @if (! $loop->last)
                    <flux:menu.separator />
                @endif
            @empty
                <div class="px-3 py-4 text-center">
                    <flux:text class="text-sm text-zinc-500">{{ __('No AI tasks yet') }}</flux:text>
                </div>
            @endforelse

            @if (count($recentTasks) > 0)
                <flux:menu.separator />
                <flux:menu.item :href="route('ai.operations')" wire:navigate>
                    {{ __('View all operations') }}
                </flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>
</div>
