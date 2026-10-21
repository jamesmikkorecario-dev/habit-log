<?php

use App\Livewire\Concerns\RequiresSameUser;
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
    use RequiresSameUser;

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
            'entries' => fn ($query) => $query->where('entry_date', $this->date)->select(['id', 'habit_id', 'entry_date', 'status', 'value'])
                ->withCount(['checkpoints', 'checkpoints as completed_checkpoints_count' => fn ($query) => $query->where('is_completed', true)]),
        ])->orderBy('name')->orderBy('id')->paginate(20);
        return $rows->through(fn ($habit) => [
            'habit' => $habit, 'entry' => $habit->entries->first(),
            'reason' => app(TrackingDate::class)->reason($user, $habit, $this->date),
            'setup' => app(TrackingDate::class)->version($habit, $this->date),
        ]);
    }

    #[Computed]
    public function overview(): array
    {
        $rows = $this->rows;
        $eligible = $rows->getCollection()->whereNull('reason');
        $user = auth()->user()->fresh();

        return [
            'today' => $user->localToday(), 'timezone' => $user->timezone,
            'hasHabits' => $rows->total() > 0 || $user->habits()->withTrashed()->exists(),
            'eligible' => $eligible->count(),
            'logged' => $eligible->filter(fn ($row) => $row['entry'] !== null)->count(),
            'unavailable' => $rows->count() - $eligible->count(),
        ];
    }
}; ?>

@php
    $rows = $this->rows;
    $overview = $this->overview;
@endphp
<div class="min-w-0 space-y-6 sm:space-y-8">
    <header>
        <x-terminal-path path="~/tracking" />
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Daily tracking</h1>
        <p class="mt-3 text-zinc-400">Show up for yourself, one day at a time.</p>
    </header>

    <section aria-label="Choose a tracking date" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div aria-live="polite" aria-atomic="true">
                <p class="text-xs font-medium text-zinc-400">Selected day{{ $date === $overview['today'] ? ' · Today' : '' }}</p>
                <p class="mt-2 text-xl font-semibold sm:text-2xl"><time datetime="{{ $date }}" data-tracking-date>{{ \Illuminate\Support\Carbon::parse($date)->format('l, F j, Y') }}</time></p>
                <p class="mt-2 break-words font-mono text-xs text-zinc-400">{{ $date }} · {{ $overview['timezone'] }}</p>
            </div>
            <div class="flex flex-wrap gap-2" role="group" aria-label="Day navigation">
                <flux:button wire:click="shiftDate(-1)" aria-label="Previous day" class="min-h-11" icon="chevron-left" :disabled="$date <= '1000-01-01'">Previous</flux:button>
                <flux:button wire:click="today" class="min-h-11">Today</flux:button>
                <flux:button wire:click="shiftDate(1)" aria-label="Next day" class="min-h-11" icon:trailing="chevron-right" :disabled="$date >= $overview['today']">Next</flux:button>
            </div>
        </div>
        <form wire:submit="goToDate" class="flex min-w-0 flex-wrap items-end gap-3 border-t border-zinc-800 pt-5">
            <div class="min-w-0 w-full sm:w-56">
                <flux:input wire:model="selectedDate" type="date" label="Tracking date" min="1000-01-01" :max="$overview['today']" class="min-w-0" />
            </div>
            <flux:button type="submit" class="min-h-11">Go to date</flux:button>
            <p class="w-full text-xs leading-relaxed text-zinc-400">Choose a date, then select Go to date. Today follows your timezone in Settings.</p>
        </form>
        <flux:error name="date" />
    </section>

    @if($rows->isEmpty())
        <section aria-labelledby="tracking-empty-heading" class="rounded-xl border border-zinc-800 bg-zinc-900/30 p-6 sm:p-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300"><flux:icon.calendar-days class="size-6" aria-hidden="true" /></span>
                <div class="max-w-xl">
                    <h2 id="tracking-empty-heading" class="text-2xl font-semibold tracking-tight">{{ !$overview['hasHabits'] ? 'Nothing to track just yet.' : ($rows->total() === 0 ? 'Your habits are in Trash.' : 'No habits on this page.') }}</h2>
                    <p class="mt-3 leading-relaxed text-zinc-400">
                        @if(!$overview['hasHabits']) Create your first habit and start recording your progress, one day at a time.
                        @elseif($rows->total() === 0) Restore a habit to bring it back to daily tracking. Its saved history will still be there.
                        @else Return to the first page to see your habits for this date.
                        @endif
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        @if(!$overview['hasHabits'])
                            <flux:button :href="route('habits.create')" variant="primary" icon:trailing="plus" class="min-h-11" wire:navigate>Create your first habit</flux:button>
                        @elseif($rows->total() === 0)
                            <flux:button :href="route('habits.index', ['tab' => 'trash'])" variant="primary" class="min-h-11" wire:navigate>Review Trash</flux:button>
                        @else
                            <flux:button wire:click="setPage(1)" class="min-h-11">Back to first page</flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @else
        <section aria-labelledby="daily-habits-heading" class="min-w-0 space-y-4">
            <header class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 id="daily-habits-heading" class="text-xl font-semibold">Your habits</h2><p class="mt-1 text-sm text-zinc-400">A moment to check in, at your pace.</p></div>
                <p class="text-xs text-zinc-400">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} habits</p>
            </header>
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/30 p-4 sm:p-5" aria-label="Recording summary for this page">
                <p class="font-mono text-xs uppercase tracking-widest text-zinc-400">On this page</p>
                <dl class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div><dt class="text-sm text-zinc-400">Recorded</dt><dd class="mt-1 font-mono text-2xl font-semibold text-emerald-300">{{ $overview['logged'] }}</dd></div>
                    <div><dt class="text-sm text-zinc-400">Ready for a check-in</dt><dd class="mt-1 font-mono text-2xl font-semibold">{{ $overview['eligible'] - $overview['logged'] }}</dd></div>
                    <div class="col-span-2 sm:col-span-1"><dt class="text-sm text-zinc-400">Not available for this date</dt><dd class="mt-1 font-mono text-2xl font-semibold">{{ $overview['unavailable'] }}</dd></div>
                </dl>
                <p class="mt-4 text-xs leading-relaxed text-zinc-400">Recorded counts entries for habits available on this date, including missed and N/A outcomes. It does not measure goal completion.</p>
            </div>
            @if($overview['eligible'] === 0)
                <p class="rounded-lg border border-zinc-800 px-4 py-3 text-sm leading-relaxed text-zinc-400" role="status">No habits on this page can be recorded for this date. Check each habit’s dates or archive period below, or choose another day. Saved activity remains visible.</p>
            @endif
            <div class="space-y-3">
                @foreach($rows as $row)
                    <article class="min-w-0 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6" wire:key="daily-{{ $row['habit']->id }}" data-tracking-habit="{{ $row['habit']->id }}" aria-labelledby="daily-habit-{{ $row['habit']->id }}">
                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div class="min-w-0 flex-1">
                                <h3 id="daily-habit-{{ $row['habit']->id }}" class="break-words text-lg font-semibold">{{ $row['habit']->name }}</h3>
                                <p class="mt-1 break-words text-sm text-zinc-400">{{ $row['habit']->category->name }} · {{ $row['setup']?->type->label() ?? 'No tracking setup for this date' }}{{ $row['habit']->archived_at ? ' · Archived' : '' }}</p>
                            </div>
                            <div class="min-w-0 sm:max-w-xs sm:text-right">
                                <p class="text-sm font-medium text-zinc-200">{{ $row['entry'] ? 'Recorded outcome: '.$row['entry']->status->label() : ($row['reason'] ? 'Not available for this date' : ($date === $overview['today'] ? 'Not logged · Pending today' : 'Not logged')) }}</p>
                                @if($row['entry'] && $row['entry']->status !== \App\Enums\HabitEntryStatus::NotApplicable && $row['setup'])
                                    @if($row['setup']->type === \App\Enums\HabitType::Checkpoint)
                                        <p class="mt-2 text-sm text-zinc-400">{{ $row['entry']->checkpoints_count > 0 ? $row['entry']->completed_checkpoints_count.' of '.$row['entry']->checkpoints_count.' checkpoints' : 'Checkpoint progress unavailable' }}</p>
                                    @elseif($row['setup']->type !== \App\Enums\HabitType::Boolean)
                                        <p class="mt-2 break-words text-sm text-zinc-400">Recorded value: {{ $row['entry']->value ?? 'No measurement recorded' }} {{ $row['setup']->goal_unit }}@if($row['setup']->goal_value !== null) · {{ $row['setup']->type === \App\Enums\HabitType::Avoidance ? 'Limit' : 'Goal' }} {{ $row['setup']->goal_value }}@endif</p>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @if($row['reason'])<p class="mt-4 text-sm leading-relaxed text-zinc-400">{{ $row['reason'] }}</p>@endif
                        <div class="mt-5 flex flex-wrap gap-2">
                            @if(!$row['reason'])<flux:button :href="route('entries.edit', ['habit' => $row['habit']->id, 'date' => $date])" :variant="$row['entry'] ? 'outline' : 'primary'" class="min-h-11" :aria-label="($row['entry'] ? 'Edit entry for ' : 'Record activity for ').$row['habit']->name" wire:navigate>{{ $row['entry'] ? 'Edit entry' : 'Record activity' }}</flux:button>@endif
                            <flux:button :href="route('habits.show', $row['habit'])" variant="ghost" class="min-h-11" :aria-label="'View habit '.$row['habit']->name" wire:navigate>View habit <span aria-hidden="true">→</span></flux:button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
    {{ $rows->links() }}
</div>
