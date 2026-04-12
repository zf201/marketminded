<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role', 'content', 'model', 'input_tokens', 'output_tokens', 'cost'])]
class Message extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            $message->created_at ??= now();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
