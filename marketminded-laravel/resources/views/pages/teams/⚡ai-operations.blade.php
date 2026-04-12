<?php

use App\Models\AiTask;
use App\Models\Team;
use App\Support\TeamPermissions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public array $tasks = [];

    public array $summary = [];

    public ?int $expandedTaskId = null;

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
        $this->loadTasks();
        $this->loadSummary();
    }

    public function toggleTask(int $taskId): void
    {
        $this->expandedTaskId = $this->expandedTaskId === $taskId ? null : $taskId;
    }

    public function cancelTask(int $taskId): void
    {
        Gate::authorize('update', $this->teamModel);

        $task = $this->teamModel->aiTasks()->findOrFail($taskId);

        if ($task->isActive()) {
            $task->markCancelled();
            Flux::toast(variant: 'success', text: __('Task cancelled.'));
        }

        $this->loadTasks();
    }

    public function retryTask(int $taskId): void
    {
        Gate::authorize('update', $this->teamModel);

        $oldTask = $this->teamModel->aiTasks()->findOrFail($taskId);

        if ($oldTask->type === 'brand_intelligence') {
            $aiTask = AiTask::create([
                'team_id' => $this->teamModel->id,
                'type' => 'brand_intelligence',
                'label' => 'Generate Brand Intelligence',
                'status' => 'pending',
                'total_steps' => 4,
            ]);

            $aiTask->steps()->createMany([
                ['name' => 'fetching', 'label' => 'Fetching URLs'],
                ['name' => 'positioning', 'label' => 'Analyzing positioning'],
                ['name' => 'personas', 'label' => 'Building personas'],
                ['name' => 'voice_profile', 'label' => 'Defining voice profile'],
            ]);

            \App\Jobs\GenerateBrandIntelligenceJob::dispatch($this->teamModel, $aiTask);

            Flux::toast(variant: 'success', text: __('Retrying task.'));
        }

        $this->loadTasks();
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        $this->loadTasks();

        return $this->view()->title(__('AI Operations'));
    }

    private function loadTasks(): void
    {
        $this->tasks = $this->teamModel->aiTasks()
            ->with('steps')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'type' => $task->type,
                'label' => $task->label,
                'status' => $task->status,
                'current_step' => $task->current_step,
                'completed_steps' => $task->completed_steps,
                'total_steps' => $task->total_steps,
                'total_tokens' => $task->total_tokens,
                'total_cost' => (float) $task->total_cost,
                'error' => $task->error,
                'created_at' => $task->created_at->diffForHumans(),
                'duration' => $task->completed_at && $task->started_at
                    ? $task->completed_at->diffInSeconds($task->started_at) . 's'
                    : null,
                'steps' => $task->steps->map(fn ($s) => [
                    'name' => $s->name,
                    'label' => $s->label,
                    'status' => $s->status,
                    'model' => $s->model,
                    'input_tokens' => $s->input_tokens,
                    'output_tokens' => $s->output_tokens,
                    'cost' => (float) $s->cost,
                    'iterations' => $s->iterations,
                    'duration' => $s->completed_at && $s->started_at
                        ? $s->completed_at->diffInSeconds($s->started_at) . 's'
                        : null,
                ])->toArray(),
            ])
            ->toArray();
    }

    private function loadSummary(): void
    {
        $thirtyDays = $this->teamModel->aiTasks()->recent(30);

        $this->summary = [
            'total_cost' => (float) ($thirtyDays->sum('total_cost') ?? 0),
            'tasks_run' => $thirtyDays->count(),
            'total_tokens' => (int) ($thirtyDays->sum('total_tokens') ?? 0),
        ];
    }
}; ?>

<section class="w-full">
    <flux:main container class="max-w-4xl">
        <flux:heading size="xl">{{ __('AI Operations') }}</flux:heading>
        <flux:subheading>{{ __('Monitor AI tasks, track costs, and review agent activity.') }}</flux:subheading>

        {{-- Summary cards --}}
        <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Cost (30d)') }}</flux:text>
                <flux:heading size="xl" class="mt-1">${{ number_format($summary['total_cost'], 4) }}</flux:heading>
            </flux:card>
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Tasks Run') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $summary['tasks_run'] }}</flux:heading>
            </flux:card>
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Tokens') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ number_format($summary['total_tokens']) }}</flux:heading>
            </flux:card>
        </div>

        {{-- Task list --}}
        <div class="mt-8 space-y-3">
            @forelse ($tasks as $task)
                <flux:card class="cursor-pointer {{ $task['status'] === 'running' || $task['status'] === 'pending' ? 'border-indigo-500/30' : '' }}" wire:click="toggleTask({{ $task['id'] }})">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                <flux:icon name="sparkles" class="animate-pulse text-indigo-400" />
                            @elseif ($task['status'] === 'completed')
                                <flux:icon name="check-circle" class="text-green-500" variant="solid" />
                            @elseif ($task['status'] === 'failed')
                                <flux:icon name="x-circle" class="text-red-400" variant="solid" />
                            @elseif ($task['status'] === 'cancelled')
                                <flux:icon name="minus-circle" class="text-zinc-500" variant="solid" />
                            @endif

                            <div>
                                <flux:heading>{{ $task['label'] }}</flux:heading>
                                @if ($task['status'] === 'running')
                                    <flux:text class="text-sm text-zinc-400">
                                        {{ __('Step :completed/:total', ['completed' => $task['completed_steps'], 'total' => $task['total_steps']]) }}
                                        @if ($task['current_step'])
                                            · {{ $task['current_step'] }}
                                        @endif
                                    </flux:text>
                                @elseif ($task['status'] === 'completed')
                                    <flux:text class="text-sm text-zinc-400">
                                        {{ $task['duration'] }} · {{ number_format($task['total_tokens']) }} tokens · ${{ number_format($task['total_cost'], 4) }}
                                    </flux:text>
                                @elseif ($task['status'] === 'failed')
                                    <flux:text class="text-sm text-red-400">{{ Str::limit($task['error'], 100) }}</flux:text>
                                @elseif ($task['status'] === 'cancelled')
                                    <flux:text class="text-sm text-zinc-500">{{ __('Cancelled') }} · {{ $task['completed_steps'] }}/{{ $task['total_steps'] }} {{ __('steps completed') }}</flux:text>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <flux:text class="text-sm text-zinc-500">{{ $task['created_at'] }}</flux:text>

                            @if ($this->permissions->canUpdateTeam)
                                @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                    <flux:button variant="ghost" size="xs" wire:click.stop="cancelTask({{ $task['id'] }})">{{ __('Cancel') }}</flux:button>
                                @elseif ($task['status'] === 'failed')
                                    <flux:button variant="ghost" size="xs" icon="sparkles" wire:click.stop="retryTask({{ $task['id'] }})">{{ __('Retry') }}</flux:button>
                                @endif
                            @endif
                        </div>
                    </div>

                    {{-- Expandable step detail --}}
                    @if ($expandedTaskId === $task['id'] && count($task['steps']) > 0)
                        <div class="mt-4 rounded-lg bg-zinc-800 p-3" wire:click.stop>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-wide text-zinc-500">
                                        <th class="px-2 py-1 text-left">{{ __('Agent') }}</th>
                                        <th class="px-2 py-1 text-left">{{ __('Model') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Tokens') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Cost') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Loops') }}</th>
                                        <th class="px-2 py-1 text-right">{{ __('Time') }}</th>
                                        <th class="px-2 py-1 text-center">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($task['steps'] as $step)
                                        <tr class="text-zinc-300">
                                            <td class="px-2 py-1">{{ $step['label'] }}</td>
                                            <td class="px-2 py-1 text-zinc-500">{{ $step['model'] ? Str::afterLast($step['model'], '/') : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['input_tokens'] + $step['output_tokens'] > 0 ? number_format($step['input_tokens'] + $step['output_tokens']) : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['cost'] > 0 ? '$' . number_format($step['cost'], 4) : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['iterations'] > 0 ? $step['iterations'] : '—' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $step['duration'] ?? '—' }}</td>
                                            <td class="px-2 py-1 text-center">
                                                @if ($step['status'] === 'completed')
                                                    <span class="text-green-400">✓</span>
                                                @elseif ($step['status'] === 'running')
                                                    <span class="animate-spin text-indigo-400">●</span>
                                                @elseif ($step['status'] === 'failed')
                                                    <span class="text-red-400">✕</span>
                                                @elseif ($step['status'] === 'skipped')
                                                    <span class="text-zinc-500">—</span>
                                                @else
                                                    <span class="text-zinc-600">○</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </flux:card>
            @empty
                <flux:card class="py-8 text-center">
                    <flux:icon name="sparkles" class="mx-auto text-zinc-500" />
                    <flux:text class="mt-2">{{ __('No AI tasks have been run yet.') }}</flux:text>
                </flux:card>
            @endforelse
        </div>
    </flux:main>
</section>
