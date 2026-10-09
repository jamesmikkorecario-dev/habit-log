<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => 'Build habits. Find your rhythm.'])
        <meta name="description" content="Build habits, understand your progress, and make space to reflect. habit.log is a private-by-default habit tracker with heatmaps, flexible tracking, and a daily journal.">
    </head>
    <body id="top" class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <a href="#main-content" class="sr-only z-50 rounded-md bg-emerald-300 px-4 py-3 font-medium text-zinc-950 focus:not-sr-only focus:absolute focus:left-4 focus:top-4">Skip to content</a>

        <header class="border-b border-zinc-800">
            <nav aria-label="Main navigation" class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded font-mono text-xl font-semibold tracking-tight text-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300" aria-label="habit.log home">habit.log</a>
                <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                    <a href="#features" class="mr-2 hidden min-h-11 items-center rounded px-2 font-mono text-sm text-zinc-400 transition-colors hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none md:inline-flex">Features</a>
                    <a href="#how-it-works" class="mr-5 hidden min-h-11 items-center rounded px-2 font-mono text-sm text-zinc-400 transition-colors hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none md:inline-flex">How it works</a>
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" class="min-h-11" icon:trailing="arrow-right" wire:navigate>Dashboard</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" class="min-h-11" wire:navigate>Log in</flux:button>
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" variant="primary" class="min-h-11" wire:navigate>Register</flux:button>
                        @endif
                    @endauth
                </div>
            </nav>
        </header>

        <main id="main-content" tabindex="-1" class="outline-none">
            <section aria-labelledby="hero-heading" class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-16 pt-14 sm:px-6 sm:py-20 lg:grid-cols-2 lg:gap-14 lg:py-24">
                <div>
                    <p class="flex items-center gap-3 font-mono text-xs tracking-wide text-emerald-300 sm:text-sm"><span class="size-2 rounded-xs bg-emerald-400" aria-hidden="true"></span>A little progress, every day.</p>
                    <h1 id="hero-heading" class="mt-6 text-5xl font-semibold leading-[1.06] tracking-tight sm:text-6xl xl:text-7xl">Build habits.<br><span class="text-emerald-400">Find your<br class="hidden lg:block"> rhythm.</span></h1>
                    <p class="mt-6 max-w-md text-lg leading-relaxed text-zinc-400">Make room for the things that matter. Track the habits you want to build, understand your patterns, and take it one day at a time.</p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        @auth
                            <flux:button :href="route('dashboard')" variant="primary" icon:trailing="arrow-right" class="min-h-12 px-6!" wire:navigate>Open dashboard</flux:button>
                        @else
                            <flux:button :href="route(Route::has('register') ? 'register' : 'login')" variant="primary" icon:trailing="arrow-right" class="min-h-12 px-6!" wire:navigate>Get Started</flux:button>
                        @endauth
                        <flux:button href="#features" variant="ghost" class="min-h-12">Explore the features <span aria-hidden="true">↓</span></flux:button>
                    </div>
                    <p class="mt-5 flex items-center gap-2 text-sm text-zinc-400"><flux:icon.lock-closed variant="micro" class="size-4 text-emerald-400" aria-hidden="true" />Your habits start private. Sharing is your choice.</p>
                </div>
                <x-landing-preview />
            </section>

            <section id="features" aria-labelledby="features-heading" class="scroll-mt-8 border-y border-zinc-800 bg-zinc-900/30">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">Made for your everyday</p>
                            <h2 id="features-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">A little structure.<br>A clearer picture.</h2>
                        </div>
                        <p class="max-w-xs leading-relaxed text-zinc-400">From the first checkmark to a year of showing up. Keep the details and see the patterns.</p>
                    </div>
                    <div class="mt-10 grid gap-10 md:grid-cols-3 md:gap-8">
                        <article class="border-t border-zinc-700 pt-6">
                            <flux:icon.squares-2x2 class="size-6 text-emerald-400" aria-hidden="true" />
                            <h3 class="mt-5 text-xl font-semibold">Track it your way</h3>
                            <p class="mt-3 leading-relaxed text-zinc-400">A daily yes, a number, minutes spent, a checklist, or something to do less of. Choose the tracking style that fits your habit.</p>
                            <ul aria-label="Supported tracking types" class="mt-5 flex flex-wrap gap-2 font-mono text-xs text-zinc-300">
                                @foreach (['Yes / no', 'Count', 'Duration', 'Checkpoints', 'Avoidance'] as $type)
                                    <li class="rounded-md border border-zinc-700 px-2.5 py-1.5">{{ $type }}</li>
                                @endforeach
                            </ul>
                        </article>
                        <article class="border-t border-zinc-700 pt-6">
                            <flux:icon.chart-bar class="size-6 text-emerald-400" aria-hidden="true" />
                            <h3 class="mt-5 text-xl font-semibold">See your consistency</h3>
                            <p class="mt-3 leading-relaxed text-zinc-400">Yearly heatmaps make your activity visible. Follow your streaks and your 30-day and yearly consistency, with credit for partial progress.</p>
                            <p class="mt-5 font-mono text-xs leading-relaxed text-emerald-300">One day becomes a pattern.</p>
                        </article>
                        <article class="border-t border-zinc-700 pt-6">
                            <flux:icon.book-open class="size-6 text-emerald-400" aria-hidden="true" />
                            <h3 class="mt-5 text-xl font-semibold">Keep a little context</h3>
                            <p class="mt-3 leading-relaxed text-zinc-400">Write a daily journal, add an optional mood, or leave a note with your activity. Give the numbers a little of your story.</p>
                            <p class="mt-5 flex items-center gap-2 font-mono text-xs text-emerald-300"><flux:icon.lock-closed variant="micro" class="size-4 shrink-0" aria-hidden="true" />Journals, moods, and notes stay private.</p>
                        </article>
                    </div>
                </div>
            </section>

            <section id="how-it-works" aria-labelledby="steps-heading" class="mx-auto max-w-6xl scroll-mt-8 px-4 py-16 sm:px-6 sm:py-20">
                <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">How it works</p>
                <h2 id="steps-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Start small. Keep coming back.</h2>
                <ol class="mt-10 grid gap-8 md:grid-cols-3 md:gap-10">
                    <li>
                        <span class="font-mono text-sm text-emerald-400" aria-hidden="true">01 /</span>
                        <h3 class="mt-4 text-lg font-semibold">Make it yours</h3>
                        <p class="mt-2 leading-relaxed text-zinc-400">Create an account and choose your first habit. Give it a category, a tracking style, and a goal if you need one.</p>
                    </li>
                    <li>
                        <span class="font-mono text-sm text-emerald-400" aria-hidden="true">02 /</span>
                        <h3 class="mt-4 text-lg font-semibold">Check in with your day</h3>
                        <p class="mt-2 leading-relaxed text-zinc-400">Record what you did, including partial progress. Mark a day N/A when a habit doesn’t apply, and add a note if you like.</p>
                    </li>
                    <li>
                        <span class="font-mono text-sm text-emerald-400" aria-hidden="true">03 /</span>
                        <h3 class="mt-4 text-lg font-semibold">Look back. Learn. Repeat.</h3>
                        <p class="mt-2 leading-relaxed text-zinc-400">Explore your heatmap and journal. Notice what’s working, adjust your habits, and keep your earlier tracking history intact.</p>
                    </li>
                </ol>
                <aside aria-labelledby="privacy-heading" class="mt-14 grid gap-5 rounded-xl border border-emerald-900/70 bg-emerald-950/20 p-6 sm:grid-cols-[auto_1fr] sm:gap-6 sm:p-8">
                    <span class="flex size-12 items-center justify-center rounded-lg border border-emerald-800 bg-emerald-950 text-emerald-300"><flux:icon.lock-closed class="size-6" aria-hidden="true" /></span>
                    <div>
                        <h3 id="privacy-heading" class="text-xl font-semibold">Your habits. Your say.</h3>
                        <p class="mt-2 max-w-3xl leading-relaxed text-zinc-400">New habits are private by default. When you want to share, choose what other signed-in users can see. Private accounts approve followers first; your journal, mood, and habit notes stay just for you.</p>
                    </div>
                </aside>
            </section>

            <section aria-labelledby="cta-heading" class="border-t border-zinc-800">
                <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-8 px-4 py-16 sm:px-6 sm:py-20 md:flex-row md:items-center">
                    <div>
                        <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">Your next chapter</p>
                        <h2 id="cta-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">One habit. One day.<br>A good place to start.</h2>
                        <p class="mt-4 text-zinc-400">Make a little space for the person you want to become.</p>
                    </div>
                    <div class="flex flex-col gap-3">
                        @auth
                            <flux:button :href="route('dashboard')" variant="primary" class="min-h-12 px-6!" icon:trailing="arrow-right" wire:navigate>Open dashboard</flux:button>
                        @else
                            <flux:button :href="route(Route::has('register') ? 'register' : 'login')" variant="primary" class="min-h-12 px-6!" icon:trailing="arrow-right" wire:navigate>{{ Route::has('register') ? 'Create your account' : 'Log in to get started' }}</flux:button>
                        @endauth
                        <p class="text-center font-mono text-xs text-zinc-400">Your pace. Your progress.</p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-zinc-800">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-8 gap-y-2 px-4 py-6 sm:px-6">
                <p class="font-mono text-sm text-zinc-400"><span class="font-semibold text-emerald-400">habit.log</span><span class="mx-3 text-zinc-600" aria-hidden="true">/</span>One day at a time.</p>
                <a href="#top" class="inline-flex min-h-11 items-center rounded font-mono text-xs text-zinc-400 transition-colors hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300 motion-reduce:transition-none">Back to top <span class="ml-2" aria-hidden="true">↑</span></a>
            </div>
        </footer>
        @fluxScripts
    </body>
</html>
