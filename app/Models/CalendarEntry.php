<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEntry extends Model
{
    protected $fillable = [
        'calendar_id', 'team_id', 'scheduled_for',
        'title', 'image_headline', 'image_prompt',
        'linkedin_copy', 'instagram_copy', 'facebook_copy',
        'notes',
        'source_topic_id', 'source_social_post_id', 'source_content_piece_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (CalendarEntry $entry) {
            if ($entry->source_topic_id && ($entry->wasRecentlyCreated || $entry->wasChanged('source_topic_id'))) {
                Topic::whereKey($entry->source_topic_id)->update(['used' => true]);
            }
            if ($entry->source_social_post_id && ($entry->wasRecentlyCreated || $entry->wasChanged('source_social_post_id'))) {
                SocialPost::whereKey($entry->source_social_post_id)->update(['used' => true]);
            }
            if ($entry->source_content_piece_id && ($entry->wasRecentlyCreated || $entry->wasChanged('source_content_piece_id'))) {
                ContentPiece::whereKey($entry->source_content_piece_id)->update(['used' => true]);
            }
        });
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ContentCalendar::class, 'calendar_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sourceTopic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'source_topic_id');
    }

    public function sourceSocialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'source_social_post_id');
    }

    public function sourceContentPiece(): BelongsTo
    {
        return $this->belongsTo(ContentPiece::class, 'source_content_piece_id');
    }
}
