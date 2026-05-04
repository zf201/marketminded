<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id', 'content_piece_id', 'conversation_id',
    'platform', 'hook', 'body', 'hashtags',
    'image_prompt', 'video_treatment',
    'score', 'posted_at', 'status', 'position',
])]
class SocialPost extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hashtags' => 'array',
            'score' => 'integer',
            'position' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function contentPiece(): BelongsTo
    {
        return $this->belongsTo(ContentPiece::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
