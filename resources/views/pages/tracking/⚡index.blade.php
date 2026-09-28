<?php

use App\Models\Habit;
use App\Services\TrackingDate;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Daily tracking')] class extends Component
{
    use WithPagination;
    #[Url]
    public string $date = '';
    public string $selectedDate = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Habit::class);
        $this->date = $this->date ?: auth()->user()->localToday();
        try { app(TrackingDate::class)->validate($this->date); } catch (\Illuminate\Validation\ValidationException) { abort(404); }
        $this->selectedDate = $this->date;
    }

    public function goToDate(): void
    {
        app(TrackingDate::class)->validate($this->selectedDate);
        $this->date = $this->selectedDate;
        $this->resetPage();
    }

    public function shiftDate(int $days): void
    {
        abort_unless(in_array($days, [-1, 1], true), 422);
        app(TrackingDate::class)->validate($this->date);
        $target = Carbon::parse($this->date)->addDays($days)->toDateString();
        app(TrackingDate::class)->validate($target);
        if ($target > auth()->user()->fresh()->localToday()) { return; }
        $this->date = $this->selectedDate = $target;
        $this->resetPage();
    }

    public function today(): void
    {
        $this->date = $this->selectedDate = auth()->user()->fresh()->localToday();
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        $this->authorize('viewAny', Habit::class);
        app(TrackingDate::class)->validate($this->date);
        $user = auth()->user()->fresh();
        $rows = $user->habits()->with([
            'category', 'versions' => fn ($query) => $query->where('effective_date', '<=', $this->date)->orderByDesc('effective_date')->limit(1),
            'suspensions' => fn ($query) => $query->where('start_date', '<=', $this->date)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $this->date)),
            'entries' => fn ($query) => $query->where('entry_date', $this->date)->select(['id', 'habit_id', 'entry_date', 'status', 'value']),
        ])->orderBy('name')->orderBy('id')->paginate(20);
        return $rows->through(fn ($habit) => [
            'habit' => $habit, 'entry' => $habit->entries->first(),
            'reason' => app(TrackingDate::class)->reason($user, $habit, $this->date),
        ]);
    }
}; ?>

<div class="space-y-6">
    <div><h1 class="text-2xl font-semibold">Daily tracking</h1><p class="mt-2 text-sm text-zinc-400">{{ $date }} · {{ auth()->user()->timezone }}. Dates without an entry are not logged.</p></div>
    <div class="flex flex-wrap gap-2">
        <flux:button wire:click="shiftDate(-1)" :disabled="$date <= '1000-01-01'">Previous day</flux:button>
        <flux:button wire:click="today">Today</flux:button>
        <flux:button wire:click="shiftDate(1)" :disabled="$date >= auth()->user()->localToday()">Next day</flux:button>
    </div>
    <form wire:submit="goToDate" class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="selectedDate" type="date" label="Tracking date" min="1000-01-01" :max="auth()->user()->localToday()" />
        <flux:button type="submit">Go to date</flux:button>
    </form>
    <flux:error name="date" />
    <div class="space-y-3">
        @forelse($this->rows as $row)
            <article class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-900/50 p-4" wire:key="daily-{{ $row['habit']->id }}">
                <div class="min-w-0"><h2 class="break-words font-medium">{{ $row['habit']->name }}</h2><p class="text-sm text-zinc-400">{{ $row['entry']?->status->label() ?? 'Not logged' }}</p>@if($row['reason'])<p class="text-sm text-zinc-400">{{ $row['reason'] }}</p>@endif</div>
                <div class="flex flex-wrap gap-2">
                    @if(!$row['reason'])<flux:button :href="route('entries.edit', ['habit' => $row['habit']->id, 'date' => $date])" wire:navigate>{{ $row['entry'] ? 'Edit entry' : 'Record activity' }}</flux:button>@endif
                    <flux:button :href="route('habits.show', $row['habit'])" wire:navigate>Habit</flux:button>
                </div>
            </article>
        @empty
            <p>No habits yet. <flux:link :href="route('habits.create')" wire:navigate>Create a habit</flux:link> to start recording.</p>
        @endforelse
    </div>
    {{ $this->rows->links() }}
</div>
