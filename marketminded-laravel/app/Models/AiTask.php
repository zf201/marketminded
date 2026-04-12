<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'type', 'label', 'status', 'current_step', 'total_steps', 'completed_steps', 'error', 'started_at', 'completed_at', 'cancelled_at', 'total_tokens', 'total_cost'])]
class AiTask extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_cost' => 'decimal:6',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiTaskStep::class);
    }

    public function scopeRunning($query)
    {
        return $query->whereIn('status', ['pending', 'running']);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running']);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $totals = $this->steps()->selectRaw('SUM(input_tokens + output_tokens) as tokens, SUM(cost) as cost')->first();

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'total_tokens' => (int) ($totals->tokens ?? 0),
            'total_cost' => $totals->cost ?? 0,
        ]);
    }

    public function markFailed(string $error): void
    {
        $totals = $this->steps()->selectRaw('SUM(input_tokens + output_tokens) as tokens, SUM(cost) as cost')->first();

        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
            'total_tokens' => (int) ($totals->tokens ?? 0),
            'total_cost' => $totals->cost ?? 0,
        ]);
    }

    public function markCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->steps()->where('status', 'pending')->update(['status' => 'skipped']);
    }
}
