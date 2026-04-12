<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_task_id', 'name', 'label', 'status', 'model', 'input_tokens', 'output_tokens', 'cost', 'iterations', 'error', 'started_at', 'completed_at'])]
class AiTaskStep extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cost' => 'decimal:6',
        ];
    }

    public function aiTask(): BelongsTo
    {
        return $this->belongsTo(AiTask::class);
    }

    public function markRunning(string $model): void
    {
        $this->update([
            'status' => 'running',
            'model' => $model,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $usage): void
    {
        $this->update([
            'status' => 'completed',
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cost' => $usage['cost'] ?? 0,
            'iterations' => $usage['iterations'] ?? 0,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
