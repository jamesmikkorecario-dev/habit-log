<x-layouts::app title="Security Settings">
    <div class="min-w-0 space-y-6 sm:space-y-8">
        <header>
            <x-terminal-path path="~/settings/security" />
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Security Settings</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">Keep your account secure.</p>
        </header>

        <x-settings-navigation />

        <section aria-labelledby="change-password" class="max-w-2xl space-y-6 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
            <div>
                <h2 id="change-password" class="text-xl font-semibold text-zinc-100">Change password</h2>
                <p class="mt-2 text-sm leading-relaxed text-zinc-400">You will stay signed in here. Your other sessions and remembered logins will be signed out.</p>
            </div>

            @if(session('password-status'))
                <p role="status" tabindex="-1" class="rounded-lg border border-emerald-800 bg-emerald-950/30 p-4 text-sm text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300"
                    x-data x-init="$nextTick(() => $el.focus())">{{ session('password-status') }}</p>
            @endif

            <form method="POST" action="{{ route('user-password.update') }}" aria-label="Change password" class="space-y-5"
                x-data="{ submitting: false }" x-on:submit="if (submitting) $event.preventDefault(); else submitting = true" x-bind:aria-busy="submitting"
                x-on:pageshow.window="submitting = false; if ($event.persisted) $el.reset()">
                @csrf
                @method('PUT')
                @if($errors->updatePassword->any())
                    <p role="alert" tabindex="-1" class="scroll-mt-24 rounded-lg border border-red-900/60 p-4 text-sm text-red-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300"
                        x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">Your password has not changed. Please review the highlighted fields.</p>
                @endif

                <flux:input name="current_password" label="Current password" type="password" required autocomplete="current-password" viewable
                    error:bag="updatePassword" :invalid="$errors->updatePassword->has('current_password')" />
                <flux:input name="password" label="New password" type="password" required autocomplete="new-password" viewable
                    error:bag="updatePassword" :invalid="$errors->updatePassword->has('password')"
                    :description="app()->isProduction() ? 'Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol. Avoid passwords found in data breaches.' : 'Use at least 8 characters. Choose a long, unique password.'" />
                <flux:input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" viewable
                    error:bag="updatePassword" :invalid="$errors->updatePassword->has('password_confirmation')" />

                <flux:button type="submit" variant="primary" class="min-h-11 w-full sm:w-auto" x-bind:disabled="submitting" :loading="false">
                    <span x-text="submitting ? 'Changing password…' : 'Change password'">Change password</span>
                </flux:button>
                <p role="status" class="text-sm text-zinc-400" x-show="submitting" x-cloak>Changing your password…</p>
            </form>
        </section>
    </div>
</x-layouts::app>
