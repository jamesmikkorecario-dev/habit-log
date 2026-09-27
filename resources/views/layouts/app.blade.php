<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <header class="border-b border-emerald-900/50">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <a href="{{ route('dashboard') }}" class="rounded font-mono text-lg font-semibold text-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300" wire:navigate>
                    habit.log
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md px-3 py-2 text-sm text-zinc-300 hover:bg-emerald-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                        Log out
                    </button>
                </form>
            </div>
        </header>
        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>
        @fluxScripts
    </body>
</html>
