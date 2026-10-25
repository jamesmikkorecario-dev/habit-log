<x-layouts::auth.split :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">{{ __('Account recovery') }}</p>
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <!-- Session Status -->
        <x-auth-session-status class="rounded-md border border-emerald-800 bg-emerald-950/40 p-3 text-emerald-300!" role="status" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
            @csrf
            @if ($errors->any())
                <p role="alert" class="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm text-red-300">{{ __('Please check the highlighted fields and try again.') }}</p>
            @endif

            <!-- Email Address -->
            <flux:input
                name="email"
                :value="old('email')"
                autocomplete="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="min-h-12 w-full" x-bind:disabled="submitting" :loading="false" data-test="email-password-reset-link-button">
                <span x-text="submitting ? @js(__('Sending…')) : @js(__('Email password reset link'))">{{ __('Email password reset link') }}</span>
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link class="inline-flex min-h-11 items-center" :href="route('login')" wire:navigate>{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth.split>
