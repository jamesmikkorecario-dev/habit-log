<nav aria-label="Settings sections" class="flex flex-wrap gap-2 border-b border-zinc-800 pb-4">
    @foreach(['profile' => 'Profile', 'security' => 'Security'] as $section => $label)
        <a href="{{ route('settings.'.$section) }}"
            @if(request()->routeIs('settings.'.$section)) aria-current="page" @endif
            class="inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 {{ request()->routeIs('settings.'.$section) ? 'bg-emerald-950/50 text-emerald-300' : 'text-zinc-400 hover:bg-zinc-900 hover:text-zinc-100' }}">{{ $label }}</a>
    @endforeach
</nav>
