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
                <div class="mt-1 text-2xl font-semibold">${{ number_format($summary['total_cost'], 4) }}</div>
            </flux:card>
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Tasks Run') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ $summary['tasks_run'] }}</div>
            </flux:card>
            <flux:card class="text-center">
                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Total Tokens') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($summary['total_tokens']) }}</div>
            </flux:card>
        </div>

        {{-- Task list --}}
        <div class="mt-8">
            @if (count($tasks) > 0)
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Task') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Tokens') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cost') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Time') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('When') }}</flux:table.column>
                        <flux:table.column align="end"></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($tasks as $task)
                            <flux:table.row class="cursor-pointer" wire:click="toggleTask({{ $task['id'] }})">
                                <flux:table.cell variant="strong">
                                    <div class="flex items-center gap-2">
                                        @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                            <flux:icon.loading class="size-4 text-indigo-400" />
                                        @elseif ($task['status'] === 'completed')
                                            <flux:icon name="check-circle" class="text-green-500" variant="mini" />
                                        @elseif ($task['status'] === 'failed')
                                            <flux:icon name="x-circle" class="text-red-400" variant="mini" />
                                        @else
                                            <flux:icon name="minus-circle" class="text-zinc-500" variant="mini" />
                                        @endif
                                        {{ $task['label'] }}
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                        <flux:badge color="indigo">{{ $task['completed_steps'] }}/{{ $task['total_steps'] }}</flux:badge>
                                    @elseif ($task['status'] === 'completed')
                                        <flux:badge color="green">{{ __('Completed') }}</flux:badge>
                                    @elseif ($task['status'] === 'failed')
                                        <flux:badge color="red">{{ __('Failed') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc">{{ __('Cancelled') }}</flux:badge>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell align="end">{{ $task['total_tokens'] > 0 ? number_format($task['total_tokens']) : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $task['total_cost'] > 0 ? '$' . number_format($task['total_cost'], 4) : '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $task['duration'] ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $task['created_at'] }}</flux:table.cell>

                                <flux:table.cell align="end">
                                    @if ($this->permissions->canUpdateTeam)
                                        @if ($task['status'] === 'running' || $task['status'] === 'pending')
                                            <flux:button variant="ghost" size="xs" wire:click.stop="cancelTask({{ $task['id'] }})">{{ __('Cancel') }}</flux:button>
                                        @elseif ($task['status'] === 'failed')
                                            <flux:button variant="ghost" size="xs" icon="sparkles" wire:click.stop="retryTask({{ $task['id'] }})">{{ __('Retry') }}</flux:button>
                                        @endif
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>

                            {{-- Expandable step detail --}}
                            @if ($expandedTaskId === $task['id'] && count($task['steps']) > 0)
                                <flux:table.row>
                                    <flux:table.cell colspan="7">
                                        <div class="py-2" wire:click.stop>
                                            @if ($task['status'] === 'failed' && $task['error'])
                                                <flux:callout variant="danger" icon="exclamation-circle" class="mb-4">
                                                    <flux:callout.text>{{ $task['error'] }}</flux:callout.text>
                                                </flux:callout>
                                            @endif

                                            <flux:table>
                                                <flux:table.columns>
                                                    <flux:table.column>{{ __('Agent') }}</flux:table.column>
                                                    <flux:table.column>{{ __('Model') }}</flux:table.column>
                                                    <flux:table.column align="end">{{ __('Tokens') }}</flux:table.column>
                                                    <flux:table.column align="end">{{ __('Cost') }}</flux:table.column>
                                                    <flux:table.column align="end">{{ __('Loops') }}</flux:table.column>
                                                    <flux:table.column align="end">{{ __('Time') }}</flux:table.column>
                                                    <flux:table.column align="end">{{ __('Status') }}</flux:table.column>
                                                </flux:table.columns>
                                                <flux:table.rows>
                                                    @foreach ($task['steps'] as $step)
                                                        <flux:table.row>
                                                            <flux:table.cell variant="strong">{{ $step['label'] }}</flux:table.cell>
                                                            <flux:table.cell>{{ $step['model'] ? Str::afterLast($step['model'], '/') : '—' }}</flux:table.cell>
                                                            <flux:table.cell align="end">{{ $step['input_tokens'] + $step['output_tokens'] > 0 ? number_format($step['input_tokens'] + $step['output_tokens']) : '—' }}</flux:table.cell>
                                                            <flux:table.cell align="end">{{ $step['cost'] > 0 ? '$' . number_format($step['cost'], 4) : '—' }}</flux:table.cell>
                                                            <flux:table.cell align="end">{{ $step['iterations'] > 0 ? $step['iterations'] : '—' }}</flux:table.cell>
                                                            <flux:table.cell align="end">{{ $step['duration'] ?? '—' }}</flux:table.cell>
                                                            <flux:table.cell align="end">
                                                                @if ($step['status'] === 'completed')
                                                                    <flux:badge color="green" size="sm">✓</flux:badge>
                                                                @elseif ($step['status'] === 'running')
                                                                    <flux:icon.loading class="size-4 text-indigo-400" />
                                                                @elseif ($step['status'] === 'failed')
                                                                    <flux:badge color="red" size="sm">✕</flux:badge>
                                                                @elseif ($step['status'] === 'skipped')
                                                                    <flux:badge color="zinc" size="sm">—</flux:badge>
                                                                @else
                                                                    <flux:badge color="zinc" size="sm">○</flux:badge>
                                                                @endif
                                                            </flux:table.cell>
                                                        </flux:table.row>
                                                    @endforeach
                                                </flux:table.rows>
                                            </flux:table>
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endif
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @else
                <flux:card class="py-8 text-center">
                    <flux:icon name="sparkles" class="mx-auto text-zinc-500" />
                    <flux:text class="mt-2">{{ __('No AI tasks have been run yet.') }}</flux:text>
                </flux:card>
            @endif
        </div>
    </flux:main>
</section>
