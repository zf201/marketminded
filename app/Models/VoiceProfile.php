<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'voice_analysis', 'content_types', 'should_avoid', 'should_use', 'style_inspiration', 'preferred_length'])]
class VoiceProfile extends Model
{
    protected $attributes = [
        'preferred_length' => 1500,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
