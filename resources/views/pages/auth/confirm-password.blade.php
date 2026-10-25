<x-layouts::auth.split :title="__('Confirm password')">
    <div class="flex flex-col gap-6">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">{{ __('Account security') }}</p>
        <x-auth-header :title="__('Confirm your password')" :description="__('Enter your current password to continue securely.')" />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6"
            x-data="{ submitting: false }" x-on:submit="submitting = true" x-on:pageshow.window="submitting = false" x-bind:aria-busy="submitting">
            @csrf
            @if ($errors->any())
                <p role="alert" class="rounded-md border border-red-800 bg-red-950/30 p-3 text-sm text-red-300">{{ __('Please check the highlighted field and try again.') }}</p>
            @endif
            <flux:input name="password" :label="__('Password')" type="password" required autofocus autocomplete="current-password" viewable />
            <flux:button variant="primary" type="submit" class="min-h-12 w-full" x-bind:disabled="submitting" :loading="false">
                <span x-text="submitting ? @js(__('Confirming…')) : @js(__('Confirm password'))">{{ __('Confirm password') }}</span>
            </flux:button>
        </form>
        <div class="text-center text-sm">
            <flux:link class="inline-flex min-h-11 items-center" :href="route('dashboard')" wire:navigate>{{ __('Return to dashboard') }}</flux:link>
        </div>
    </div>
</x-layouts::auth.split>
