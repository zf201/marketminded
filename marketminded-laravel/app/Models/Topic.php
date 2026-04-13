<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'conversation_id', 'title', 'angle', 'sources', 'status', 'score'])]
class Topic extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'score' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Topic $topic) {
            $topic->created_at ??= now();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
