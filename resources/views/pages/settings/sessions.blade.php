<section aria-labelledby="active-sessions" class="max-w-2xl space-y-6 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6"
    x-data="{ target: 'all', device: '', submitting: false }">
    <div>
        <h2 id="active-sessions" class="text-xl font-semibold text-zinc-100">Active sessions</h2>
        <p class="mt-2 text-sm leading-relaxed text-zinc-400">Review where you are signed in. Browser and device names are estimates, not verified device identities.</p>
    </div>
    @if(!$sessionListing['available'])
        <p class="text-sm text-zinc-300">Session management is unavailable with this server's session configuration. Contact the service operator.</p>
    @else
        @if(session('session-status'))
            <p role="status" tabindex="-1" class="rounded-lg border border-emerald-800 bg-emerald-950/30 p-4 text-sm text-emerald-300 focus-visible:outline-2 focus-visible:outline-emerald-300"
                x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">{{ session('session-status') }}</p>
        @endif
        @if($errors->sessionRevoke->any())
            <div role="alert" tabindex="-1" class="scroll-mt-24 space-y-2 rounded-lg border border-red-900/60 p-4 text-sm text-red-300 focus-visible:outline-2 focus-visible:outline-emerald-300"
                x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">
                <p>No sessions were signed out. Review the message, then try again.</p>
                <ul class="list-inside list-disc">@foreach($errors->sessionRevoke->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div class="rounded-lg border border-emerald-900 p-4">
            <h3 class="font-semibold text-emerald-300">This session</h3>
            <p class="mt-2 text-sm text-zinc-300">{{ $sessionListing['currentDevice'] }}</p>
            <p class="mt-1 text-sm text-zinc-400">Active now · stays signed in</p>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold">Other sessions ({{ $sessionListing['sessions']->total() }})</h3>
            <flux:button :href="route('settings.security')" class="min-h-11">Refresh sessions</flux:button>
        </div>
        @if($sessionListing['sessions']->isEmpty())
            <p class="text-sm text-zinc-400">No other active sessions on this page.</p>
        @else
            <ul class="space-y-3" aria-label="Other active sessions">
                @foreach($sessionListing['sessions'] as $session)
                    <li class="space-y-3 rounded-lg border border-zinc-800 p-4">
                        <p class="font-medium text-zinc-200">{{ $session['device'] }}</p>
                        <p class="text-sm text-zinc-400">Last active {{ $session['last_active'] }} · {{ auth()->user()->timezone }}</p>
                        <flux:modal.trigger name="revoke-session">
                            <flux:button class="min-h-11 w-full sm:w-auto" x-on:click="target = @js($session['key']); device = @js($session['device'])">Sign out this session</flux:button>
                        </flux:modal.trigger>
                    </li>
                @endforeach
            </ul>
        @endif
        {{ $sessionListing['sessions']->links() }}
        <p class="text-sm leading-relaxed text-zinc-400">Signing out any session also clears remembered sign-ins across your account. Other active sessions stay signed in when you select just one. Your current session stays active.</p>
        <flux:modal.trigger name="revoke-session">
            <flux:button class="min-h-11 w-full sm:w-auto" x-on:click="target = 'all'; device = ''">Sign out all other sessions</flux:button>
        </flux:modal.trigger>
        <flux:modal name="revoke-session" class="w-[calc(100%-2rem)] min-w-0! max-w-lg [&_[data-flux-modal-close]_button]:min-h-11 [&_[data-flux-modal-close]_button]:min-w-11"
            x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'revoke-session-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'revoke-session-description')"
            x-on:close="submitting = false; $el.querySelector('form').reset()">
            <form method="POST" action="{{ route('settings.sessions.revoke') }}" aria-label="Confirm session sign out" class="space-y-6"
                x-on:submit="if (submitting) $event.preventDefault(); else submitting = true" x-bind:aria-busy="submitting">
                @csrf
                <input type="hidden" name="session_target" x-bind:value="target">
                <h2 id="revoke-session-title" class="pr-6 text-lg font-semibold">Confirm session sign out</h2>
                <p id="revoke-session-description" class="text-sm text-zinc-300" x-text="target === 'all' ? 'Sign out all other sessions and clear remembered sign-ins? You will stay signed in here.' : 'Sign out the selected session (' + device + ') and clear remembered sign-ins? Other active sessions will stay signed in.'"></p>
                <flux:input name="current_password" type="password" label="Current password to sign out sessions" autocomplete="current-password" required viewable />
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button type="button" class="min-h-11">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" class="min-h-11" x-bind:disabled="submitting" :loading="false"><span x-show="!submitting">Confirm sign out</span><span x-show="submitting" x-cloak>Signing out…</span></flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
