@props(['action', 'label', 'variant' => 'primary'])
<form method="POST" action="{{ $action }}" aria-label="{{ $label }}" class="space-y-5"
    x-data="{ submitting: false }" x-on:submit="if (submitting) $event.preventDefault(); else submitting = true" x-bind:aria-busy="submitting"
    x-on:pageshow.window="submitting = false; if ($event.persisted) $el.reset()">
    @csrf
    {{ $slot }}
    <flux:button type="submit" :variant="$variant" class="min-h-11 w-full sm:w-auto" x-bind:disabled="submitting" :loading="false">
        <span x-show="!submitting">{{ $label }}</span><span x-show="submitting" x-cloak>Submitting…</span>
    </flux:button>
</form>
