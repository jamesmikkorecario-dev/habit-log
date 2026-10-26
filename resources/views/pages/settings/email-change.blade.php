<section aria-labelledby="change-email" class="max-w-2xl space-y-6 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
    <div>
        <h2 id="change-email" class="text-xl font-semibold text-zinc-100">Change email</h2>
        <p class="mt-2 break-all text-sm text-zinc-300">Current login email: {{ auth()->user()->email }}</p>
        <p class="mt-2 text-sm leading-relaxed text-zinc-400">Your current address stays active for login and password recovery until you verify the new address and confirm with your password.</p>
    </div>
    @if(session('email-status'))
        <p role="status" tabindex="-1" class="rounded-lg border border-emerald-800 bg-emerald-950/30 p-4 text-sm text-emerald-300 focus-visible:outline-2 focus-visible:outline-emerald-300"
            x-data x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">{{ session('email-status') }}</p>
    @endif
    <x-email-change-errors />
    @if($pendingEmail)
        <div class="space-y-4 rounded-lg border border-zinc-700 p-4" aria-label="Pending email change">
            <h3 class="font-semibold">{{ $pendingEmail->expires_at->isPast() ? 'Expired email change' : 'Pending email change' }}</h3>
            <p class="break-all text-sm text-zinc-300">Proposed email: {{ $pendingEmail->new_email }}</p>
            <p class="text-sm text-zinc-400">Expires {{ $pendingEmail->expires_at->setTimezone(auth()->user()->timezone)->format('M j, Y H:i') }} · {{ auth()->user()->timezone }}. Only the latest link works. Wait one minute between requests. Availability is checked when you confirm.</p>
            <x-email-change-form :action="route('settings.email.resend')" label="Resend verification email">
                <input type="hidden" name="generation" value="{{ $pendingEmail->id }}">
                <flux:input name="current_password" label="Current password to resend" type="password" required autocomplete="current-password" viewable error:bag="emailResend" :invalid="$errors->emailResend->has('current_password')" />
            </x-email-change-form>
            <x-email-change-form :action="route('settings.email.cancel')" label="Cancel email change" variant="filled">
                <input type="hidden" name="generation" value="{{ $pendingEmail->id }}">
            </x-email-change-form>
        </div>
    @endif
    <x-email-change-form :action="route('settings.email.request')" :label="$pendingEmail ? 'Replace email request' : 'Send verification email'">
        <input type="hidden" name="generation" value="{{ $pendingEmail?->id ?? 'none' }}">
        <flux:input name="new_email" label="New email address" type="email" required maxlength="255" autocomplete="email" :value="old('new_email')" error:bag="emailRequest" :invalid="$errors->emailRequest->has('new_email')" />
        <flux:input name="current_password" label="Current password for email change" type="password" required autocomplete="current-password" viewable error:bag="emailRequest" :invalid="$errors->emailRequest->has('current_password')" />
        @if($pendingEmail)<p class="text-sm text-zinc-400">Replacing this request invalidates the previous link.</p>@endif
    </x-email-change-form>
</section>
