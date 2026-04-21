<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Agents\StyleReferenceAgent;
use App\Services\Writer\Brief;

class FakeStyleReferenceAgent extends StyleReferenceAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function convWithOutline(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $topic = Topic::create([
        'team_id' => $team->id, 'title' => 'Knives', 'angle' => 'Pro kitchens', 'status' => 'available',
    ]);

    $brief = [
        'topic' => ['id' => $topic->id, 'title' => 'Knives', 'angle' => 'Pro kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
        'outline' => [
            'angle' => 'Pro maintenance',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ],
    ];

    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id,
        'title' => 't', 'type' => 'writer', 'topic_id' => $topic->id,
        'brief' => $brief,
    ]);

    return [$team, $conversation, $topic];
}

test('handler returns skipped when no blog_url and no style_reference_urls', function () {
    [$team, $conversation] = convWithOutline();
    // team has no blog_url and empty style_reference_urls by default

    $handler = new FetchStyleReferenceToolHandler;
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('skipped');
    expect($decoded['reason'])->toContain('blog URL');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasStyleReference())->toBeFalse();
});

test('handler fetches bodies, persists brief, returns ok', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    $bodyLessBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'Post One', 'why_chosen' => 'Good hook.', 'body' => ''],
            ['url' => 'https://brand.com/post-2', 'title' => 'Post Two', 'why_chosen' => 'Direct.', 'body' => ''],
        ],
        'reasoning' => 'Best examples.',
    ]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::ok(
        $bodyLessBrief,
        ['kind' => 'style_reference', 'summary' => 'Style reference: 2 examples selected', 'examples' => [
            ['title' => 'Post One', 'why_chosen' => 'Good hook.'],
            ['title' => 'Post Two', 'why_chosen' => 'Direct.'],
        ]],
        'Style reference: 2 examples selected',
    );

    // Fake URL fetcher returns long-enough bodies
    $fakeFetcher = fn (string $url) => str_repeat('word ', 100); // 500 chars, above 400 threshold

    $handler = new FetchStyleReferenceToolHandler($agent, $fakeFetcher);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['card']['kind'])->toBe('style_reference');

    $conversation->refresh();
    $ref = Brief::fromJson($conversation->brief)->styleReference();
    expect($ref)->not->toBeNull();
    expect($ref['examples'])->toHaveCount(2);
    expect(strlen($ref['examples'][0]['body']))->toBeGreaterThan(0);
});

test('handler drops examples with body shorter than 400 chars, passes if 2+ survive', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    $bodyLessBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'Post One', 'why_chosen' => 'Good.', 'body' => ''],
            ['url' => 'https://brand.com/post-2', 'title' => 'Post Two', 'why_chosen' => 'Great.', 'body' => ''],
            ['url' => 'https://brand.com/post-3', 'title' => 'Post Three', 'why_chosen' => 'Nice.', 'body' => ''],
        ],
        'reasoning' => 'Three examples.',
    ]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::ok($bodyLessBrief, ['kind' => 'style_reference', 'summary' => 'Style reference: 3 examples selected', 'examples' => []], 'ok');

    $callCount = 0;
    $fakeFetcher = function (string $url) use (&$callCount): string {
        $callCount++;
        // First URL returns too-short body; others return long bodies
        return $callCount === 1 ? 'short' : str_repeat('word ', 100);
    };

    $handler = new FetchStyleReferenceToolHandler($agent, $fakeFetcher);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');

    $conversation->refresh();
    $ref = Brief::fromJson($conversation->brief)->styleReference();
    expect($ref['examples'])->toHaveCount(2); // post-1 was dropped
});

test('handler returns error when fewer than 2 examples survive body fetch', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    $bodyLessBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'P1', 'why_chosen' => 'w', 'body' => ''],
            ['url' => 'https://brand.com/post-2', 'title' => 'P2', 'why_chosen' => 'w', 'body' => ''],
        ],
        'reasoning' => 'r',
    ]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::ok($bodyLessBrief, ['kind' => 'style_reference', 'summary' => 'ok', 'examples' => []], 'ok');

    $fakeFetcher = fn (string $url) => 'too short'; // all below threshold

    $handler = new FetchStyleReferenceToolHandler($agent, $fakeFetcher);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('2');
});

test('handler is idempotent on duplicate in-turn call', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    // Pre-populate style_reference in brief
    $refBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'u1', 'title' => 'Post One', 'why_chosen' => 'w', 'body' => 'body text'],
            ['url' => 'u2', 'title' => 'Post Two', 'why_chosen' => 'w', 'body' => 'body text'],
        ],
        'reasoning' => 'r',
    ]);
    $conversation->update(['brief' => $refBrief->toJson()]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new FetchStyleReferenceToolHandler($agent);
    $priorTurnTools = [['name' => 'fetch_style_reference', 'args' => [], 'status' => 'ok']];
    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('already');
});

test('toolSchema returns valid schema', function () {
    $schema = FetchStyleReferenceToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('fetch_style_reference');
});
