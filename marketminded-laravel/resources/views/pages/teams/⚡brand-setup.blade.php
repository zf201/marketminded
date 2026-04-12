<?php

use App\Models\Team;
use App\Support\TeamPermissions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $homepageUrl = '';

    public string $blogUrl = '';

    public string $brandDescription = '';

    public array $productUrls = [];

    public array $competitorUrls = [];

    public array $styleReferenceUrls = [];

    public string $targetAudience = '';

    public string $toneKeywords = '';

    public string $contentLanguage = 'English';

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->homepageUrl = $team->homepage_url ?? '';
        $this->blogUrl = $team->blog_url ?? '';
        $this->brandDescription = $team->brand_description ?? '';
        $this->productUrls = $team->product_urls ?? [];
        $this->competitorUrls = $team->competitor_urls ?? [];
        $this->styleReferenceUrls = $team->style_reference_urls ?? [];
        $this->targetAudience = $team->target_audience ?? '';
        $this->toneKeywords = $team->tone_keywords ?? '';
        $this->contentLanguage = $team->content_language ?? 'English';
    }

    public function saveBrandSetup(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'homepageUrl' => ['required', 'url', 'max:255'],
            'blogUrl' => ['nullable', 'url', 'max:255'],
            'brandDescription' => ['nullable', 'string', 'max:5000'],
            'productUrls' => ['nullable', 'array', 'max:20'],
            'productUrls.*' => ['required', 'url', 'max:255', 'distinct'],
            'competitorUrls' => ['nullable', 'array', 'max:20'],
            'competitorUrls.*' => ['required', 'url', 'max:255', 'distinct'],
            'styleReferenceUrls' => ['nullable', 'array', 'max:20'],
            'styleReferenceUrls.*' => ['required', 'url', 'max:255', 'distinct'],
            'targetAudience' => ['nullable', 'string', 'max:5000'],
            'toneKeywords' => ['nullable', 'string', 'max:255'],
            'contentLanguage' => ['nullable', 'string', 'max:50'],
        ]);

        $this->teamModel->update([
            'homepage_url' => $validated['homepageUrl'],
            'blog_url' => $validated['blogUrl'] ?: null,
            'brand_description' => $validated['brandDescription'] ?: null,
            'product_urls' => $validated['productUrls'] ?? [],
            'competitor_urls' => $validated['competitorUrls'] ?? [],
            'style_reference_urls' => $validated['styleReferenceUrls'] ?? [],
            'target_audience' => $validated['targetAudience'] ?: null,
            'tone_keywords' => $validated['toneKeywords'] ?: null,
            'content_language' => $validated['contentLanguage'] ?: 'English',
        ]);

        Flux::toast(variant: 'success', text: __('Brand setup saved.'));
    }

    public function addProductUrl(): void
    {
        $this->productUrls[] = '';
    }

    public function removeProductUrl(int $index): void
    {
        unset($this->productUrls[$index]);
        $this->productUrls = array_values($this->productUrls);
    }

    public function addCompetitorUrl(): void
    {
        $this->competitorUrls[] = '';
    }

    public function removeCompetitorUrl(int $index): void
    {
        unset($this->competitorUrls[$index]);
        $this->competitorUrls = array_values($this->competitorUrls);
    }

    public function addStyleReferenceUrl(): void
    {
        $this->styleReferenceUrls[] = '';
    }

    public function removeStyleReferenceUrl(int $index): void
    {
        unset($this->styleReferenceUrls[$index]);
        $this->styleReferenceUrls = array_values($this->styleReferenceUrls);
    }

    public function getPermissionsProperty(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    public function render()
    {
        return $this->view()->title(__('Brand Setup'));
    }
}; ?>

<section class="w-full">
    <div class="mx-auto w-full max-w-2xl space-y-10 py-6">
        <div>
            <flux:heading size="xl">{{ __('Brand Setup') }}</flux:heading>
            <flux:subheading>{{ __('Tell us about your brand so our AI agents can build your content strategy.') }}</flux:subheading>
        </div>

        <form wire:submit="saveBrandSetup" class="space-y-10">
            {{-- Section 1: Company --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Company') }}</flux:heading>
                    <flux:subheading>{{ __('Your company\'s online presence. The homepage URL is the only required field — everything else helps the AI do a better job.') }}</flux:subheading>
                </div>

                <flux:input
                    wire:model="homepageUrl"
                    :label="__('Homepage URL')"
                    :description="__('Your main website. We\'ll crawl this to understand your brand.')"
                    type="url"
                    placeholder="https://yourcompany.com"
                    required
                />

                <flux:input
                    wire:model="blogUrl"
                    :label="__('Blog URL')"
                    :description="__('Your blog\'s index page. Helps us understand your existing content and avoid repetition.')"
                    type="url"
                    placeholder="https://yourcompany.com/blog"
                />

                <flux:textarea
                    wire:model="brandDescription"
                    :label="__('Brand Description')"
                    :description="__('A brief description of what your company does, who it serves, and what makes it different. 2-3 sentences is plenty.')"
                    placeholder="{{ __('We make project management simple for remote teams...') }}"
                    rows="3"
                />
            </div>

            {{-- Section 2: Product & Brand Pages --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Product & Brand Pages') }}</flux:heading>
                    <flux:subheading>{{ __('Links to your product pages, about page, case studies, or documentation. These help the AI understand your offerings in depth.') }}</flux:subheading>
                </div>

                <div class="space-y-3">
                    @foreach ($productUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="productUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://yourcompany.com/product"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeProductUrl({{ $index }})"
                            />
                        </div>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addProductUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 3: Competitors --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Competitors') }}</flux:heading>
                    <flux:subheading>{{ __('Competitor websites. Helps the AI differentiate your positioning and find unique angles for your content.') }}</flux:subheading>
                </div>

                <div class="space-y-3">
                    @foreach ($competitorUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="competitorUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://competitor.com"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeCompetitorUrl({{ $index }})"
                            />
                        </div>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addCompetitorUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 4: Style References --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Style References') }}</flux:heading>
                    <flux:subheading>{{ __('Articles or blogs whose writing style you admire — including your own posts if you already have an established style. These guide the AI\'s tone and writing approach and don\'t need to be in your industry.') }}</flux:subheading>
                </div>

                <div class="space-y-3">
                    @foreach ($styleReferenceUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="styleReferenceUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://example.com/great-article"
                                />
                            </div>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                wire:click="removeStyleReferenceUrl({{ $index }})"
                            />
                        </div>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addStyleReferenceUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 5: Additional Context --}}
            <div class="space-y-6">
                <div>
                    <flux:heading>{{ __('Additional Context') }}</flux:heading>
                    <flux:subheading>{{ __('Optional hints that help the AI understand your brand better.') }}</flux:subheading>
                </div>

                <flux:textarea
                    wire:model="targetAudience"
                    :label="__('Target Audience')"
                    :description="__('Who are you writing for? e.g., \"CTOs at mid-size SaaS companies\" or \"first-time homebuyers in their 30s\"')"
                    placeholder="{{ __('CTOs and engineering leads at B2B SaaS companies...') }}"
                    rows="2"
                />

                <flux:input
                    wire:model="toneKeywords"
                    :label="__('Tone Keywords')"
                    :description="__('Words that describe how your brand should sound. e.g., \"professional but approachable\", \"technical but not jargon-heavy\"')"
                    placeholder="{{ __('Professional, approachable, concise') }}"
                />

                <flux:input
                    wire:model="contentLanguage"
                    :label="__('Content Language')"
                    :description="__('The language your content should be written in.')"
                    placeholder="English"
                />
            </div>

            {{-- Save --}}
            @if ($this->permissions->canUpdateTeam)
                <flux:button variant="primary" type="submit">
                    {{ __('Save brand setup') }}
                </flux:button>
            @endif
        </form>
    </div>
</section>
