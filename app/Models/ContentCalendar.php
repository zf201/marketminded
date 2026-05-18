<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCalendar extends Model
{
    protected $fillable = ['team_id', 'posting_days'];

    protected function casts(): array
    {
        return [
            'posting_days' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CalendarEntry::class, 'calendar_id');
    }
}
