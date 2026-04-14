<?php

namespace App\Services;

use App\Models\ContentPiece;
use App\Models\Message;
use App\Models\Team;
use App\Models\Topic;

class WriteBlogPostToolHandler
{
    /**
     * @param array $priorTurnTools Tool calls completed earlier in the same ask() turn.
     *                              Each entry: ['name' => string, 'args' => array]
     */
    public function execute(Team $team, int $conversationId, array $data, ?Topic $topic = null, array $priorTurnTools = []): string
    {
        $missing = $this->missingPrereqs($conversationId, $priorTurnTools);
        if (! empty($missing)) {
            return json_encode([
                'status' => 'error',
                'message' => 'You must call ' . implode(' and ', $missing) . ' before write_blog_post.',
            ]);
        }

        if (ContentPiece::where('team_id', $team->id)
            ->where('conversation_id', $conversationId)
            ->exists()) {
            return json_encode([
                'status' => 'error',
                'message' => 'A blog post already exists for this conversation. Use update_blog_post to revise it.',
            ]);
        }

        $title = $data['title'] ?? '';
        $body = $data['body'] ?? '';

        if ($title === '' || $body === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'title and body are required.',
            ]);
        }

        $piece = ContentPiece::create([
            'team_id' => $team->id,
            'conversation_id' => $conversationId,
            'topic_id' => $topic?->id,
            'title' => '',
            'body' => '',
            'status' => 'draft',
            'platform' => 'blog',
            'format' => 'pillar',
            'current_version' => 0,
        ]);

        $piece->saveSnapshot($title, $body, 'Initial draft');

        if ($topic) {
            $topic->update(['status' => 'used']);
        }

        return json_encode([
            'status' => 'ok',
            'content_piece_id' => $piece->id,
            'title' => $piece->title,
            'version' => $piece->current_version,
        ]);
    }

    /**
     * Returns an array of prerequisite tool names that were not found in either the
     * in-flight tool calls for this turn or the persisted conversation history.
     */
    private function missingPrereqs(int $conversationId, array $priorTurnTools = []): array
    {
        $needed = ['research_topic', 'create_outline'];
        $found = [];

        // Check in-flight tool calls from the current ask() turn first — the
        // assistant message isn't persisted until after the stream ends.
        foreach ($priorTurnTools as $tool) {
            $name = $tool['name'] ?? null;
            if (in_array($name, $needed, true)) {
                $found[$name] = true;
            }
        }

        // Fall back to persisted history (for cross-turn continuity, e.g. checkpoint mode).
        $messages = Message::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->get();

        foreach ($messages as $m) {
            foreach ($m->metadata['tools'] ?? [] as $tool) {
                $name = $tool['name'] ?? null;
                if (in_array($name, $needed, true)) {
                    $found[$name] = true;
                }
            }
        }

        return array_values(array_diff($needed, array_keys($found)));
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'write_blog_post',
                'description' => 'Produce the final blog post. Requires research_topic and create_outline tool calls earlier in this conversation.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => [
                            'type' => 'string',
                            'description' => 'Full blog post in markdown. 1200-2000 words. Every statistic, percentage, date, named entity, or quote must trace to a claim ID from research_topic.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
