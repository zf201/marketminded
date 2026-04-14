<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Services\WriteBlogPostToolHandler;

function writerConversationWithTopic(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Zero Party Data',
        'angle' => 'Privacy angle',
        'status' => 'available',
    ]);
    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 'W',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'writer_mode' => 'autopilot',
    ]);
    return [$team, $conversation, $topic];
}

function addToolMessage(Conversation $c, string $toolName, array $args = []): void
{
    Message::create([
        'conversation_id' => $c->id,
        'role' => 'assistant',
        'content' => '',
        'metadata' => [
            'tools' => [['name' => $toolName, 'args' => $args]],
        ],
    ]);
}

test('write_blog_post gates on missing research_topic', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'create_outline');

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'T',
        'body' => 'B',
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('research_topic');
});

test('write_blog_post gates on missing create_outline', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'research_topic');

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'T',
        'body' => 'B',
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('create_outline');
});

test('write_blog_post creates content piece with v1 when prerequisites met', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'research_topic');
    addToolMessage($conversation, 'create_outline');

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'The Case for Zero Party Data',
        'body' => "# Intro\n\nBody.",
    ], $topic);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['version'])->toBe(1);

    $piece = ContentPiece::findOrFail($decoded['content_piece_id']);
    expect($piece->title)->toBe('The Case for Zero Party Data');
    expect($piece->body)->toBe("# Intro\n\nBody.");
    expect($piece->current_version)->toBe(1);
    expect($piece->topic_id)->toBe($topic->id);
    expect($piece->conversation_id)->toBe($conversation->id);
    expect($piece->team_id)->toBe($team->id);
    expect($piece->status)->toBe('draft');
    expect($piece->platform)->toBe('blog');
    expect($piece->format)->toBe('pillar');
    expect($piece->versions()->count())->toBe(1);

    expect($topic->refresh()->status)->toBe('used');
});

test('write_blog_post refuses when piece already exists for conversation', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();
    addToolMessage($conversation, 'research_topic');
    addToolMessage($conversation, 'create_outline');

    $handler = new WriteBlogPostToolHandler;
    $first = $handler->execute($team, $conversation->id, ['title' => 'A', 'body' => 'B'], $topic);
    expect(json_decode($first, true)['status'])->toBe('ok');

    $second = $handler->execute($team, $conversation->id, ['title' => 'A2', 'body' => 'B2'], $topic);
    $decoded = json_decode($second, true);
    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('update_blog_post');
});

test('write_blog_post accepts in-turn research_topic and create_outline (no persisted messages)', function () {
    [$team, $conversation, $topic] = writerConversationWithTopic();

    $priorTurnTools = [
        ['name' => 'research_topic', 'args' => []],
        ['name' => 'create_outline', 'args' => []],
    ];

    $handler = new WriteBlogPostToolHandler;
    $result = $handler->execute($team, $conversation->id, [
        'title' => 'T',
        'body' => 'B',
    ], $topic, $priorTurnTools);

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe('ok');
    expect($decoded['version'])->toBe(1);
});

test('toolSchema returns valid schema', function () {
    $schema = WriteBlogPostToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('write_blog_post');
});
