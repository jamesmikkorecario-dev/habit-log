<x-layouts::auth.split :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">{{ __('Account recovery') }}</p>
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <!-- Session Status -->
        <x-auth-session-status class="rounded-md border border-emerald-800 bg-emerald-950/40 p-3 text-emerald-300!" role="status" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
            @csrf
            @if ($errors->any())
                <p role="alert" class="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm text-red-300">{{ __('Please check the highlighted fields and try again.') }}</p>
            @endif
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                :value="old('email', request('email'))"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="min-h-12 w-full" x-bind:disabled="submitting" :loading="false" data-test="reset-password-button">
                    <span x-text="submitting ? @js(__('Resetting…')) : @js(__('Reset password'))">{{ __('Reset password') }}</span>
                </flux:button>
            </div>
        </form>
        <div class="text-center text-sm">
            <flux:link class="inline-flex min-h-11 items-center" :href="route('login')" wire:navigate>{{ __('Return to log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth.split>
