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

    public function mount(Team $current_team): void
    {
        $this->teamModel = $current_team;
        $this->homepageUrl = $current_team->homepage_url ?? '';
        $this->blogUrl = $current_team->blog_url ?? '';
        $this->brandDescription = $current_team->brand_description ?? '';
        $this->productUrls = $current_team->product_urls ?? [];
        $this->competitorUrls = $current_team->competitor_urls ?? [];
        $this->styleReferenceUrls = $current_team->style_reference_urls ?? [];
        $this->targetAudience = $current_team->target_audience ?? '';
        $this->toneKeywords = $current_team->tone_keywords ?? '';
        $this->contentLanguage = $current_team->content_language ?? 'English';
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
    <flux:main container class="max-w-xl lg:max-w-3xl">
        <flux:heading size="xl">{{ __('Brand Setup') }}</flux:heading>
        <flux:subheading>{{ __('Tell us about your brand so our AI agents can build your content strategy.') }}</flux:subheading>

        <form wire:submit="saveBrandSetup">
            {{-- Section 1: Company --}}
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Company') }}</flux:heading>
                    <flux:subheading>{{ __('Your company\'s online presence. The homepage URL is the only required field.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-6">
                    <flux:input
                        wire:model="homepageUrl"
                        label="Homepage URL"
                        description:trailing="Your main website. We will crawl this to understand your brand."
                        type="url"
                        placeholder="https://yourcompany.com"
                        required
                    />

                    <flux:input
                        wire:model="blogUrl"
                        label="Blog URL"
                        description:trailing="Your blog index page. Helps us understand your existing content and avoid repetition."
                        type="url"
                        placeholder="https://yourcompany.com/blog"
                    />

                    <flux:textarea
                        wire:model="brandDescription"
                        label="Brand Description"
                        description:trailing="A brief description of what your company does, who it serves, and what makes it different. 2-3 sentences is plenty."
                        placeholder="We make project management simple for remote teams..."
                        rows="3"
                    />
                </div>
            </div>

            {{-- Section 2: Product & Brand Pages --}}
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Product & Brand Pages') }}</flux:heading>
                    <flux:subheading>{{ __('Links to your product pages, about page, case studies, or documentation.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-3">
                    @foreach ($productUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="productUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://yourcompany.com/product"
                                />
                            </div>
                            <flux:modal.trigger :name="'remove-product-url-'.$index">
                                <flux:button variant="ghost" size="sm" icon="x-mark" />
                            </flux:modal.trigger>
                        </div>

                        <flux:modal :name="'remove-product-url-'.$index" class="min-w-[22rem]">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Remove URL?') }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('This URL will be removed from the list.') }}</flux:text>
                                </div>
                                <div class="flex gap-2">
                                    <flux:spacer />
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" wire:click="removeProductUrl({{ $index }})">
                                        {{ __('Remove') }}
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addProductUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 3: Competitors --}}
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Competitors') }}</flux:heading>
                    <flux:subheading>{{ __('Competitor websites. Helps the AI differentiate your positioning and find unique angles.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-3">
                    @foreach ($competitorUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="competitorUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://competitor.com"
                                />
                            </div>
                            <flux:modal.trigger :name="'remove-competitor-url-'.$index">
                                <flux:button variant="ghost" size="sm" icon="x-mark" />
                            </flux:modal.trigger>
                        </div>

                        <flux:modal :name="'remove-competitor-url-'.$index" class="min-w-[22rem]">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Remove URL?') }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('This URL will be removed from the list.') }}</flux:text>
                                </div>
                                <div class="flex gap-2">
                                    <flux:spacer />
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" wire:click="removeCompetitorUrl({{ $index }})">
                                        {{ __('Remove') }}
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addCompetitorUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 4: Style References --}}
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Style References') }}</flux:heading>
                    <flux:subheading>{{ __('Articles or blogs whose writing style you admire — including your own posts if you already have an established style.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-3">
                    @foreach ($styleReferenceUrls as $index => $url)
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <flux:input
                                    wire:model="styleReferenceUrls.{{ $index }}"
                                    type="url"
                                    placeholder="https://example.com/great-article"
                                />
                            </div>
                            <flux:modal.trigger :name="'remove-style-url-'.$index">
                                <flux:button variant="ghost" size="sm" icon="x-mark" />
                            </flux:modal.trigger>
                        </div>

                        <flux:modal :name="'remove-style-url-'.$index" class="min-w-[22rem]">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Remove URL?') }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('This URL will be removed from the list.') }}</flux:text>
                                </div>
                                <div class="flex gap-2">
                                    <flux:spacer />
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" wire:click="removeStyleReferenceUrl({{ $index }})">
                                        {{ __('Remove') }}
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach

                    <flux:button variant="subtle" size="sm" icon="plus" wire:click="addStyleReferenceUrl">
                        {{ __('Add URL') }}
                    </flux:button>
                </div>
            </div>

            {{-- Section 5: Additional Context --}}
            <flux:separator variant="subtle" class="my-8" />

            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6 pb-10">
                <div class="w-80">
                    <flux:heading size="lg">{{ __('Additional Context') }}</flux:heading>
                    <flux:subheading>{{ __('Optional hints that help the AI understand your brand better.') }}</flux:subheading>
                </div>

                <div class="flex-1 space-y-6">
                    <flux:textarea
                        wire:model="targetAudience"
                        label="Target Audience"
                        description:trailing="Who are you writing for? e.g. CTOs at mid-size SaaS companies, or first-time homebuyers in their 30s."
                        placeholder="CTOs and engineering leads at B2B SaaS companies..."
                        rows="2"
                    />

                    <flux:input
                        wire:model="toneKeywords"
                        label="Tone Keywords"
                        description:trailing="Words that describe how your brand should sound. e.g. professional but approachable, technical but not jargon-heavy."
                        placeholder="Professional, approachable, concise"
                    />

                    <flux:input
                        wire:model="contentLanguage"
                        label="Content Language"
                        description:trailing="The language your content should be written in."
                        placeholder="English"
                    />

                    @if ($this->permissions->canUpdateTeam)
                        <div class="flex justify-end">
                            <flux:button variant="primary" type="submit">
                                {{ __('Save brand setup') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            </div>
        </form>
    </flux:main>
</section>
