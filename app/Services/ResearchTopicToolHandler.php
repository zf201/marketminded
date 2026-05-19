<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\ResearchAgent;
use App\Services\Writer\Brief;

class ResearchTopicToolHandler
{
    public function __construct(private ?ResearchAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'research_topic')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already retried research_topic this turn. Get help from the user.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        // When the chat started ad-hoc (no backlog topic linked), brief.topic
        // is empty and the sub-agent has nothing to research. The orchestrator
        // knows the title/angle from the conversation, so accept them here and
        // populate the brief before dispatching. Caller-provided values always
        // override existing brief.topic so the orchestrator can correct course
        // on retries.
        $title = trim((string) ($args['title'] ?? ''));
        $angle = trim((string) ($args['angle'] ?? ''));
        if ($title !== '' || $angle !== '') {
            $existing = $brief->topic() ?? [];
            // Always set both keys (even when empty) so downstream agents can
            // safely read $topic['angle'] without an "Undefined array key"
            // notice, which Laravel surfaces as an ErrorException and the
            // tool handler returns to the orchestrator as a confusing failure.
            $brief = $brief->withTopic(array_merge($existing, [
                'title' => $title !== '' ? $title : ($existing['title'] ?? ''),
                'angle' => $angle !== '' ? $angle : ($existing['angle'] ?? ''),
            ]));
            $conversation->update(['brief' => $brief->toJson()]);
        }

        if (($brief->topic()['title'] ?? '') === '') {
            return json_encode([
                'status' => 'error',
                'message' => 'No topic title set on the brief. Retry research_topic with `title` (and optionally `angle`) describing what to research.',
            ]);
        }

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new ResearchAgent($extraContext) : ($this->agent ?? new ResearchAgent);
        $agent->conversationId = $conversationId;
        $agent->bus = $bus;

        try {
            $result = $agent->execute($brief, $team);
        } catch (TurnStoppedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'research_topic',
                'description' => 'Run the Research sub-agent. Writes brief.research with structured claims sourced via web search. If brief.topic is already populated (chat started from a backlog topic) you can omit title/angle. If the chat started ad-hoc you MUST pass title (and optionally angle) so the researcher knows what to look up — without them the search wanders into generic territory.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'The blog post topic / working title in the user\'s language. Pass this whenever the topic was not picked from the backlog. Overrides any existing brief.topic.title.',
                        ],
                        'angle' => [
                            'type' => 'string',
                            'description' => 'Optional one-sentence angle / framing for the post. Helps the researcher focus on the right facets.',
                        ],
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
