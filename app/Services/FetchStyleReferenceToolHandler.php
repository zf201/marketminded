<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\StyleReferenceAgent;
use App\Services\Writer\Brief;

class FetchStyleReferenceToolHandler
{
    private const MIN_BODY_CHARS = 400;

    /** @param callable|null $urlFetcher fn(string $url): string — injected in tests */
    public function __construct(
        private ?Agent $agent = null,
        private $urlFetcher = null,
    ) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
    {
        $hasBlogUrl = ! empty($team->blog_url);
        $hasCuratedUrls = ! empty($team->style_reference_urls);

        if (! $hasBlogUrl && ! $hasCuratedUrls) {
            return json_encode([
                'status' => 'skipped',
                'reason' => 'No blog URL or style reference URLs configured for this team.',
            ]);
        }

        $callsSoFar = collect($priorTurnTools)->where('name', 'fetch_style_reference')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            $conversation = Conversation::findOrFail($conversationId);
            $ref = Brief::fromJson($conversation->brief ?? [])->styleReference();
            return json_encode([
                'status' => 'ok',
                'summary' => 'Style reference already fetched this turn',
                'card' => [
                    'kind' => 'style_reference',
                    'summary' => 'Style reference already fetched this turn',
                    'examples' => array_map(fn ($ex) => [
                        'title' => $ex['title'],
                        'why_chosen' => $ex['why_chosen'],
                    ], $ref['examples'] ?? []),
                ],
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new StyleReferenceAgent($extraContext) : ($this->agent ?? new StyleReferenceAgent);
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

        // Fetch bodies for each submitted example and rebuild the style_reference slot.
        $fetcher = $this->urlFetcher ?? fn (string $url) => (new UrlFetcher)->fetch($url);
        $examples = $result->brief->styleReference()['examples'] ?? [];
        $kept = [];

        foreach ($examples as $ex) {
            $body = $fetcher($ex['url']);
            if (strlen($body) < self::MIN_BODY_CHARS) {
                continue;
            }
            $ex['body'] = $body;
            $kept[] = $ex;
        }

        if (count($kept) < 2) {
            return json_encode([
                'status' => 'error',
                'message' => 'Style reference: fewer than 2 examples had fetchable content (need at least 2).',
            ]);
        }

        $finalBrief = $result->brief->withStyleReference([
            'examples' => $kept,
            'reasoning' => $result->brief->styleReference()['reasoning'] ?? '',
        ]);

        $conversation->update(['brief' => $finalBrief->toJson()]);

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
                'name' => 'fetch_style_reference',
                'description' => 'Run the StyleReference sub-agent. Reads team blog_url / style_reference_urls; writes brief.style_reference with 2–3 exemplar posts and their full bodies. Returns status=skipped if no blog URL or style reference URLs are configured.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
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
