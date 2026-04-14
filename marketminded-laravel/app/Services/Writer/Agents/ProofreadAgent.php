<?php

namespace App\Services\Writer\Agents;

use App\Models\ContentPiece;
use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class ProofreadAgent extends BaseAgent
{
    public function __construct(
        protected string $feedback = '',
        ?string $extraContext = null,
    ) {
        parent::__construct($extraContext);
    }

    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasContentPiece()) {
            return AgentResult::error('No content piece to proofread. Run write_blog_post first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->where('id', $brief->contentPieceId())
            ->firstOrFail();

        $extra = $this->extraContextBlock();

        return <<<PROMPT
You are the Proofread sub-agent. Your single job is to revise an existing
blog post based on user feedback, then submit the revision via the
submit_revision tool. You do NOT narrate or commentary — only the tool call.

## How to work
1. Read the user feedback below.
2. Apply the requested changes surgically. Do NOT rewrite the whole post.
3. Match the existing voice. Preserve sourced facts.
4. Submit via submit_revision with a clear change_description.

## User feedback
{$this->feedback}

## Current title
{$piece->title}

## Current body
{$piece->body}
{$extra}
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_revision',
                'description' => 'Submit the revised blog post. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['title', 'body', 'change_description'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'change_description' => ['type' => 'string', 'description' => 'Short summary of what changed'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.4;
    }

    protected function validate(array $payload): ?string
    {
        if (trim($payload['title'] ?? '') === '') {
            return 'Revision title must not be empty.';
        }
        if (trim($payload['body'] ?? '') === '') {
            return 'Revision body must not be empty.';
        }
        if (trim($payload['change_description'] ?? '') === '') {
            return 'change_description must not be empty.';
        }
        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $piece = ContentPiece::where('team_id', $team->id)
            ->where('id', $brief->contentPieceId())
            ->firstOrFail();

        $piece->saveSnapshot($payload['title'], $payload['body'], $payload['change_description']);

        // Brief unchanged — content_piece_id stays the same; ContentPiece
        // model holds the new state via saveSnapshot.
        return $brief;
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'content_piece',
            'summary' => $this->buildSummary($payload),
            'title' => $payload['title'],
            'preview' => mb_substr(strip_tags($payload['body']), 0, 200),
            'change_description' => $payload['change_description'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return 'Revised · ' . $payload['change_description'];
    }
}
