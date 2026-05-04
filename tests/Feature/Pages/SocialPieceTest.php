<?php

use App\Models\ContentPiece;
use App\Models\Conversation;
use App\Models\SocialPost;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->user->update(['current_team_id' => $this->team->id]);
    $this->piece = ContentPiece::factory()->create(['team_id' => $this->team->id, 'title' => 'Source']);
});

it('renders cards for each active post', function () {
    SocialPost::factory()->create([
        'team_id' => $this->team->id, 'content_piece_id' => $this->piece->id,
        'platform' => 'linkedin', 'hook' => 'LinkedIn Hook Here', 'body' => 'Body [POST_URL]',
    ]);
    SocialPost::factory()->create([
        'team_id' => $this->team->id, 'content_piece_id' => $this->piece->id,
        'platform' => 'instagram', 'hook' => 'IG Hook Here', 'body' => 'Body [POST_URL]',
    ]);

    actingAs($this->user)
        ->get(route('social.show', ['current_team' => $this->team, 'contentPiece' => $this->piece]))
        ->assertOk()
        ->assertSee('LinkedIn Hook Here')
        ->assertSee('IG Hook Here')
        ->assertSee('[POST_URL]');
});

it('updateScore persists the score within team scope', function () {
    actingAs($this->user);
    $post = SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $this->piece->id]);

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('updateScore', $post->id, 8);

    expect($post->fresh()->score)->toBe(8);
});

it('togglePosted flips posted_at', function () {
    actingAs($this->user);
    $post = SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $this->piece->id]);

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('togglePosted', $post->id);
    expect($post->fresh()->posted_at)->not->toBeNull();

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('togglePosted', $post->id);
    expect($post->fresh()->posted_at)->toBeNull();
});

it('deletePost soft-deletes', function () {
    actingAs($this->user);
    $post = SocialPost::factory()->create(['team_id' => $this->team->id, 'content_piece_id' => $this->piece->id]);

    Livewire::test('pages::teams.social-piece', ['current_team' => $this->team, 'contentPiece' => $this->piece])
        ->call('deletePost', $post->id);

    expect($post->fresh()->status)->toBe('deleted');
});

it('refine button links to most recent funnel conversation when one exists', function () {
    $conv = Conversation::factory()->create([
        'team_id' => $this->team->id,
        'type' => 'funnel',
        'content_piece_id' => $this->piece->id,
    ]);
    SocialPost::factory()->create([
        'team_id' => $this->team->id,
        'content_piece_id' => $this->piece->id,
        'conversation_id' => $conv->id,
    ]);

    actingAs($this->user)
        ->get(route('social.show', ['current_team' => $this->team, 'contentPiece' => $this->piece]))
        ->assertSee(route('create.chat', ['current_team' => $this->team, 'conversation' => $conv]), false);
});

it('blocks access to a piece from another team', function () {
    $other = Team::factory()->create();
    $foreign = ContentPiece::factory()->create(['team_id' => $other->id]);

    actingAs($this->user)
        ->get(route('social.show', ['current_team' => $this->team, 'contentPiece' => $foreign]))
        ->assertNotFound();
});
