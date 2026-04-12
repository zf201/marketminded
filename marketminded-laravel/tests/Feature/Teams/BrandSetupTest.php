<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('owner can save company setup via brand intelligence', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->set('blogUrl', 'https://example.com/blog')
        ->set('brandDescription', 'We make widgets for developers.')
        ->set('targetAudience', 'Senior developers at SaaS companies')
        ->set('toneKeywords', 'Professional, approachable')
        ->set('contentLanguage', 'English')
        ->call('saveSetup')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->blog_url)->toBe('https://example.com/blog');
    expect($team->brand_description)->toBe('We make widgets for developers.');
    expect($team->target_audience)->toBe('Senior developers at SaaS companies');
    expect($team->tone_keywords)->toBe('Professional, approachable');
    expect($team->content_language)->toBe('English');
});

test('admin can save company setup via brand intelligence', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->call('saveSetup')
        ->assertHasNoErrors();

    expect($team->fresh()->homepage_url)->toBe('https://example.com');
});

test('member cannot save company setup via brand intelligence', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->call('saveSetup')
        ->assertForbidden();
});

test('homepage url is required for company setup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('homepageUrl', '')
        ->call('saveSetup')
        ->assertHasErrors(['homepageUrl']);
});

test('urls must be valid for company setup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('homepageUrl', 'not-a-url')
        ->set('blogUrl', 'also-not-a-url')
        ->call('saveSetup')
        ->assertHasErrors(['homepageUrl', 'blogUrl']);
});

test('brand setup has correct defaults', function () {
    $team = Team::factory()->create();

    expect($team->homepage_url)->toBeNull();
    expect($team->blog_url)->toBeNull();
    expect($team->brand_description)->toBeNull();
    expect($team->target_audience)->toBeNull();
    expect($team->tone_keywords)->toBeNull();
    expect($team->content_language)->toBe('English');
});

test('optional fields can be cleared via brand intelligence', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'blog_url' => 'https://example.com/blog',
        'brand_description' => 'Old description',
        'target_audience' => 'Old audience',
        'tone_keywords' => 'Old keywords',
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('blogUrl', '')
        ->set('brandDescription', '')
        ->set('targetAudience', '')
        ->set('toneKeywords', '')
        ->call('saveSetup')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->blog_url)->toBeNull();
    expect($team->brand_description)->toBeNull();
    expect($team->target_audience)->toBeNull();
    expect($team->tone_keywords)->toBeNull();
});

test('mount populates company setup from existing team data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'blog_url' => 'https://example.com/blog',
        'brand_description' => 'A great company',
        'target_audience' => 'Developers',
        'tone_keywords' => 'Friendly',
        'content_language' => 'Spanish',
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->assertSet('homepageUrl', 'https://example.com')
        ->assertSet('blogUrl', 'https://example.com/blog')
        ->assertSet('brandDescription', 'A great company')
        ->assertSet('targetAudience', 'Developers')
        ->assertSet('toneKeywords', 'Friendly')
        ->assertSet('contentLanguage', 'Spanish');
});

test('brand intelligence page can be rendered', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    $this->actingAs($user)
        ->get(route('brand.intelligence', ['current_team' => $team->slug]))
        ->assertOk();
});

test('guests cannot access brand intelligence', function () {
    $team = Team::factory()->create();

    $this->get(route('brand.intelligence', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});
