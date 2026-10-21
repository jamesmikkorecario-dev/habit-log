<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <a href="#auth-form" class="sr-only z-50 rounded-md bg-emerald-300 px-4 py-3 font-medium text-zinc-950 focus:not-sr-only focus:absolute focus:left-4 focus:top-4">{{ __('Skip to form') }}</a>
        <div class="mx-auto flex min-h-svh max-w-6xl flex-col justify-center px-4 py-6 sm:px-6 sm:py-10 lg:py-12">
            <header class="mb-6 flex items-center justify-between gap-4 lg:hidden">
                <a href="{{ route('home') }}" aria-label="habit.log home" class="inline-flex min-h-11 items-center rounded font-mono text-xl font-semibold tracking-tight text-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300" wire:navigate>habit.log</a>
                <span class="font-mono text-xs text-zinc-400">{{ __('One day at a time.') }}</span>
            </header>

            <div class="grid overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/40 shadow-lg shadow-black/20 lg:grid-cols-2">
                <aside aria-label="{{ __('About habit.log') }}" class="hidden min-w-0 flex-col justify-between gap-12 border-r border-zinc-800 bg-zinc-900/70 p-10 lg:flex xl:p-12">
                    <a href="{{ route('home') }}" aria-label="habit.log home" class="inline-flex min-h-11 self-start items-center rounded font-mono text-xl font-semibold tracking-tight text-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300" wire:navigate>habit.log</a>
                    <div>
                        <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">{{ __('A little progress, every day.') }}</p>
                        <h2 class="mt-5 text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">{{ __('Small steps.') }}<br><span class="text-emerald-400">{{ __('Lasting change.') }}</span></h2>
                        <p class="mt-5 max-w-sm leading-relaxed text-zinc-400">{{ __('Build habits that fit your life. See your progress, make space to reflect, and find your own rhythm.') }}</p>
                        <div aria-hidden="true" class="mt-9 border-t border-zinc-700 pt-6">
                            <div class="grid grid-flow-col grid-rows-7 gap-1.5">
                                @foreach (['0102100', '1210210', '2101321', '1223032', '0321231', '2132230', '3212321', '1223132', '2313232', '3221343', '2332342', '3243343', '3432344', '3443444'] as $week)
                                    @foreach (str_split($week) as $band)
                                        <span @class(['heat-cell aspect-square h-auto! w-full!', 'heat-band-'.$band])></span>
                                    @endforeach
                                @endforeach
                            </div>
                            <p class="mt-3 font-mono text-xs text-zinc-400">{{ __('One day becomes a pattern.') }}</p>
                        </div>
                    </div>
                    <p class="flex items-start gap-2 text-sm leading-relaxed text-zinc-400"><flux:icon.lock-closed variant="micro" class="mt-0.5 size-4 shrink-0 text-emerald-400" aria-hidden="true" />{{ __('Your habits start private. Sharing is your choice.') }}</p>
                </aside>

                <main id="auth-form" tabindex="-1" class="flex min-w-0 items-center justify-center px-5 py-8 outline-none sm:px-10 sm:py-10 lg:px-12">
                    <div class="w-full max-w-sm [&_input[data-flux-control]]:min-h-11 [&_button]:min-h-11 [&_button]:min-w-11 [&_button]:focus-visible:outline-2 [&_button]:focus-visible:outline-offset-2 [&_button]:focus-visible:outline-emerald-300 [&_a]:focus-visible:outline-2 [&_a]:focus-visible:outline-offset-4 [&_a]:focus-visible:outline-emerald-300 motion-reduce:[&_*]:transition-none motion-reduce:[&_*]:animate-none">
                        {{ $slot }}
                    </div>
                </main>
            </div>
            <footer class="mt-5 text-center">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center gap-2 rounded font-mono text-xs text-zinc-400 transition-colors hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300 motion-reduce:transition-none" wire:navigate><span aria-hidden="true">←</span>{{ __('Back to home') }}</a>
            </footer>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist
        @fluxScripts
    </body>
</html>
