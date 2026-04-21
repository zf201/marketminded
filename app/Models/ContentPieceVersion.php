<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_piece_id', 'version', 'title', 'body', 'change_description'])]
class ContentPieceVersion extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ContentPieceVersion $v) {
            $v->created_at ??= now();
        });
    }

    public function contentPiece(): BelongsTo
    {
        return $this->belongsTo(ContentPiece::class);
    }
}
