<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\ContentPiece;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;

class CalendarEntryToolHandler
{
    private const ENTRY_FIELDS = [
        'scheduled_for', 'title', 'platform', 'image_headline', 'image_prompt', 'content', 'notes',
        'source_topic_id', 'source_social_post_id', 'source_content_piece_id',
        'status',
    ];

    public static function proposeSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'propose_entries',
                'description' => 'Create one or more calendar entries for the current calendar. Setting a source_*_id flips that entity to used.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['entries'],
                    'properties' => [
                        'entries' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => self::entrySchema(),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function updateSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_entry',
                'description' => 'Patch one calendar entry by id. Only include fields to change.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => array_merge(
                        ['id' => ['type' => 'integer']],
                        self::entrySchema()['properties'],
                    ),
                ],
            ],
        ];
    }

    public static function deleteSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'delete_entry',
                'description' => 'Delete one calendar entry by id. Does not unmark its source.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ];
    }

    private static function entrySchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scheduled_for', 'title'],
            'properties' => [
                'scheduled_for' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'title' => ['type' => 'string'],
                'platform' => ['type' => 'string', 'description' => 'Target platform, e.g. linkedin, instagram, facebook.'],
                'image_headline' => ['type' => 'string'],
                'image_prompt' => ['type' => 'string'],
                'content' => ['type' => 'string', 'description' => 'The post body for the chosen platform.'],
                'notes' => ['type' => 'string'],
                'source_topic_id' => ['type' => 'integer'],
                'source_social_post_id' => ['type' => 'integer'],
                'source_content_piece_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'ready', 'published']],
            ],
        ];
    }

    public static function propose(Team $team, ContentCalendar $calendar, array $args): array
    {
        $entries = $args['entries'] ?? [];
        if (empty($entries)) {
            return ['status' => 'error', 'message' => 'No entries provided.'];
        }

        foreach ($entries as $e) {
            if (! self::sourcesBelongToTeam($team, $e)) {
                return ['status' => 'error', 'message' => 'Source id does not belong to this team.'];
            }
        }

        $created = DB::transaction(function () use ($team, $calendar, $entries) {
            $ids = [];
            foreach ($entries as $e) {
                $row = $calendar->entries()->create(array_merge(
                    ['team_id' => $team->id],
                    collect($e)->only(self::ENTRY_FIELDS)->all(),
                ));
                $ids[] = $row->id;
            }
            return $ids;
        });

        return ['status' => 'ok', 'created_ids' => $created];
    }

    public static function update(Team $team, array $args): array
    {
        $id = $args['id'] ?? null;
        if (! $id) {
            return ['status' => 'error', 'message' => 'Missing id.'];
        }

        $entry = CalendarEntry::where('id', $id)->where('team_id', $team->id)->first();
        if (! $entry) {
            return ['status' => 'error', 'message' => 'Entry not found for this team.'];
        }

        if (! self::sourcesBelongToTeam($team, $args)) {
            return ['status' => 'error', 'message' => 'Source id does not belong to this team.'];
        }

        $entry->fill(collect($args)->only(self::ENTRY_FIELDS)->all())->save();
        return ['status' => 'ok', 'id' => $entry->id];
    }

    public static function delete(Team $team, array $args): array
    {
        $id = $args['id'] ?? null;
        if (! $id) {
            return ['status' => 'error', 'message' => 'Missing id.'];
        }

        $entry = CalendarEntry::where('id', $id)->where('team_id', $team->id)->first();
        if (! $entry) {
            return ['status' => 'error', 'message' => 'Entry not found for this team.'];
        }

        $entry->delete();
        return ['status' => 'ok'];
    }

    private static function sourcesBelongToTeam(Team $team, array $entry): bool
    {
        if (! empty($entry['source_topic_id'])
            && ! Topic::where('id', $entry['source_topic_id'])->where('team_id', $team->id)->exists()) {
            return false;
        }
        if (! empty($entry['source_social_post_id'])
            && ! SocialPost::where('id', $entry['source_social_post_id'])->where('team_id', $team->id)->exists()) {
            return false;
        }
        if (! empty($entry['source_content_piece_id'])
            && ! ContentPiece::where('id', $entry['source_content_piece_id'])->where('team_id', $team->id)->exists()) {
            return false;
        }
        return true;
    }
}
