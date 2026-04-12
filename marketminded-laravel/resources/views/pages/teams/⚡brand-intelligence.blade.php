<?php

use App\Models\AudiencePersona;
use App\Models\BrandPositioning;
use App\Models\Team;
use App\Models\VoiceProfile;
use App\Support\TeamPermissions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public bool $missingPrerequisites = false;

    public array $missingItems = [];

    public bool $hasPositioning = false;

    public bool $hasPersonas = false;

    public bool $hasVoiceProfile = false;

    public bool $editingPositioning = false;

    public bool $editingVoiceProfile = false;

    public array $positioningForm = [
        'value_proposition' => '',
        'target_market' => '',
        'differentiators' => '',
        'core_problems' => '',
        'products_services' => '',
        'primary_cta' => '',
    ];

    public array $voiceForm = [
        'voice_analysis' => '',
        'content_types' => '',
        'should_avoid' => '',
        'should_use' => '',
        'style_inspiration' => '',
        'preferred_length' => 1500,
    ];

    public array $personaForm = [
        'label' => '',
        'description' => '',
        'pain_points' => '',
        'push' => '',
        'pull' => '',
        'anxiety' => '',
        'role' => '',
    ];

    public ?int $editingPersonaId = null;

    public array $personas = [];

    public ?array $positioning = null;

    public ?array $voiceProfile = null;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->checkPrerequisites();
        $this->loadData();
    }

    public function savePositioning(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'positioningForm.value_proposition' => ['nullable', 'string', 'max:10000'],
            'positioningForm.target_market' => ['nullable', 'string', 'max:10000'],
            'positioningForm.differentiators' => ['nullable', 'string', 'max:10000'],
            'positioningForm.core_problems' => ['nullable', 'string', 'max:10000'],
            'positioningForm.products_services' => ['nullable', 'string', 'max:10000'],
            'positioningForm.primary_cta' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->teamModel->brandPositioning()->updateOrCreate(
            ['team_id' => $this->teamModel->id],
            $validated['positioningForm'],
        );

        $this->editingPositioning = false;
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Positioning saved.'));
    }

    public function savePersona(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'personaForm.label' => ['required', 'string', 'max:255'],
            'personaForm.description' => ['nullable', 'string', 'max:10000'],
            'personaForm.pain_points' => ['nullable', 'string', 'max:10000'],
            'personaForm.push' => ['nullable', 'string', 'max:10000'],
            'personaForm.pull' => ['nullable', 'string', 'max:10000'],
            'personaForm.anxiety' => ['nullable', 'string', 'max:10000'],
            'personaForm.role' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingPersonaId) {
            $persona = $this->teamModel->audiencePersonas()->findOrFail($this->editingPersonaId);
            $persona->update($validated['personaForm']);
        } else {
            $sortOrder = $this->teamModel->audiencePersonas()->max('sort_order') ?? -1;
            $this->teamModel->audiencePersonas()->create(
                array_merge($validated['personaForm'], ['sort_order' => $sortOrder + 1]),
            );
        }

        $this->editingPersonaId = null;
        $this->resetPersonaForm();
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Persona saved.'));
    }

    public function editPersona(int $personaId): void
    {
        $persona = $this->teamModel->audiencePersonas()->findOrFail($personaId);

        $this->editingPersonaId = $persona->id;
        $this->personaForm = [
            'label' => $persona->label,
            'description' => $persona->description ?? '',
            'pain_points' => $persona->pain_points ?? '',
            'push' => $persona->push ?? '',
            'pull' => $persona->pull ?? '',
            'anxiety' => $persona->anxiety ?? '',
            'role' => $persona->role ?? '',
        ];
    }

    public function deletePersona(int $personaId): void
    {
        Gate::authorize('update', $this->teamModel);

        $this->teamModel->audiencePersonas()->findOrFail($personaId)->delete();
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Persona deleted.'));
    }

    public function saveVoiceProfile(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'voiceForm.voice_analysis' => ['nullable', 'string', 'max:10000'],
            'voiceForm.content_types' => ['nullable', 'string', 'max:10000'],
            'voiceForm.should_avoid' => ['nullable', 'string', 'max:10000'],
            'voiceForm.should_use' => ['nullable', 'string', 'max:10000'],
            'voiceForm.style_inspiration' => ['nullable', 'string', 'max:10000'],
            'voiceForm.preferred_length' => ['required', 'integer', 'min:100', 'max:10000'],
        ]);

        $this->teamModel->voiceProfile()->updateOrCreate(
            ['team_id' => $this->teamModel->id],
            $validated['voiceForm'],
        );

        $this->editingVoiceProfile = false;
        $this->loadData();

        Flux::toast(variant: 'success', text: __('Voice profile saved.'));
    }

    public function startEditingPositioning(): void
    {
        $this->editingPositioning = true;
    }

    public function cancelEditingPositioning(): void
    {
        $this->editingPositioning = false;
        $this->loadData();
    }

    public function startEditingVoiceProfile(): void
    {
        $this->editingVoiceProfile = true;
    }

    public function cancelEditingVoiceProfile(): void
    {
        $this->editingVoiceProfile = false;
        $this->loadData();
    }

    public function resetPersonaForm(): void
    {
        $this->editingPersonaId = null;
        $this->personaForm = [
            'label' => '',
            'description' => '',
            'pain_points' => '',
            'push' => '',
            'pull' => '',
            'anxiety' => '',
            'role' => '',
        ];
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        return $this->view()->title(__('Brand Intelligence'));
    }

    private function checkPrerequisites(): void
    {
        $this->missingItems = [];

        if (! $this->teamModel->homepage_url) {
            $this->missingItems[] = ['label' => 'Homepage URL', 'route' => 'brand.setup'];
        }

        if (! $this->teamModel->openrouter_api_key) {
            $this->missingItems[] = ['label' => 'OpenRouter API key', 'route' => 'teams.edit'];
        }

        $this->missingPrerequisites = count($this->missingItems) > 0;
    }

    private function loadData(): void
    {
        $positioning = $this->teamModel->brandPositioning;
        $this->hasPositioning = $positioning !== null;

        if ($positioning) {
            $this->positioning = $positioning->only([
                'value_proposition', 'target_market', 'differentiators',
                'core_problems', 'products_services', 'primary_cta',
            ]);
            $this->positioningForm = [
                'value_proposition' => $positioning->value_proposition ?? '',
                'target_market' => $positioning->target_market ?? '',
                'differentiators' => $positioning->differentiators ?? '',
                'core_problems' => $positioning->core_problems ?? '',
                'products_services' => $positioning->products_services ?? '',
                'primary_cta' => $positioning->primary_cta ?? '',
            ];
        }

        $personas = $this->teamModel->audiencePersonas()->get();
        $this->hasPersonas = $personas->isNotEmpty();
        $this->personas = $personas->map(fn ($p) => [
            'id' => $p->id,
            'label' => $p->label,
            'description' => $p->description,
            'pain_points' => $p->pain_points,
            'push' => $p->push,
            'pull' => $p->pull,
            'anxiety' => $p->anxiety,
            'role' => $p->role,
            'sort_order' => $p->sort_order,
        ])->toArray();

        $voice = $this->teamModel->voiceProfile;
        $this->hasVoiceProfile = $voice !== null;

        if ($voice) {
            $this->voiceProfile = $voice->only([
                'voice_analysis', 'content_types', 'should_avoid',
                'should_use', 'style_inspiration', 'preferred_length',
            ]);
            $this->voiceForm = [
                'voice_analysis' => $voice->voice_analysis ?? '',
                'content_types' => $voice->content_types ?? '',
                'should_avoid' => $voice->should_avoid ?? '',
                'should_use' => $voice->should_use ?? '',
                'style_inspiration' => $voice->style_inspiration ?? '',
                'preferred_length' => $voice->preferred_length ?? 1500,
            ];
        }
    }
}; ?>

<section class="w-full">
    <flux:main container class="max-w-xl lg:max-w-3xl">
        <flux:heading size="xl">{{ __('Brand Intelligence') }}</flux:heading>
        <flux:subheading>{{ __('AI-generated insights about your brand, audience, and voice.') }}</flux:subheading>

        @if ($missingPrerequisites)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
                <flux:callout.heading>{{ __('Setup required') }}</flux:callout.heading>
                <flux:callout.text>
                    @foreach ($missingItems as $item)
                        {{ __('Add your') }} <a href="{{ route($item['route'], $item['route'] === 'teams.edit' ? ['team' => $teamModel] : []) }}" class="underline" wire:navigate>{{ $item['label'] }}</a>{{ $loop->last ? '.' : ', ' }}
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @endif
    </flux:main>
</section>
