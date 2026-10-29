<section aria-labelledby="delete-account-title" class="max-w-2xl space-y-5 rounded-xl border border-red-900/60 bg-zinc-900/30 p-5 sm:p-6"
    x-data="{ submitting: false }">
    <div>
        <h2 id="delete-account-title" class="text-xl font-semibold text-zinc-100">Delete account</h2>
        <p class="mt-2 text-sm leading-relaxed text-zinc-300">Permanently delete your account and its data. There is no recovery window, including for habits in Trash.</p>
    </div>
    <ul class="list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-400">
        <li>Your profile, all active, archived and trashed habits, entries, notes, tracking history and checkpoints, journals, moods and custom categories will be deleted.</li>
        <li>Your public and follower-visible habits will disappear. Follow requests and relationships in both directions will be removed. Other people's accounts, habits, journals and system categories will remain.</li>
        <li>All signed-in sessions, remembered sign-ins, password-reset links and pending email changes will lose access.</li>
        <li>Deletion is immediate in the live database. If backups are configured, encrypted restricted backups may retain data for at most 30 days. Recovery must reapply deletions before reopening access. Already delivered emails and copies others have saved cannot be recalled.</li>
    </ul>
    @if($errors->accountDeletion->any())
        <div role="alert" tabindex="-1" class="scroll-mt-24 space-y-2 rounded-lg border border-red-900/60 p-4 text-sm text-red-300 focus-visible:outline-2 focus-visible:outline-emerald-300"
            x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">
            <p>Your account was not deleted.</p>
            <ul class="list-inside list-disc">@foreach($errors->accountDeletion->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @if(\App\Actions\Accounts\SessionSecurity::supported())
        <flux:modal.trigger name="delete-account">
            <flux:button class="min-h-11 w-full sm:w-auto">Delete my account</flux:button>
        </flux:modal.trigger>
        <flux:modal name="delete-account" class="w-[calc(100%-2rem)] min-w-0! max-w-lg [&_[data-flux-modal-close]_button]:min-h-11 [&_[data-flux-modal-close]_button]:min-w-11"
            x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'delete-account-confirmation'); $el.querySelector('dialog').setAttribute('aria-describedby', 'delete-account-consequence')"
            x-on:close="submitting = false; $el.querySelector('form').reset()">
            <form method="POST" action="{{ route('settings.account.destroy') }}" aria-label="Confirm account deletion" class="space-y-5"
                x-on:submit="if (submitting) $event.preventDefault(); else submitting = true" x-bind:aria-busy="submitting"
                x-on:pageshow.window="submitting = false; if ($event.persisted) $el.reset()">
                @csrf
                @method('DELETE')
                <input type="hidden" name="account_id" value="{{ auth()->id() }}">
                <h2 id="delete-account-confirmation" class="pr-6 text-lg font-semibold">Permanently delete account?</h2>
                <p id="delete-account-consequence" class="text-sm leading-relaxed text-zinc-300">Your account and its live data, including history and Trash, will be permanently removed. You will be signed out everywhere. This cannot be undone.</p>
                <flux:input name="current_password" type="password" label="Current password to delete account" autocomplete="current-password" required />
                <label class="flex min-h-11 items-start gap-3 text-sm leading-relaxed text-zinc-300">
                    <input type="checkbox" name="acknowledge_deletion" value="1" required class="mt-1 size-5 shrink-0 accent-emerald-400">
                    <span>I understand that deleting my account is permanent and cannot be undone.</span>
                </label>
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close><flux:button type="button" autofocus class="min-h-11">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" class="min-h-11" x-bind:disabled="submitting" :loading="false">
                        <span x-text="submitting ? 'Deleting account…' : 'Permanently delete account'">Permanently delete account</span>
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @else
        <p class="text-sm text-zinc-400">Account deletion is unavailable with this server's session configuration. Contact the service operator.</p>
    @endif
</section>
