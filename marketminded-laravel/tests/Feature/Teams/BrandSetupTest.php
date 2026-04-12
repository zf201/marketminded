<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('owner can save brand setup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->set('blogUrl', 'https://example.com/blog')
        ->set('brandDescription', 'We make widgets for developers.')
        ->set('productUrls', ['https://example.com/product', 'https://example.com/about'])
        ->set('competitorUrls', ['https://competitor.com'])
        ->set('styleReferenceUrls', ['https://blog.example.com/great-post'])
        ->set('targetAudience', 'Senior developers at SaaS companies')
        ->set('toneKeywords', 'Professional, approachable')
        ->set('contentLanguage', 'English')
        ->call('saveBrandSetup')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->blog_url)->toBe('https://example.com/blog');
    expect($team->brand_description)->toBe('We make widgets for developers.');
    expect($team->product_urls)->toBe(['https://example.com/product', 'https://example.com/about']);
    expect($team->competitor_urls)->toBe(['https://competitor.com']);
    expect($team->style_reference_urls)->toBe(['https://blog.example.com/great-post']);
    expect($team->target_audience)->toBe('Senior developers at SaaS companies');
    expect($team->tone_keywords)->toBe('Professional, approachable');
    expect($team->content_language)->toBe('English');
});

test('admin can save brand setup', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->call('saveBrandSetup')
        ->assertHasNoErrors();

    expect($team->fresh()->homepage_url)->toBe('https://example.com');
});

test('member cannot save brand setup', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->call('saveBrandSetup')
        ->assertForbidden();
});

test('homepage url is required', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', '')
        ->call('saveBrandSetup')
        ->assertHasErrors(['homepageUrl']);
});

test('urls must be valid', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'not-a-url')
        ->set('blogUrl', 'also-not-a-url')
        ->set('productUrls', ['bad-url'])
        ->call('saveBrandSetup')
        ->assertHasErrors(['homepageUrl', 'blogUrl', 'productUrls.0']);
});

test('brand setup has correct defaults', function () {
    $team = Team::factory()->create();

    expect($team->homepage_url)->toBeNull();
    expect($team->blog_url)->toBeNull();
    expect($team->brand_description)->toBeNull();
    expect($team->product_urls)->toBe([]);
    expect($team->competitor_urls)->toBe([]);
    expect($team->style_reference_urls)->toBe([]);
    expect($team->target_audience)->toBeNull();
    expect($team->tone_keywords)->toBeNull();
    expect($team->content_language)->toBe('English');
});

test('optional fields can be cleared', function () {
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

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('blogUrl', '')
        ->set('brandDescription', '')
        ->set('targetAudience', '')
        ->set('toneKeywords', '')
        ->call('saveBrandSetup')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->homepage_url)->toBe('https://example.com');
    expect($team->blog_url)->toBeNull();
    expect($team->brand_description)->toBeNull();
    expect($team->target_audience)->toBeNull();
    expect($team->tone_keywords)->toBeNull();
});

test('url arrays are capped at 20 items', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    $urls = array_fill(0, 21, 'https://example.com');

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->set('homepageUrl', 'https://example.com')
        ->set('productUrls', $urls)
        ->call('saveBrandSetup')
        ->assertHasErrors(['productUrls']);
});

test('mount populates form from existing team data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create([
        'homepage_url' => 'https://example.com',
        'blog_url' => 'https://example.com/blog',
        'brand_description' => 'A great company',
        'product_urls' => ['https://example.com/product'],
        'competitor_urls' => ['https://competitor.com'],
        'style_reference_urls' => ['https://style.example.com'],
        'target_audience' => 'Developers',
        'tone_keywords' => 'Friendly',
        'content_language' => 'Spanish',
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-setup', ['team' => $team])
        ->assertSet('homepageUrl', 'https://example.com')
        ->assertSet('blogUrl', 'https://example.com/blog')
        ->assertSet('brandDescription', 'A great company')
        ->assertSet('productUrls', ['https://example.com/product'])
        ->assertSet('competitorUrls', ['https://competitor.com'])
        ->assertSet('styleReferenceUrls', ['https://style.example.com'])
        ->assertSet('targetAudience', 'Developers')
        ->assertSet('toneKeywords', 'Friendly')
        ->assertSet('contentLanguage', 'Spanish');
});
