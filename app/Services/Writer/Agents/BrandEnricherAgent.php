<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class BrandEnricherAgent extends BaseAgent
{
    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];
        $title = $topic['title'] ?? '';
        $angle = $topic['angle'] ?? '';

        $urls = $this->brandUrls($team);
        $urlsBlock = empty($urls)
            ? '(none — submit zero claims and zero sources)'
            : "\n- " . implode("\n- ", $urls);

        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the Brand Enricher sub-agent. Your ONLY output is a `submit_brand_claims` tool call.
- Do NOT write any text. No planning, explaining, thinking aloud, or asking questions.
- Use fetch_url to read each brand URL below in full, then extract verifiable single-sentence claims about THIS brand's own offers, products, prices, differentiators, and value propositions.
- Call submit_brand_claims immediately after — never summarise in text.
- If uncertain about any field, call the tool with best-effort values — never refuse or ask for clarification.

## Why this exists
The Research sub-agent gathers general industry claims. Those often pull competitor names and external products into the post. This step adds claims about THE BRAND'S OWN offers so the Writer can anchor the post on what THIS team actually sells. The Writer's "cite by id" rule will then pick up these claims automatically alongside the research claims.

## Workflow
1. Read the brand URLs below (homepage first, then product/offer pages if listed).
2. Fetch EACH URL with fetch_url. Do not skip any — even if the topic seems unrelated, fetch them and decide based on the body.
3. Extract 3-10 verifiable single-sentence claims about the brand's own offers, plans, prices, named features, value props, or differentiators.
4. Only extract facts that are RELEVANT to the topic below. Skip generic boilerplate ("we care about customers") and marketing fluff.
5. Call submit_brand_claims with claims and sources.

## Quality rules
- Each claim must be a single declarative sentence about THIS brand (not generic industry knowledge).
- Each claim must have type: `offer`, `price`, `feature`, `value_prop`, or `differentiator`.
- Each claim must cite at least one source id (b1, b2, ...).
- Source IDs use the `b` prefix (b1, b2, ...) so they do not collide with research source ids (s1, s2, ...).
- Claim IDs also use the `b` prefix (bc1, bc2, ...) for the same reason.
- Prefer concrete facts: product names, plan tiers, prices, named features. Skip vague positioning statements.
- If no brand URLs are listed below, submit ZERO claims and ZERO sources — the handler will mark this step skipped.

## Topic (reference data — guides what to extract; do not echo back)
<topic>
Title: {$title}
Angle: {$angle}
</topic>

## Brand URLs to fetch (reference data — do not echo back; fetch each one)
<brand-urls>{$urlsBlock}
</brand-urls>
{$extra}

## IMPORTANT
Call `submit_brand_claims` now. Do not write anything — the tool call is your complete output.
PROMPT;
    }

    /** @return array<int, string> */
    private function brandUrls(Team $team): array
    {
        $urls = [];
        if (! empty($team->homepage_url)) {
            $urls[] = $team->homepage_url;
        }
        if (is_array($team->product_urls)) {
            foreach ($team->product_urls as $u) {
                if (is_string($u) && $u !== '') {
                    $urls[] = $u;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    public function hasBrandUrls(Team $team): bool
    {
        return ! empty($this->brandUrls($team));
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_brand_claims',
                'description' => 'Submit structured claims about THIS brand\'s own offers, drawn from the brand URLs you fetched. Your ONLY valid output is calling this tool. Submit zero claims/sources only if no brand URLs were available.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['claims', 'sources'],
                    'properties' => [
                        'claims' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'text', 'type', 'source_ids'],
                                'properties' => [
                                    'id' => ['type' => 'string', 'description' => 'Use the b prefix: bc1, bc2, ...'],
                                    'text' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => ['offer', 'price', 'feature', 'value_prop', 'differentiator']],
                                    'source_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                                ],
                            ],
                        ],
                        'sources' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['id', 'url', 'title'],
                                'properties' => [
                                    'id' => ['type' => 'string', 'description' => 'Use the b prefix: b1, b2, ...'],
                                    'url' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [BrandIntelligenceToolHandler::fetchUrlToolSchema()];
    }

    protected function useServerTools(): bool
    {
        // No web search — this agent only reads the brand's own pre-known URLs.
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.3;
    }

    protected function timeout(): int
    {
        return 240;
    }

    protected function validate(array $payload): ?string
    {
        $claims = $payload['claims'] ?? [];
        $sources = $payload['sources'] ?? [];

        // Zero claims is valid — means no URLs were available or nothing relevant
        // was extracted. The handler turns that into a skipped status.
        if (count($claims) === 0 && count($sources) === 0) {
            return null;
        }

        if (count($sources) === 0) {
            return 'Brand claims must cite at least one source.';
        }

        $claimIds = array_map(fn ($c) => $c['id'] ?? '', $claims);
        if (count($claimIds) !== count(array_unique($claimIds))) {
            return 'Brand claims has duplicate claim ids.';
        }

        $sourceIds = array_map(fn ($s) => $s['id'] ?? '', $sources);
        if (count($sourceIds) !== count(array_unique($sourceIds))) {
            return 'Brand claims has duplicate source ids.';
        }

        $sourceIdSet = array_flip($sourceIds);
        foreach ($claims as $c) {
            foreach ($c['source_ids'] ?? [] as $sid) {
                if (! isset($sourceIdSet[$sid])) {
                    return "Brand claim {$c['id']} cites unknown source: {$sid}";
                }
            }
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        return $brief->withBrandClaims([
            'claims' => $payload['claims'] ?? [],
            'sources' => $payload['sources'] ?? [],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'brand_claims',
            'summary' => $this->buildSummary($payload),
            'claims' => $payload['claims'] ?? [],
            'sources' => $payload['sources'] ?? [],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $claims = count($payload['claims'] ?? []);
        $sources = count($payload['sources'] ?? []);
        if ($claims === 0) {
            return 'No brand pages available to enrich from';
        }
        return "Extracted {$claims} brand claims from {$sources} brand sources";
    }

    protected function agentTitle(): string { return 'Brand Enricher sub-agent'; }
    protected function agentColor(): string { return 'pink'; }
}
