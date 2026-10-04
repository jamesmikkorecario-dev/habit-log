<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    // Analytics remains scoped to the authenticated owner.
};

?>

<div class="min-w-0 space-y-6">
    <p class="font-mono text-sm text-emerald-500" aria-hidden="true">~/dashboard</p>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight text-zinc-100 sm:text-3xl">
        Welcome, {{ auth()->user()->displayName() }}
    </h1>
    <p class="mt-3 text-base text-zinc-400">
        Make room for the habits that matter to you.
    </p>
    <div class="mt-6 flex flex-wrap gap-3">
        <flux:button :href="route('tracking.index')" variant="primary" wire:navigate>Track today</flux:button>
        <flux:button :href="route('journal.index')" wire:navigate>Write in journal</flux:button>
        <flux:button :href="route('habits.index')" wire:navigate>Your habits</flux:button>
        <flux:button :href="route('habits.create')" wire:navigate>Create a habit</flux:button>
    </div>
    <livewire:activity-heatmap />
</div>
