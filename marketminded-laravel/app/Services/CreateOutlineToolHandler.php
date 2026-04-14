<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Team;

class CreateOutlineToolHandler
{
    /**
     * @param array $priorTurnTools Tool calls completed earlier in the same ask() turn.
     *                              Each entry: ['name' => string, 'args' => array]
     */
    public function execute(Team $team, int $conversationId, array $data, array $priorTurnTools = []): string
    {
        $knownIds = $this->claimIdsFromPriorTurnTools($priorTurnTools)
            ?? $this->latestResearchClaimIds($conversationId);

        if ($knownIds === null) {
            return json_encode([
                'status' => 'error',
                'message' => 'No research_topic output found in this conversation. Call research_topic first.',
            ]);
        }

        $unknown = [];
        foreach ($data['sections'] ?? [] as $section) {
            foreach ($section['claim_ids'] ?? [] as $id) {
                if (! in_array($id, $knownIds, true) && ! in_array($id, $unknown, true)) {
                    $unknown[] = $id;
                }
            }
        }

        if (! empty($unknown)) {
            return json_encode([
                'status' => 'error',
                'message' => 'Unknown claim IDs: ' . implode(', ', $unknown),
            ]);
        }

        return json_encode([
            'status' => 'ok',
            'section_count' => count($data['sections'] ?? []),
        ]);
    }

    /**
     * @return array<string>|null  null when no research_topic output found
     */
    private function latestResearchClaimIds(int $conversationId): ?array
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->get();

        foreach ($messages as $m) {
            foreach ($m->metadata['tools'] ?? [] as $tool) {
                if (($tool['name'] ?? null) === 'research_topic') {
                    $claims = $tool['args']['claims'] ?? [];
                    return array_map(fn ($c) => (string) ($c['id'] ?? ''), $claims);
                }
            }
        }
        return null;
    }

    /**
     * Look for research_topic in tool calls made earlier in the same ask() turn.
     * The assistant message isn't persisted until the turn finishes, so
     * latestResearchClaimIds() can't see in-flight tool calls — this does.
     *
     * @return array<string>|null
     */
    private function claimIdsFromPriorTurnTools(array $priorTurnTools): ?array
    {
        foreach ($priorTurnTools as $tool) {
            if (($tool['name'] ?? null) === 'research_topic') {
                $claims = $tool['args']['claims'] ?? [];
                return array_map(fn ($c) => (string) ($c['id'] ?? ''), $claims);
            }
        }
        return null;
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_outline',
                'description' => 'Create the editorial outline. Each section must reference claim IDs from the research_topic output. Call this AFTER research_topic and BEFORE write_blog_post.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'angle', 'sections', 'target_length_words'],
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Working title'],
                        'angle' => ['type' => 'string', 'description' => 'Angle/positioning'],
                        'target_length_words' => ['type' => 'integer', 'description' => 'Target word count (1200-2000 typical).'],
                        'sections' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'items' => [
                                'type' => 'object',
                                'required' => ['heading', 'purpose', 'claim_ids'],
                                'properties' => [
                                    'heading' => ['type' => 'string'],
                                    'purpose' => ['type' => 'string'],
                                    'claim_ids' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
