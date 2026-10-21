<x-layouts::auth.split :title="__('Register')">
    <div class="flex flex-col gap-6">
        <div>
            <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">{{ __('Your first step') }}</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ __('Create your account.') }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ __('Create your account. Start with one small habit.') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="rounded-md border border-emerald-800 bg-emerald-950/40 p-3 text-emerald-300!" role="status" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
            @csrf

            @if ($errors->any())
                <p role="alert" class="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm text-red-300">{{ __('Please check the highlighted fields and try again.') }}</p>
            @endif
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
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
                <flux:button type="submit" variant="primary" class="min-h-12 w-full" x-bind:disabled="submitting" :loading="false" data-test="register-user-button">
                    <span x-text="submitting ? @js(__('Creating account…')) : @js(__('Create account'))">{{ __('Create account') }}</span>
                </flux:button>
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-center gap-x-2 border-t border-zinc-800 pt-4 text-sm text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link class="inline-flex min-h-11 items-center" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth.split>
