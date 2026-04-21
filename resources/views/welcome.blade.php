<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Write blogs your brand would actually publish')])
        <link href="https://fonts.bunny.net/css?family=pirata-one:400" rel="stylesheet" />
        <style>
            @keyframes landing-scroll {
                from { transform: translateY(0); }
                to { transform: translateY(-50%); }
            }
            @media (prefers-reduced-motion: reduce) {
                .landing-scroll { animation: none !important; }
            }
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="bg-background flex min-h-svh flex-col">
            <header class="w-full border-b border-zinc-200/70 dark:border-zinc-800/70">
                <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-4">
                    <x-app-logo />
                    <div class="flex items-center gap-2">
                        <flux:button :href="route('login')" variant="ghost" size="sm">
                            {{ __('Log in') }}
                        </flux:button>
                        @if ($canRegister)
                            <flux:button :href="route('register')" variant="primary" size="sm">
                                {{ __('Start trial') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </header>

            <main class="flex-1">
                {{-- Hero --}}
                <section class="relative overflow-hidden">
                    {{-- waving dot grid + lime radial glow --}}
                    <div aria-hidden="true" class="pointer-events-none absolute inset-0 z-0 select-none">
                        <canvas
                            x-data="{
                                init() {
                                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                                    const canvas = this.$el;
                                    const ctx = canvas.getContext('2d');
                                    const spacing = 28;
                                    let t = 0, raf;
                                    const resize = () => {
                                        canvas.width  = canvas.offsetWidth;
                                        canvas.height = canvas.offsetHeight;
                                    };
                                    this.$nextTick(resize);
                                    window.addEventListener('resize', resize);
                                    const draw = () => {
                                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                                        const cols = Math.ceil(canvas.width  / spacing) + 1;
                                        const rows = Math.ceil(canvas.height / spacing) + 1;
                                        for (let c = 0; c < cols; c++) {
                                            for (let r = 0; r < rows; r++) {
                                                const x   = c * spacing;
                                                const dy  = Math.sin(c * 0.35 + t) * 5;
                                                const y   = r * spacing + dy;
                                                const opc = 0.20 + Math.sin(c * 0.35 + t) * 0.12;
                                                ctx.beginPath();
                                                ctx.arc(x, y, 1.5, 0, Math.PI * 2);
                                                ctx.fillStyle = `rgba(255,255,255,${Math.max(0.08, opc)})`;
                                                ctx.fill();
                                            }
                                        }
                                        t += 0.012;
                                        raf = requestAnimationFrame(draw);
                                    };
                                    draw();
                                }
                            }"
                            class="absolute inset-0 h-full w-full"
                        ></canvas>
                        <div class="absolute inset-0" style="background: radial-gradient(ellipse 90% 55% at 50% -5%, rgba(132,204,22,0.14) 0%, transparent 70%);"></div>
                    </div>
                    <div class="relative z-10 mx-auto w-full max-w-5xl px-6 text-center" style="padding-top: 5rem; padding-bottom: 5rem;">
                        <flux:badge color="lime" size="sm">{{ __('Open beta') }}</flux:badge>
                        <flux:heading size="xl" class="mt-6 text-4xl leading-tight md:text-5xl">
                            {{ __('Write blogs your brand would actually publish.') }}
                        </flux:heading>

                        {{-- Agent output carousel --}}
                        <div
                            class="mt-10 mx-auto max-w-lg text-left"
                            x-data="{
                                current: 0,
                                init() {
                                    setInterval(() => { this.current = (this.current + 1) % 4 }, 3500)
                                }
                            }"
                        >
                            <div class="mb-3 px-0.5">
                                <flux:text>{{ __('Five specialists. One post. No generic output.') }}</flux:text>
                            </div>

                            <div class="relative overflow-hidden rounded-lg" style="min-height: 210px;">
                                {{-- Research card --}}
                                <div
                                    x-show="current === 0"
                                    x-transition:enter="transition-opacity duration-500"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition-opacity duration-500"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute inset-0"
                                >
                                    <div class="h-full rounded-lg border border-zinc-700 bg-zinc-900 p-4 pb-8">
                                        <div class="text-sm text-purple-400">&#10003; {{ __('Gathered 12 claims from 9 sources') }}</div>
                                        <ul class="mt-2 list-disc pl-5">
                                            <li class="text-sm text-zinc-400">{{ __('68% of B2B marketers cite SEO as their top acquisition channel.') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('Companies publishing 11+ posts/month get 3× more traffic.') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('Long-form posts (2,000+ words) earn 77% more backlinks.') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('Average B2B buyer reads 3–5 pieces before engaging with sales.') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('AI-generated content penalties increased 40% in the last core update.') }}</li>
                                        </ul>
                                        <div class="mt-1 text-sm text-zinc-500">{{ __('…and 7 more') }}</div>
                                    </div>
                                </div>

                                {{-- Audience card --}}
                                <div
                                    x-cloak
                                    x-show="current === 1"
                                    x-transition:enter="transition-opacity duration-500"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition-opacity duration-500"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute inset-0"
                                >
                                    <div class="h-full rounded-lg border border-zinc-700 bg-zinc-900 p-4 pb-8">
                                        <div class="text-sm text-amber-400">&#10003; {{ __('Audience: persona selected') }}</div>
                                        <div class="mt-2 text-sm text-zinc-400">{{ __('Write for a growth-stage SaaS founder sick of generic AI content. Start with the SEO numbers, then make the case for writing with an actual point of view.') }}</div>
                                    </div>
                                </div>

                                {{-- Outline card --}}
                                <div
                                    x-cloak
                                    x-show="current === 2"
                                    x-transition:enter="transition-opacity duration-500"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition-opacity duration-500"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute inset-0"
                                >
                                    <div class="h-full rounded-lg border border-zinc-700 bg-zinc-900 p-4 pb-8">
                                        <div class="text-sm text-blue-400">&#10003; {{ __('Outline ready (6 sections)') }}</div>
                                        <ul class="mt-2 list-disc pl-5">
                                            <li class="text-sm text-zinc-400">{{ __('Why generic AI content is hurting your SEO') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('The case for brand-grounded content') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('What specialist agents do differently') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('Building your brand intelligence layer') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('A topic research workflow that stays on-brand') }}</li>
                                            <li class="text-sm text-zinc-400">{{ __('Getting your first draft out the door') }}</li>
                                        </ul>
                                    </div>
                                </div>

                                {{-- Draft card --}}
                                <div
                                    x-cloak
                                    x-show="current === 3"
                                    x-transition:enter="transition-opacity duration-500"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition-opacity duration-500"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute inset-0"
                                >
                                    <div class="h-full rounded-lg border border-zinc-700 bg-zinc-900 p-4 pb-8">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm text-green-400">&#10003; {{ __('Draft created · v1 · 1,243 words') }}</span>
                                            <span class="text-sm text-indigo-400">{{ __('Open →') }}</span>
                                        </div>
                                        <div class="text-base font-semibold text-zinc-200">{{ __('Why Generic AI Content Is Hurting Your SEO (And What to Do About It)') }}</div>
                                        <div class="mt-2 text-sm text-zinc-400 line-clamp-3">{{ __('The uncomfortable truth about AI writing tools is that they all read from the same playbook. Same structure, same phrasing, same lack of a point of view. Google is getting better at detecting this. Your audience already knows.') }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2 px-0.5">
                                <flux:text variant="subtle" class="text-xs" x-text="['Step 1 · Researcher', 'Step 2 · Audience Picker', 'Step 3 · Editor', 'Step 4 · Writer'][current]"></flux:text>
                            </div>
                        </div>

                        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                            @if ($canRegister)
                                <flux:button :href="route('register')" variant="primary">
                                    {{ __('Start 30-day free trial') }}
                                </flux:button>
                            @endif
                            <flux:button :href="route('login')" variant="ghost">
                                {{ __('Log in') }}
                            </flux:button>
                        </div>
                    </div>
                </section>

                <flux:separator />

                {{-- Foundation + pipeline --}}
                <section class="mx-auto w-full max-w-5xl px-6 py-16">

                    {{-- Brand intelligence + topic research --}}
                    <div class="grid gap-12 md:grid-cols-2 md:items-center">
                        <div>
                            <flux:heading size="xl">{{ __('Built on your brand, not a blank slate.') }}</flux:heading>
                            <flux:text class="mt-4 leading-relaxed">
                                {{ __('Before any writing starts, MarketMinded learns who you are. You answer a few questions about your positioning, voice, and audience. Every step of the writing process uses that context automatically.') }}
                            </flux:text>
                            <flux:text class="mt-3 leading-relaxed">
                                {{ __('Topic research works the same way. Ideas are filtered against what you actually stand for, so you only see angles that fit.') }}
                            </flux:text>
                        </div>
                        <div class="mt-8 flex gap-3 md:mt-0">
                            <flux:card class="flex-1 space-y-2" size="sm">
                                <div class="flex size-8 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon name="sparkles" class="size-4 text-zinc-700 dark:text-zinc-200" />
                                </div>
                                <flux:heading size="lg">{{ __('Brand Intelligence') }}</flux:heading>
                                <flux:text size="sm">{{ __('Positioning · voice · personas. Set once.') }}</flux:text>
                            </flux:card>
                            <flux:card class="flex-1 space-y-2" size="sm">
                                <div class="flex size-8 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon name="magnifying-glass" class="size-4 text-zinc-700 dark:text-zinc-200" />
                                </div>
                                <flux:heading size="lg">{{ __('Topic Research') }}</flux:heading>
                                <flux:text size="sm">{{ __('Filtered against your positioning. Only angles that fit.') }}</flux:text>
                            </flux:card>
                        </div>
                    </div>

                    {{-- 5 writing specialists --}}
                    <div class="mt-24">
                        <flux:heading size="xl">{{ __('Five specialists write every post.') }}</flux:heading>
                        <flux:subheading class="mt-3 max-w-lg">
                            {{ __('Not one chatbot trying to do everything. Five focused agents, each with one job.') }}
                        </flux:subheading>
                    </div>

                    @php
                    $steps = [
                        [
                            'num'      => '1',
                            'name'     => __('Researcher'),
                            'desc'     => __('Runs live web searches. Extracts 8–15 sourced claims: stats, quotes, facts.'),
                            'numClass' => 'text-purple-600 dark:text-purple-400',
                        ],
                        [
                            'num'      => '2',
                            'name'     => __('Audience Picker'),
                            'desc'     => __('Reads the research and your personas. Picks who this post is for and briefs the writer.'),
                            'numClass' => 'text-amber-600 dark:text-amber-400',
                        ],
                        [
                            'num'      => '3',
                            'name'     => __('Editor'),
                            'desc'     => __('Picks the strongest claims and maps out the structure.'),
                            'numClass' => 'text-blue-600 dark:text-blue-400',
                        ],
                        [
                            'num'      => '4',
                            'name'     => __('Writer'),
                            'desc'     => __('Writes the full post in your voice, shaped by the outline and who it\'s for.'),
                            'numClass' => 'text-green-600 dark:text-green-400',
                        ],
                        [
                            'num'      => '5',
                            'name'     => __('Proofreader'),
                            'desc'     => __('Reads the draft for tone, clarity, and consistency before it reaches you.'),
                            'numClass' => 'text-teal-600 dark:text-teal-400',
                        ],
                    ];
                    @endphp

                    <div class="mt-10 grid gap-3 sm:grid-cols-3">
                        @foreach ($steps as $step)
                            <flux:card class="space-y-2">
                                <div class="text-3xl font-bold {{ $step['numClass'] }}">{{ $step['num'] }}</div>
                                <flux:heading size="lg">{{ $step['name'] }}</flux:heading>
                                <flux:text size="sm" variant="subtle">{{ $step['desc'] }}</flux:text>
                            </flux:card>
                        @endforeach
                    </div>

                </section>

                <flux:separator />

                {{-- Pricing (scrolling text as background) --}}
                <section class="relative overflow-hidden py-16">
                    <div aria-hidden="true" class="pointer-events-none absolute inset-0 z-0 select-none overflow-hidden">
                        <div class="landing-scroll break-all text-zinc-900/[0.05] dark:text-white/[0.05]" style="font-family: 'Pirata One', serif; font-size: 40px; line-height: 1.4; animation: landing-scroll 180s linear infinite;">
                            @for ($i = 0; $i < 14; $i++)
                                Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
                            @endfor
                            @for ($i = 0; $i < 14; $i++)
                                Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
                            @endfor
                        </div>
                    </div>

                    <div class="relative z-10 mx-auto max-w-sm px-6">
                        <div class="space-y-5 rounded-xl border border-zinc-200 bg-white p-6 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <div>
                                <flux:badge color="lime" size="sm">{{ __('Free for 30 days') }}</flux:badge>
                                <flux:heading size="xl" class="mt-4 text-4xl">
                                    $25<flux:text inline class="text-lg"> /{{ __('mo') }}</flux:text>
                                </flux:heading>
                                <flux:text variant="subtle" size="sm" class="mt-1">
                                    {{ __('after trial · open beta pricing') }}
                                </flux:text>
                            </div>

                            <ul class="space-y-2 text-left">
                                @foreach ([
                                    __('Brand setup (positioning, voice, personas)'),
                                    __('Topic research matched to your positioning'),
                                    __('5-agent writing pipeline'),
                                    __('Unlimited drafts'),
                                    __('Bring your own API key, no markup'),
                                    __('Your data is never used for training'),
                                    __('One team, one seat'),
                                ] as $feature)
                                    <li class="flex items-center gap-2">
                                        <flux:icon name="check" class="size-4 shrink-0 text-lime-600 dark:text-lime-400" />
                                        <flux:text>{{ $feature }}</flux:text>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="space-y-3">
                                @if ($canRegister)
                                    <flux:button :href="route('register')" variant="primary" class="w-full">
                                        {{ __('Start 30-day free trial') }}
                                    </flux:button>
                                @else
                                    <flux:button :href="route('login')" variant="primary" class="w-full">
                                        {{ __('Log in') }}
                                    </flux:button>
                                @endif
                                <flux:text variant="subtle" size="sm">
                                    {{ __('No card needed. Paid plans are not live yet, so your trial stays free until they are.') }}
                                </flux:text>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <footer class="border-t border-zinc-200/70 dark:border-zinc-800/70">
                <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-6">
                    <x-app-logo />
                    <flux:text variant="subtle" size="sm">&copy; {{ date('Y') }} MarketMinded</flux:text>
                    <a href="{{ route('login') }}" wire:navigate class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100">
                        {{ __('Log in') }}
                    </a>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
