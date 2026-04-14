<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'team_id',
    'conversation_id',
    'topic_id',
    'title',
    'body',
    'status',
    'platform',
    'format',
    'current_version',
])]
class ContentPiece extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentPieceVersion::class)->orderByDesc('version');
    }

    /**
     * Atomically snapshot a new version and update the piece's current state.
     */
    public function saveSnapshot(string $title, string $body, ?string $changeDescription = null): ContentPieceVersion
    {
        return DB::transaction(function () use ($title, $body, $changeDescription) {
            $this->current_version = $this->current_version + 1;
            $this->title = $title;
            $this->body = $body;
            $this->save();

            return $this->versions()->create([
                'version' => $this->current_version,
                'title' => $title,
                'body' => $body,
                'change_description' => $changeDescription,
            ]);
        });
    }
}
