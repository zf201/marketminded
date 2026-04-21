<?php

use App\Enums\TeamRole;
use App\Models\AudiencePersona;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\User;
use App\Models\VoiceProfile;
use Livewire\Livewire;

// --- Page rendering ---

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

// --- Company setup (merged from Brand Setup) ---

test('shows company info section with empty fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['homepage_url' => null]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->assertSee('Company')
        ->assertSee('Homepage URL');
});

test('shows company info with existing data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['homepage_url' => 'https://example.com', 'brand_description' => 'Test brand']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->assertSet('homepageUrl', 'https://example.com')
        ->assertSet('brandDescription', 'Test brand');
});

// --- Positioning CRUD ---

test('owner can save positioning', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('positioningForm.value_proposition', 'We make widgets')
        ->set('positioningForm.target_market', 'Developers')
        ->set('positioningForm.differentiators', 'Best in class')
        ->set('positioningForm.core_problems', 'Complexity')
        ->set('positioningForm.products_services', 'Widget Pro')
        ->set('positioningForm.primary_cta', 'Try free')
        ->call('savePositioning')
        ->assertHasNoErrors();

    $positioning = $team->fresh()->brandPositioning;
    expect($positioning)->not->toBeNull();
    expect($positioning->value_proposition)->toBe('We make widgets');
    expect($positioning->target_market)->toBe('Developers');
});

test('owner can update existing positioning', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    BrandPositioning::create(['team_id' => $team->id, 'value_proposition' => 'Old value']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('positioningForm.value_proposition', 'New value')
        ->call('savePositioning')
        ->assertHasNoErrors();

    expect($team->fresh()->brandPositioning->value_proposition)->toBe('New value');
});

test('member cannot save positioning', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('positioningForm.value_proposition', 'Hacked')
        ->call('savePositioning')
        ->assertForbidden();
});

// --- Persona CRUD ---

test('owner can create persona', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('personaForm.label', 'The Developer')
        ->set('personaForm.description', 'Writes code all day')
        ->set('personaForm.pain_points', 'Too many meetings')
        ->set('personaForm.push', 'Burnout')
        ->set('personaForm.pull', 'Better tools')
        ->set('personaForm.anxiety', 'Learning curve')
        ->set('personaForm.role', 'Senior Engineer')
        ->call('savePersona')
        ->assertHasNoErrors();

    $persona = $team->audiencePersonas()->first();
    expect($persona)->not->toBeNull();
    expect($persona->label)->toBe('The Developer');
    expect($persona->role)->toBe('Senior Engineer');
});

test('owner can update persona', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $persona = AudiencePersona::create(['team_id' => $team->id, 'label' => 'Old Name']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->call('editPersona', $persona->id)
        ->set('personaForm.label', 'New Name')
        ->call('savePersona')
        ->assertHasNoErrors();

    expect($persona->fresh()->label)->toBe('New Name');
});

test('owner can delete persona', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $persona = AudiencePersona::create(['team_id' => $team->id, 'label' => 'Doomed']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->call('deletePersona', $persona->id)
        ->assertHasNoErrors();

    expect(AudiencePersona::find($persona->id))->toBeNull();
});

test('member cannot create persona', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('personaForm.label', 'Hacked')
        ->call('savePersona')
        ->assertForbidden();
});

test('persona label is required', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('personaForm.label', '')
        ->call('savePersona')
        ->assertHasErrors(['personaForm.label']);
});

// --- Voice Profile CRUD ---

test('owner can save voice profile', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('voiceForm.voice_analysis', 'Professional but friendly')
        ->set('voiceForm.content_types', 'Educational, how-to')
        ->set('voiceForm.should_avoid', 'Jargon')
        ->set('voiceForm.should_use', 'Active voice')
        ->set('voiceForm.style_inspiration', 'Concise and direct')
        ->set('voiceForm.preferred_length', 2000)
        ->call('saveVoiceProfile')
        ->assertHasNoErrors();

    $voice = $team->fresh()->voiceProfile;
    expect($voice)->not->toBeNull();
    expect($voice->voice_analysis)->toBe('Professional but friendly');
    expect($voice->preferred_length)->toBe(2000);
});

test('member cannot save voice profile', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('voiceForm.voice_analysis', 'Hacked')
        ->call('saveVoiceProfile')
        ->assertForbidden();
});

test('preferred length must be between 100 and 10000', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->set('voiceForm.preferred_length', 50)
        ->call('saveVoiceProfile')
        ->assertHasErrors(['voiceForm.preferred_length']);
});

// --- Mount populates from existing data ---

test('mount populates positioning from existing data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    BrandPositioning::create(['team_id' => $team->id, 'value_proposition' => 'We rock']);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->assertSet('positioningForm.value_proposition', 'We rock');
});

test('mount populates voice profile from existing data', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    VoiceProfile::create(['team_id' => $team->id, 'voice_analysis' => 'Friendly tone', 'preferred_length' => 2000]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->assertSet('voiceForm.voice_analysis', 'Friendly tone')
        ->assertSet('voiceForm.preferred_length', 2000);
});

test('mount loads existing personas', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    AudiencePersona::create(['team_id' => $team->id, 'label' => 'Persona A', 'sort_order' => 0]);
    AudiencePersona::create(['team_id' => $team->id, 'label' => 'Persona B', 'sort_order' => 1]);

    $this->actingAs($user);

    Livewire::test('pages::teams.brand-intelligence', ['current_team' => $team])
        ->assertSet('hasPersonas', true);
});
