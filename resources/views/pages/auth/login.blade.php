<x-layouts::auth.split :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <div>
            <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">{{ __('Your daily log') }}</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ __('Welcome back.') }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ __('Log in to pick up where you left off.') }}</p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="rounded-md border border-emerald-800 bg-emerald-950/40 p-3 text-emerald-300!" role="status" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5" x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
            @csrf

            @if ($errors->any())
                <p role="alert" class="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm text-red-300">{{ __('Please check the highlighted fields and try again.') }}</p>
            @endif

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />
                @if (Route::has('password.request'))
                    <flux:link class="inline-flex min-h-11 items-center text-sm" :href="route('password.request')" wire:navigate>{{ __('Forgot your password?') }}</flux:link>
                @endif
            </div>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="min-h-12 w-full" x-bind:disabled="submitting" :loading="false" data-test="login-button">
                    <span x-text="submitting ? @js(__('Logging in…')) : @js(__('Log in'))">{{ __('Log in') }}</span>
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="flex flex-wrap items-center justify-center gap-x-2 border-t border-zinc-800 pt-4 text-sm text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link class="inline-flex min-h-11 items-center" :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth.split>
