<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <div class="min-h-svh flex flex-col">
            {{-- Navigation --}}
            <nav
                class="sticky top-0 z-40 border-b border-emerald-900/50 bg-zinc-950/95 backdrop-blur supports-[backdrop-filter]:bg-zinc-950/80"
                x-data="{ mobileOpen: false }"
            >
                <div class="mx-auto max-w-5xl px-4 sm:px-6">
                    <div class="flex h-14 items-center justify-between">
                        {{-- Branding --}}
                        <a
                            href="{{ route('dashboard') }}"
                            class="rounded font-mono text-lg font-semibold text-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300"
                            wire:navigate
                        >
                            habit.log
                        </a>

                        {{-- Desktop navigation --}}
                        <div class="hidden items-center gap-1 md:flex">
                            <a
                                href="{{ route('dashboard') }}"
                                class="rounded-md px-3 py-2 font-mono text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none {{ request()->routeIs('dashboard') ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:bg-emerald-950/30 hover:text-emerald-300' }}"
                                wire:navigate
                                @if(request()->routeIs('dashboard')) aria-current="page" @endif
                            >
                                Dashboard
                            </a>
                            @foreach(['tracking' => 'Tracking', 'journal' => 'Journal', 'habits' => 'Habits', 'categories' => 'Categories'] as $section => $label)
                                <a href="{{ route($section.'.index') }}" wire:navigate
                                    class="rounded-md px-3 py-2 font-mono text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 {{ request()->routeIs($section.'.*') ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:text-emerald-300' }}"
                                    @if(request()->routeIs($section.'.*')) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                            <a
                                href="{{ route('settings.profile') }}"
                                class="rounded-md px-3 py-2 font-mono text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none {{ request()->routeIs('settings.*') ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:bg-emerald-950/30 hover:text-emerald-300' }}"
                                wire:navigate
                                @if(request()->routeIs('settings.*')) aria-current="page" @endif
                            >
                                Settings
                            </a>
                        </div>

                        <div class="flex items-center gap-2">
                            {{-- User dropdown (desktop) --}}
                            <flux:dropdown class="hidden md:block" align="end">
                                <button
                                    type="button"
                                    aria-label="Account menu"
                                    class="flex h-10 w-10 items-center justify-center rounded-full border border-emerald-800 font-mono text-xs font-semibold text-emerald-300 transition hover:border-emerald-500 hover:bg-emerald-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none"
                                >
                                    {{ auth()->user()->initials() }}
                                </button>

                                <flux:menu class="w-48 dark:border-emerald-900/50 dark:bg-zinc-900">
                                    <div class="border-b border-emerald-900/50 px-3 py-2">
                                        <p class="truncate font-mono text-sm text-zinc-100">{{ auth()->user()->displayName() }}</p>
                                        @if(auth()->user()->username)
                                            <p class="truncate font-mono text-xs text-zinc-400">{{ '@' . auth()->user()->username }}</p>
                                        @endif
                                    </div>
                                    <flux:menu.item :href="route('settings.profile')" wire:navigate>
                                        Profile settings
                                    </flux:menu.item>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <flux:menu.item type="submit">
                                            Log out
                                        </flux:menu.item>
                                    </form>
                                </flux:menu>
                            </flux:dropdown>

                            {{-- Mobile menu button --}}
                            <button
                                @click="mobileOpen = !mobileOpen"
                                :aria-expanded="mobileOpen.toString()"
                                aria-label="Toggle navigation"
                                class="flex h-10 w-10 items-center justify-center rounded-md text-zinc-400 transition hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 md:hidden motion-reduce:transition-none"
                            >
                                <svg x-show="!mobileOpen" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                                <svg x-show="mobileOpen" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Mobile menu --}}
                <div x-show="mobileOpen" x-cloak x-transition class="border-t border-emerald-900/50 md:hidden motion-reduce:transition-none!">
                    <div class="space-y-1 px-4 py-3">
                        <a
                            href="{{ route('dashboard') }}"
                            class="block rounded-md px-3 py-2 font-mono text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 {{ request()->routeIs('dashboard') ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:bg-emerald-950/30 hover:text-emerald-300' }}"
                            wire:navigate
                            @click="mobileOpen = false"
                            @if(request()->routeIs('dashboard')) aria-current="page" @endif
                        >
                            Dashboard
                        </a>
                        @foreach(['tracking' => 'Tracking', 'journal' => 'Journal', 'habits' => 'Habits', 'categories' => 'Categories'] as $section => $label)
                            <a href="{{ route($section.'.index') }}" wire:navigate @click="mobileOpen = false"
                                class="block rounded-md px-3 py-2 font-mono text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 {{ request()->routeIs($section.'.*') ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:text-emerald-300' }}"
                                @if(request()->routeIs($section.'.*')) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                        <a
                            href="{{ route('settings.profile') }}"
                            class="block rounded-md px-3 py-2 font-mono text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 {{ request()->routeIs('settings.*') ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:bg-emerald-950/30 hover:text-emerald-300' }}"
                            wire:navigate
                            @click="mobileOpen = false"
                            @if(request()->routeIs('settings.*')) aria-current="page" @endif
                        >
                            Settings
                        </a>
                    </div>
                    <div class="border-t border-emerald-900/50 px-4 py-3">
                        <p class="truncate px-3 font-mono text-sm text-zinc-100">{{ auth()->user()->displayName() }}</p>
                        @if(auth()->user()->username)
                            <p class="truncate px-3 font-mono text-xs text-zinc-500">{{ '@' . auth()->user()->username }}</p>
                        @endif
                        <div class="mt-2 space-y-1">
                            <a
                                href="{{ route('settings.profile') }}"
                                class="block rounded-md px-3 py-2 text-sm text-zinc-400 transition hover:bg-emerald-950/30 hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none"
                                wire:navigate
                                @click="mobileOpen = false"
                            >
                                Profile settings
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button
                                    type="submit"
                                    class="block w-full rounded-md px-3 py-2 text-left text-sm text-zinc-400 transition hover:bg-emerald-950/30 hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 motion-reduce:transition-none"
                                >
                                    Log out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </nav>

            {{-- Main content --}}
            <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
                {{ $slot }}
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
