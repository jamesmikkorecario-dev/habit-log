<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    // Dashboard content will grow as features are added in later phases.
};

?>

<div>
    <p class="font-mono text-sm text-emerald-500" aria-hidden="true">~/dashboard</p>
    <h1 class="mt-3 text-2xl font-semibold tracking-tight text-zinc-100 sm:text-3xl">
        Welcome, {{ auth()->user()->name }}
    </h1>
    <p class="mt-3 text-base text-zinc-400">
        Foundation ready.
    </p>
</div>
