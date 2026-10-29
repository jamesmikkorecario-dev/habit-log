<x-layouts::auth.split title="Account deleted">
    <div class="space-y-5 text-center">
        <h1 tabindex="-1" x-data x-init="$nextTick(() => $el.focus())" class="text-2xl font-semibold text-zinc-100 focus-visible:outline-2 focus-visible:outline-emerald-300">Account deleted</h1>
        <p class="text-sm leading-relaxed text-zinc-400">Your account and its live data have been permanently deleted. You are signed out, and your previous sign-ins and recovery links no longer work.</p>
        <flux:button :href="route('home')" class="min-h-11">Return home</flux:button>
    </div>
</x-layouts::auth.split>
