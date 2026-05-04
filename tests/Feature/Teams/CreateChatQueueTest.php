<?php

use App\Jobs\RunConversationTurn;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('create chat dispatches writer turn to the dedicated queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $team->update(['ai_api_key' => 'test-key']);

    $this->actingAs($user);

    Livewire::test('pages::teams.create-chat', ['current_team' => $team])
        ->set('type', 'writer')
        ->set('freeForm', true)
        ->set('prompt', 'Write a short post about async queues.')
        ->call('submitPrompt')
        ->assertSet('isStreaming', true);

    $conversation = $team->conversations()->first();

    expect($conversation)->not->toBeNull()
        ->and(Message::where('conversation_id', $conversation->id)->where('role', 'user')->count())->toBe(1);

    Queue::assertPushedOn('writer', RunConversationTurn::class, function (RunConversationTurn $job) use ($team, $conversation) {
        return $job->connection === 'database_writer'
            && $job->teamId === $team->id
            && $job->conversationId === $conversation->id;
    });
});
