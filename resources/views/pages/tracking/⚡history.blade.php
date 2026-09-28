<?php

use App\Models\Habit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Entry history')] class extends Component
{
    use WithPagination;
    #[Locked] public int $habitId;
    public function mount(int $habit): void { $this->habitId = $habit; $this->habit; }
    #[Computed]
    public function habit(): Habit
    {
        $habit = auth()->user()->habits()->findOrFail($this->habitId);
        $this->authorize('view', $habit);
        return $habit;
    }
    #[Computed]
    public function entries()
    {
        return $this->habit->entries()->select(['id', 'habit_id', 'habit_tracking_version_id', 'entry_date', 'status', 'value'])->with('version')->orderByDesc('entry_date')->paginate(20);
    }
}; ?>

<div class="space-y-6">
    <h1 class="break-words text-2xl font-semibold">{{ $this->habit->name }} · Entry history</h1>
    <p class="text-sm text-zinc-400">Saved calendar dates stay unchanged when you change timezone. Notes and reasons are available in the private entry editor.</p>
    @forelse($this->entries as $entry)
        <article class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-900/50 p-4">
            <div><h2 class="font-medium">{{ $entry->entry_date->toDateString() }}</h2><p>{{ $entry->status->label() }} · {{ $entry->version->type->label() }}@if($entry->value !== null && $entry->status->value !== 'not_applicable') · {{ $entry->value }} {{ $entry->version->goal_unit }}@endif</p></div>
            <flux:button :href="route('entries.edit', ['habit' => $habitId, 'date' => $entry->entry_date->toDateString()])" wire:navigate>View entry</flux:button>
        </article>
    @empty<p>No recorded activity yet.</p>@endforelse
    {{ $this->entries->links() }}
    <flux:button :href="route('habits.show', $habitId)" wire:navigate>Back to habit</flux:button>
</div>
