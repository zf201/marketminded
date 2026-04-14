<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'user_id', 'title', 'type', 'writer_mode', 'topic_id', 'brief'])]
class Conversation extends Model
{
    protected $attributes = [
        'brief' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function contentPieces(): HasMany
    {
        return $this->hasMany(ContentPiece::class);
    }
}
