<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agents\BrandEnricherAgent;
use App\Services\Writer\Brief;

class EnrichBrandToolHandler
{
    public function __construct(private ?BrandEnricherAgent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = [], ?ConversationBus $bus = null): string
    {
        $callsSoFar = collect($priorTurnTools)->where('name', 'enrich_brand_context')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'Already ran enrich_brand_context this turn. Continue to the next pipeline step.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        if (! $brief->hasResearch()) {
            return json_encode([
                'status' => 'error',
                'message' => 'Cannot enrich brand context without research. Run research_topic first.',
            ]);
        }

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new BrandEnricherAgent($extraContext) : ($this->agent ?? new BrandEnricherAgent);

        // Skip cleanly when there is nothing to fetch. Mirrors how the style
        // reference agent reports {status: skipped} when blog_url is empty.
        if (! $agent->hasBrandUrls($team)) {
            return json_encode([
                'status' => 'skipped',
                'message' => 'No homepage_url or product_urls configured for this team — nothing to enrich.',
            ]);
        }

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

        $claimCount = count($result->brief->brandClaims()['claims'] ?? []);
        if ($claimCount === 0) {
            return json_encode([
                'status' => 'skipped',
                'message' => 'Brand pages fetched but no topic-relevant claims found.',
            ]);
        }

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
                'name' => 'enrich_brand_context',
                'description' => 'Run the Brand Enricher sub-agent. Fetches this brand\'s homepage and product/offer pages and extracts claims about the brand\'s own offers (with b-prefixed ids). Fills brief.brand_claims. Requires brief.research. Returns status=skipped if no brand URLs are configured — treat as success and continue.',
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
