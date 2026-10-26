<x-layouts::app title="Confirm email change">
    <div class="max-w-2xl space-y-6">
        <header>
            <x-terminal-path path="~/settings/security" />
            <h1 class="mt-3 text-3xl font-semibold text-zinc-100">Confirm email change</h1>
        </header>
        <section class="space-y-6 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
            @if($pendingEmail)
                <p class="break-all text-sm text-zinc-300">New login email: {{ $pendingEmail->new_email }}</p>
                <p class="text-sm leading-relaxed text-zinc-400">Confirm with your current password. You will stay signed in here; other sessions and remembered logins will be signed out. Your password and saved activity stay the same.</p>
                <x-email-change-errors />
                <x-email-change-form :action="$verificationUrl" label="Confirm email change">
                    <flux:input name="current_password" label="Current password to confirm email" type="password" required autocomplete="current-password" viewable error:bag="emailConfirm" :invalid="$errors->emailConfirm->has('current_password')" />
                </x-email-change-form>
            @else
                <p role="alert" class="text-sm text-zinc-300">{{ \App\Actions\Accounts\EmailChanges::INVALID }}</p>
            @endif
            <flux:button :href="route('settings.security')" class="min-h-11">Back to Security Settings</flux:button>
        </section>
    </div>
</x-layouts::app>
