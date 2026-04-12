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

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
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

    public function startGeneration(): void
    {
        Gate::authorize('update', $this->teamModel);

        $this->teamModel->update(['intelligence_status' => 'pending', 'intelligence_error' => null]);

        \App\Jobs\GenerateBrandIntelligenceJob::dispatch($this->teamModel);
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        $this->checkPrerequisites();

        return $this->view()->title(__('Brand Intelligence'));
    }

    private function checkPrerequisites(): void
    {
        $this->missingItems = [];

        $team = $this->teamModel->fresh();

        if (! $team->homepage_url) {
            $this->missingItems[] = ['label' => 'Homepage URL required', 'action' => 'Add your homepage URL in Brand Setup', 'route' => 'brand.setup'];
        }

        if (! $team->openrouter_api_key) {
            $this->missingItems[] = ['label' => 'OpenRouter API key required', 'action' => 'Add your API key in Team Settings', 'route' => 'teams.edit'];
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
        <flux:subheading>{{ __('AI-generated insights about your brand, audience, and voice. Review and edit as needed.') }}</flux:subheading>

        {{-- Prerequisite warnings --}}
        @if ($missingPrerequisites)
            <div class="mt-6 space-y-3">
                @foreach ($missingItems as $item)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ $item['label'] }}</flux:callout.heading>
                        <flux:callout.text>
                            <a href="{{ route($item['route'], $item['route'] === 'teams.edit' ? ['team' => $teamModel] : []) }}" class="underline" wire:navigate>{{ $item['action'] }}</a>
                        </flux:callout.text>
                    </flux:callout>
                @endforeach
            </div>
        @endif

        {{-- Bootstrap CTA (when prerequisites met but no data) --}}
        @if (! $missingPrerequisites && ! $hasPositioning && ! $hasPersonas && ! $hasVoiceProfile)
            <flux:card class="mt-8 text-center">
                <div class="space-y-4 py-4">
                    <flux:text>{{ __('Ready to analyze your brand. This will crawl your URLs and generate positioning, audience personas, and voice profile.') }}</flux:text>
                    <flux:button variant="primary" disabled>
                        {{ __('Generate Brand Intelligence') }}
                    </flux:button>
                    <flux:text class="text-xs">{{ __('Coming soon — AI generation will be available in a future update.') }}</flux:text>
                </div>
            </flux:card>
        @endif

        {{-- Section 1: Positioning --}}
        @if ($hasPositioning || $editingPositioning)
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Positioning') }}</flux:heading>
                    <flux:subheading>{{ __('Your brand\'s market position and value proposition.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-6">
                    @if ($editingPositioning)
                        <flux:textarea wire:model="positioningForm.value_proposition" label="Value Proposition" rows="2" />
                        <flux:textarea wire:model="positioningForm.target_market" label="Target Market" rows="2" />
                        <flux:textarea wire:model="positioningForm.differentiators" label="Key Differentiators" rows="2" />
                        <flux:textarea wire:model="positioningForm.core_problems" label="Core Problems Solved" rows="2" />
                        <flux:textarea wire:model="positioningForm.products_services" label="Products & Services" rows="2" />
                        <flux:textarea wire:model="positioningForm.primary_cta" label="Primary CTA" rows="1" />

                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="cancelEditingPositioning">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" wire:click="savePositioning">{{ __('Save') }}</flux:button>
                        </div>
                    @else
                        @foreach ([
                            'value_proposition' => 'Value Proposition',
                            'target_market' => 'Target Market',
                            'differentiators' => 'Key Differentiators',
                            'core_problems' => 'Core Problems Solved',
                            'products_services' => 'Products & Services',
                            'primary_cta' => 'Primary CTA',
                        ] as $field => $label)
                            @if (! empty($positioning[$field]))
                                <div>
                                    <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                                    <flux:text class="mt-1">{{ $positioning[$field] }}</flux:text>
                                </div>
                            @endif
                        @endforeach

                        @if ($this->permissions->canUpdateTeam)
                            <div class="flex justify-end gap-2">
                                <flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate') }}</flux:button>
                                <flux:button variant="subtle" size="sm" wire:click="startEditingPositioning">{{ __('Edit') }}</flux:button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        {{-- Section 2: Audience Personas --}}
        @if ($hasPersonas || $hasPositioning)
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Audience Personas') }}</flux:heading>
                    <flux:subheading>{{ __('Your target audience segments. Each content piece targets one persona.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-4">
                    @foreach ($personas as $persona)
                        <flux:card class="space-y-3">
                            <div class="flex items-start justify-between">
                                <div>
                                    <flux:heading>{{ $persona['label'] }}</flux:heading>
                                    @if ($persona['role'])
                                        <flux:text class="text-sm text-zinc-400">{{ $persona['role'] }}</flux:text>
                                    @endif
                                </div>
                                @if ($this->permissions->canUpdateTeam)
                                    <div class="flex gap-1">
                                        <flux:modal.trigger name="edit-persona-modal">
                                            <flux:button variant="ghost" size="xs" icon="pencil" wire:click="editPersona({{ $persona['id'] }})" />
                                        </flux:modal.trigger>
                                        <flux:modal.trigger :name="'delete-persona-'.$persona['id']">
                                            <flux:button variant="ghost" size="xs" icon="x-mark" />
                                        </flux:modal.trigger>
                                    </div>
                                @endif
                            </div>

                            @if ($persona['description'])
                                <flux:text class="text-sm">{{ $persona['description'] }}</flux:text>
                            @endif

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ([
                                    'pain_points' => 'Pain Points',
                                    'push' => 'Push',
                                    'pull' => 'Pull',
                                    'anxiety' => 'Anxiety',
                                ] as $field => $label)
                                    @if (! empty($persona[$field]))
                                        <div>
                                            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                                            <flux:text class="mt-1 text-sm">{{ $persona[$field] }}</flux:text>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </flux:card>

                        {{-- Delete persona confirmation modal --}}
                        @if ($this->permissions->canUpdateTeam)
                            <flux:modal :name="'delete-persona-'.$persona['id']" class="min-w-[22rem]">
                                <div class="space-y-6">
                                    <div>
                                        <flux:heading size="lg">{{ __('Delete persona?') }}</flux:heading>
                                        <flux:text class="mt-2">{{ __('"' . $persona['label'] . '" will be permanently deleted.') }}</flux:text>
                                    </div>
                                    <div class="flex gap-2">
                                        <flux:spacer />
                                        <flux:modal.close>
                                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                        </flux:modal.close>
                                        <flux:button variant="danger" wire:click="deletePersona({{ $persona['id'] }})">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </flux:modal>
                        @endif
                    @endforeach

                    @if ($this->permissions->canUpdateTeam)
                        <div class="flex justify-end gap-2">
                            <flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate all') }}</flux:button>
                            <flux:modal.trigger name="edit-persona-modal">
                                <flux:button variant="subtle" size="sm" icon="plus" wire:click="resetPersonaForm">{{ __('Add persona') }}</flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Section 3: Voice & Tone --}}
        @if ($hasVoiceProfile || $editingVoiceProfile)
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6 pb-10">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Voice & Tone') }}</flux:heading>
                    <flux:subheading>{{ __('How your brand sounds in writing. Guides the AI content generation.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-6">
                    @if ($editingVoiceProfile)
                        <flux:textarea wire:model="voiceForm.voice_analysis" label="Voice Analysis" rows="3" />
                        <flux:textarea wire:model="voiceForm.content_types" label="Content Types" rows="2" />
                        <flux:textarea wire:model="voiceForm.should_avoid" label="Should Avoid" rows="2" />
                        <flux:textarea wire:model="voiceForm.should_use" label="Should Use" rows="2" />
                        <flux:textarea wire:model="voiceForm.style_inspiration" label="Style Inspiration" rows="2" />
                        <flux:input wire:model="voiceForm.preferred_length" label="Preferred Length (words)" type="number" min="100" max="10000" />

                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="cancelEditingVoiceProfile">{{ __('Cancel') }}</flux:button>
                            <flux:button variant="primary" wire:click="saveVoiceProfile">{{ __('Save') }}</flux:button>
                        </div>
                    @else
                        @foreach ([
                            'voice_analysis' => 'Voice Analysis',
                            'content_types' => 'Content Types',
                            'should_avoid' => 'Should Avoid',
                            'should_use' => 'Should Use',
                            'style_inspiration' => 'Style Inspiration',
                        ] as $field => $label)
                            @if (! empty($voiceProfile[$field]))
                                <div>
                                    <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $label }}</flux:text>
                                    <flux:text class="mt-1">{{ $voiceProfile[$field] }}</flux:text>
                                </div>
                            @endif
                        @endforeach

                        @if (! empty($voiceProfile['preferred_length']))
                            <div>
                                <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ __('Target Length') }}</flux:text>
                                <flux:text class="mt-1">{{ number_format($voiceProfile['preferred_length']) }} {{ __('words') }}</flux:text>
                            </div>
                        @endif

                        @if ($this->permissions->canUpdateTeam)
                            <div class="flex justify-end gap-2">
                                <flux:button variant="subtle" size="sm" disabled>{{ __('Regenerate') }}</flux:button>
                                <flux:button variant="subtle" size="sm" wire:click="startEditingVoiceProfile">{{ __('Edit') }}</flux:button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        {{-- Persona edit/add modal --}}
        @if ($this->permissions->canUpdateTeam)
            <flux:modal name="edit-persona-modal" class="w-full max-w-lg">
                <div class="space-y-6">
                    <flux:heading size="lg">{{ $editingPersonaId ? __('Edit Persona') : __('Add Persona') }}</flux:heading>

                    <flux:input wire:model="personaForm.label" label="Label" placeholder="The Overwhelmed Engineering Lead" required />
                    <flux:input wire:model="personaForm.role" label="Role" placeholder="Senior Engineer" />
                    <flux:textarea wire:model="personaForm.description" label="Description" rows="2" placeholder="Who they are..." />
                    <flux:textarea wire:model="personaForm.pain_points" label="Pain Points" rows="2" placeholder="What problems they face..." />
                    <flux:textarea wire:model="personaForm.push" label="Push" rows="2" placeholder="What drives them to change..." />
                    <flux:textarea wire:model="personaForm.pull" label="Pull" rows="2" placeholder="What attracts them to a solution..." />
                    <flux:textarea wire:model="personaForm.anxiety" label="Anxiety" rows="2" placeholder="What holds them back..." />

                    <div class="flex gap-2">
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" wire:click="savePersona">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </flux:main>
</section>
