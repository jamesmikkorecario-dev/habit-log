<?php

use App\Models\Habit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Habit')] class extends Component
{
    #[Locked]
    public int $habitId;

    public function mount(int $habit): void
    {
        $this->habitId = $habit;
        $this->habit;
    }

    #[Computed]
    public function trackingSetup(): array
    {
        if ($this->habitId === null) { return ['current' => null, 'pending' => null]; }
        $habit = auth()->user()->habits()->findOrFail($this->habitId);
        $this->authorize('view', $habit);
        $today = auth()->user()->fresh()->localToday();
        return [
            'current' => $habit->versions()->with('checkpoints')->where('effective_date', '<=', $today)->latest('effective_date')->first(),
            'pending' => $habit->entries()->exists() ? $habit->versions()->where('effective_date', '>', $today)->latest('effective_date')->first() : null,
        ];
    }

    #[Computed]
    public function habit(): Habit
    {
        $habit = auth()->user()->habits()->with(['category', 'checkpoints'])->findOrFail($this->habitId);
        $this->authorize('view', $habit);
        return $habit;
    }
}; ?>

<div class="min-w-0 space-y-6">
    @if(session('status'))<p role="status" class="text-emerald-300">{{ session('status') }}</p>@endif
    <div class="flex flex-wrap items-start justify-between gap-4">
        <h1 class="min-w-0 break-words text-2xl font-semibold">{{ $this->habit->name }}</h1>
        <flux:button :href="route('habits.edit', $habitId)" wire:navigate>Edit habit</flux:button>
    </div>
    @if($this->habit->description !== null)<p class="whitespace-pre-wrap break-words text-zinc-300">{{ $this->habit->description }}</p>@endif
    <livewire:activity-heatmap :habit-id="$habitId" />
    <x-tracking-setup :current="$this->trackingSetup['current']" :pending="$this->trackingSetup['pending']" />
    <h2 class="font-medium">Latest requested configuration</h2>
    <dl class="grid gap-4 rounded-xl border border-emerald-900/50 p-5 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-400">Category</dt><dd class="break-words">{{ $this->habit->category->name }}</dd></div>
        <div><dt class="text-sm text-zinc-400">Type</dt><dd>{{ $this->habit->type->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-400">State</dt><dd>{{ $this->habit->archived_at ? 'Archived' : 'Active' }}</dd></div>
        <div><dt class="text-sm text-zinc-400">Visibility preference</dt><dd>{{ $this->habit->visibility->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-400">Start date</dt><dd>{{ $this->habit->start_date->toDateString() }}</dd></div>
        <div><dt class="text-sm text-zinc-400">End date</dt><dd>{{ $this->habit->end_date?->toDateString() ?? 'No end date' }}</dd></div>
        @if(in_array($this->habit->type->value, ['count', 'duration', 'avoidance'], true))
            <div><dt class="text-sm text-zinc-400">{{ $this->habit->type->value === 'avoidance' ? 'Maximum' : 'Goal' }}</dt><dd class="break-words">{{ $this->habit->goal_value ?? 'No goal' }} {{ $this->habit->goal_unit }}</dd></div>
        @endif
    </dl>
    @if($this->habit->type->value === 'checkpoint')
        <section class="space-y-3" aria-labelledby="checkpoint-heading">
            <h2 id="checkpoint-heading" class="text-lg font-medium">Checkpoints</h2>
            <ol class="list-inside list-decimal space-y-2">@foreach($this->habit->checkpoints as $checkpoint)<li class="break-words">{{ $checkpoint->label }}</li>@endforeach</ol>
        </section>
    @endif
    <p class="text-sm text-zinc-400">Only you can access this habit and its daily activity. Sharing will be available in a later update.</p>
    <div class="flex flex-wrap gap-2">
        <flux:button :href="route('entries.edit', ['habit' => $habitId, 'date' => auth()->user()->localToday()])" wire:navigate>Record today</flux:button>
        <flux:button :href="route('entries.index', $habitId)" wire:navigate>Entry history</flux:button>
    </div>
    <flux:button :href="route('habits.index')" wire:navigate>Back to habits</flux:button>
</div>
