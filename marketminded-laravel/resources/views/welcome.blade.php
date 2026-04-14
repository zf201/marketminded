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
                <section class="relative overflow-hidden">
                    <div aria-hidden="true" class="pointer-events-none absolute inset-0 select-none overflow-hidden">
                        <div class="landing-scroll break-all text-zinc-900/[0.05] dark:text-white/[0.05]" style="font-family: 'Pirata One', serif; font-size: 40px; line-height: 1.4; animation: landing-scroll 180s linear infinite;">
                            @for ($i = 0; $i < 14; $i++)
                                Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
                            @endfor
                            @for ($i = 0; $i < 14; $i++)
                                Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
                            @endfor
                        </div>
                    </div>
                    <div class="relative mx-auto w-full max-w-5xl px-6 pt-20 pb-16 text-center">
                    <flux:badge color="lime" size="sm">{{ __('Open beta') }}</flux:badge>
                    <flux:heading size="xl" class="mt-6 text-4xl leading-tight md:text-5xl">
                        {{ __('Write blogs your brand would actually publish.') }}
                    </flux:heading>
                    <flux:subheading class="mx-auto mt-5 max-w-2xl text-base md:text-lg">
                        {{ __('Specialist AI agents that learn your brand, pick the right topics, and draft blog and social copy. Bring your own API key.') }}
                    </flux:subheading>
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

                <section class="mx-auto w-full max-w-5xl px-6 py-12">
                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:card class="space-y-3">
                            <div class="flex size-10 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon name="sparkles" class="size-5 text-zinc-700 dark:text-zinc-200" />
                            </div>
                            <flux:heading size="lg">{{ __('Brand intelligence') }}</flux:heading>
                            <flux:text>
                                {{ __('A living profile of your positioning, personas, and voice. Every agent reads from it.') }}
                            </flux:text>
                        </flux:card>

                        <flux:card class="space-y-3">
                            <div class="flex size-10 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon name="chat-bubble-left-right" class="size-5 text-zinc-700 dark:text-zinc-200" />
                            </div>
                            <flux:heading size="lg">{{ __('Specialist agents') }}</flux:heading>
                            <flux:text>
                                {{ __('Brand strategist, topic researcher, and writer — each tuned for its step, not one generic chatbot.') }}
                            </flux:text>
                        </flux:card>

                        <flux:card class="space-y-3">
                            <div class="flex size-10 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon name="key" class="size-5 text-zinc-700 dark:text-zinc-200" />
                            </div>
                            <flux:heading size="lg">{{ __('Bring your own key') }}</flux:heading>
                            <flux:text>
                                {{ __('Plug in your own model key. Your usage, your control, no markup.') }}
                            </flux:text>
                        </flux:card>
                    </div>
                </section>

                <section class="mx-auto w-full max-w-5xl px-6 py-12">
                    <flux:card class="mx-auto max-w-xl text-center">
                        <div class="space-y-4">
                            <flux:badge color="lime" size="sm">{{ __('Free for 30 days') }}</flux:badge>
                            <div>
                                <flux:heading size="xl" class="text-4xl">
                                    $25<flux:text class="inline text-lg text-zinc-500"> / {{ __('month after trial') }}</flux:text>
                                </flux:heading>
                                <flux:text class="mt-2">
                                    {{ __('Single team, single seat. Open beta pricing.') }}
                                </flux:text>
                                <flux:text class="mt-1 text-sm text-zinc-500">
                                    {{ __('You supply your own AI model key.') }}
                                </flux:text>
                            </div>
                            <div class="pt-2">
                                @if ($canRegister)
                                    <flux:button :href="route('register')" variant="primary">
                                        {{ __('Start 30-day free trial') }}
                                    </flux:button>
                                @else
                                    <flux:button :href="route('login')" variant="primary">
                                        {{ __('Log in') }}
                                    </flux:button>
                                @endif
                            </div>
                            <flux:text class="pt-1 text-xs text-zinc-500">
                                {{ __('Paid subscriptions are not yet available. Your trial stays free until they are.') }}
                            </flux:text>
                        </div>
                    </flux:card>
                </section>
            </main>

            <footer class="w-full border-t border-zinc-200/70 dark:border-zinc-800/70">
                <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-6">
                    <x-app-logo />
                    <flux:text class="text-sm text-zinc-500">
                        &copy; {{ date('Y') }} MarketMinded
                    </flux:text>
                    <a href="{{ route('login') }}" wire:navigate class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100">
                        {{ __('Log in') }}
                    </a>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
