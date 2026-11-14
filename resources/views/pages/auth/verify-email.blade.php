<x-layouts::auth.split :title="__('Verify your email')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Verify your email')" :description="__('Confirm your email address to start using habit.log.')" />

        @if (isset($verificationError))
            <p role="alert" class="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm text-red-300">{{ __($verificationError) }}</p>
        @endif

        @if (auth()->user()->hasVerifiedEmail())
            <p class="text-sm leading-relaxed text-zinc-300">{{ __('Your email address is already verified.') }}</p>
            <flux:button variant="primary" :href="route('dashboard')" class="min-h-12 w-full">{{ __('Continue to dashboard') }}</flux:button>
        @else
            <div class="space-y-3 text-sm leading-relaxed text-zinc-300">
                <p>{{ __('Open the verification email sent to:') }}</p>
                <p class="[overflow-wrap:anywhere] font-medium text-zinc-100">{{ auth()->user()->email }}</p>
                <p class="text-zinc-400">{{ __('The link expires after :minutes minutes. Check your spam folder, or request another email below.', ['minutes' => config('auth.verification.expire', 60)]) }}</p>
            </div>

            @if (session('status') === \Laravel\Fortify\Fortify::VERIFICATION_LINK_SENT)
                <p role="status" tabindex="-1" x-data x-init="$el.focus()" class="rounded-md border border-emerald-800 bg-emerald-950/40 p-3 text-sm text-emerald-300">{{ __('A new verification link has been sent to your email address.') }}</p>
            @endif

            <form method="POST" action="{{ route('verification.send') }}" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
                @csrf
                <flux:button variant="primary" type="submit" class="min-h-12 w-full" x-bind:disabled="submitting" :loading="false">
                    <span x-text="submitting ? @js(__('Sending…')) : @js(__('Resend verification email'))">{{ __('Resend verification email') }}</span>
                </flux:button>
            </form>
            <p class="text-sm leading-relaxed text-zinc-400">{{ __('Too many requests? Wait one minute before trying again. If this is the wrong email address, log out and register with the correct address.') }}</p>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button variant="ghost" type="submit" class="min-h-11 w-full">{{ __('Log out') }}</flux:button>
        </form>
    </div>
</x-layouts::auth.split>
