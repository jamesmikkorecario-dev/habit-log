<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Models\Habit;
use App\Services\TrackingDate;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    use RequiresSameUser;

    #[Computed]
    public function today(): array
    {
        $this->authorize('viewAny', Habit::class);

        // Keep date, setup and entry reads consistent without locking activity writes.
        return DB::transaction(function () {
            $user = auth()->user()->fresh();
            $date = $user->localToday();
            $tracking = app(TrackingDate::class);
            $summary = [
                'date' => $date, 'timezone' => $user->timezone, 'name' => $user->displayName(),
                'hasHabits' => $user->habits()->withTrashed()->exists(),
                'included' => 0, 'eligible' => 0, 'logged' => 0, 'rows' => [],
            ];
            $user->habits()->select(['id', 'user_id', 'category_id', 'name', 'archived_at', 'deleted_at'])
                ->with([
                    'category:id,name',
                    'versions' => fn ($q) => $q->where('effective_date', '<=', $date)->latest('effective_date')->limit(1),
                    'suspensions' => fn ($q) => $q->where('start_date', '<=', $date)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $date)),
                    'entries' => fn ($q) => $q->where('entry_date', $date)->select(['id', 'habit_id', 'entry_date', 'status', 'value'])
                        ->withCount(['checkpoints', 'checkpoints as completed_checkpoints_count' => fn ($q) => $q->where('is_completed', true)]),
                ])->chunkById(100, function ($habits) use (&$summary, $user, $date, $tracking) {
                    foreach ($habits as $habit) {
                        $summary['included']++;
                        if ($tracking->reason($user, $habit, $date) !== null) {
                            continue;
                        }
                        $summary['eligible']++;
                        $entry = $habit->entries->first();
                        $summary['logged'] += $entry !== null ? 1 : 0;
                        if (count($summary['rows']) < 6) {
                            $summary['rows'][] = ['habit' => $habit, 'entry' => $entry, 'setup' => $tracking->version($habit, $date)];
                        }
                    }
                });

            return $summary;
        });
    }
};

?>

@php($today = $this->today)
<div class="min-w-0 space-y-8 sm:space-y-10">
    <header class="flex flex-col justify-between gap-6 xl:flex-row xl:items-end">
        <div class="min-w-0">
            <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">Your daily space</p>
            <h1 class="mt-3 break-words text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Welcome back, {{ $today['name'] }}.</h1>
            <p class="mt-3 text-zinc-400">A little progress, every day.</p>
            <p class="mt-3 font-mono text-xs text-zinc-400"><time datetime="{{ $today['date'] }}">{{ $today['date'] }}</time> · {{ $today['timezone'] }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if($today['hasHabits'])
                <flux:button :href="route('tracking.index', ['date' => $today['date']])" variant="primary" icon:trailing="arrow-right" class="min-h-11" wire:navigate>Track today</flux:button>
                <flux:button :href="route('habits.index')" variant="ghost" class="min-h-11" wire:navigate>View habits</flux:button>
                <flux:button :href="route('journal.index')" variant="ghost" class="min-h-11" wire:navigate>Write in journal</flux:button>
            @else
                <flux:button :href="route('habits.create')" variant="primary" icon:trailing="plus" class="min-h-11" wire:navigate>Create your first habit</flux:button>
            @endif
        </div>
    </header>

    <section aria-labelledby="today-heading" class="min-w-0 rounded-xl border border-zinc-800 bg-zinc-900/30">
        @if($today['eligible'] > 0)
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-800 p-5 sm:p-6">
                <div><h2 id="today-heading" class="text-xl font-semibold">Today's habits</h2><p class="mt-1 text-sm text-zinc-400">A check-in, at your pace.</p></div>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm" aria-label="Today's recording summary">
                    <p><span class="font-mono text-lg font-semibold text-emerald-300">{{ $today['logged'] }}</span> <span class="text-zinc-400">logged today</span></p>
                    <p><span class="font-mono text-lg font-semibold">{{ $today['eligible'] - $today['logged'] }}</span> <span class="text-zinc-400">awaiting a check-in</span></p>
                </div>
            </header>
            <ul class="divide-y divide-zinc-800 px-5 sm:px-6">
                @foreach($today['rows'] as $row)
                    <li class="flex flex-col justify-between gap-4 py-5 sm:flex-row sm:items-center" data-today-habit="{{ $row['habit']->id }}" wire:key="today-{{ $row['habit']->id }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('habits.show', $row['habit']) }}" class="break-words rounded font-medium hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300" wire:navigate>{{ $row['habit']->name }}</a>
                            <p class="mt-1 break-words text-sm text-zinc-400">{{ $row['habit']->category->name }} · {{ $row['setup']->type->label() }}{{ $row['habit']->archived_at ? ' · Archived; today is still eligible' : '' }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 sm:justify-end sm:gap-5">
                            <div class="text-sm sm:text-right">
                                <p @class(['font-medium', 'text-emerald-300' => $row['entry']?->status === \App\Enums\HabitEntryStatus::Completed, 'text-zinc-300' => $row['entry']?->status !== \App\Enums\HabitEntryStatus::Completed])>{{ $row['entry']?->status->label() ?? 'Not logged' }}</p>
                                @if($row['entry'] && $row['entry']->status !== \App\Enums\HabitEntryStatus::NotApplicable)
                                    @if($row['setup']->type === \App\Enums\HabitType::Checkpoint)
                                        <p class="mt-1 text-xs text-zinc-400">{{ $row['entry']->completed_checkpoints_count }} of {{ $row['entry']->checkpoints_count }} checkpoints</p>
                                    @elseif($row['setup']->type !== \App\Enums\HabitType::Boolean)
                                        <p class="mt-1 text-xs text-zinc-400">{{ $row['entry']->value ?? 'No measurement' }} {{ $row['setup']->goal_unit }}@if($row['setup']->goal_value !== null) · {{ $row['setup']->type === \App\Enums\HabitType::Avoidance ? 'Limit' : 'Goal' }} {{ $row['setup']->goal_value }}@endif</p>
                                    @endif
                                @endif
                            </div>
                            <flux:button :href="route('entries.edit', [$row['habit'], $today['date']])" class="min-h-11" :aria-label="($row['entry'] ? 'Edit check-in for ' : 'Check in for ').$row['habit']->name" wire:navigate>{{ $row['entry'] ? 'Edit check-in' : 'Check in' }}</flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-800 px-5 py-4 sm:px-6">
                <p class="max-w-xl text-xs leading-relaxed text-zinc-400">Showing {{ count($today['rows']) }} of {{ $today['eligible'] }} eligible habits. Logged includes N/A and recorded outcomes; it does not mean every goal was met.</p>
                <flux:button :href="route('tracking.index', ['date' => $today['date']])" variant="ghost" class="min-h-11" wire:navigate>View all today's habits <span aria-hidden="true">→</span></flux:button>
            </footer>
        @else
            <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:p-8">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-lg border border-emerald-800 bg-emerald-950/40 text-emerald-300"><flux:icon.sparkles class="size-6" aria-hidden="true" /></span>
                <div class="max-w-2xl">
                    <h2 id="today-heading" class="text-2xl font-semibold tracking-tight">{{ !$today['hasHabits'] ? 'Your journey starts here.' : 'A little breathing room.' }}</h2>
                    <p class="mt-3 leading-relaxed text-zinc-400">
                        @if(!$today['hasHabits']) Every meaningful change begins with one small habit. Create your first habit and start building consistency.
                        @elseif($today['included'] === 0) Your habits are in Trash. Restore one to bring its eligible history back into your activity overview.
                        @else Nothing is eligible for tracking today. Your habits may be scheduled for another date, ended, or paused by an archive. Your eligible history is still included below.
                        @endif
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        @if(!$today['hasHabits'])
                            <flux:button :href="route('tracking.index')" class="min-h-11" wire:navigate>Explore habit tracking</flux:button>
                        @elseif($today['included'] === 0)
                            <flux:button :href="route('habits.index', ['tab' => 'trash'])" class="min-h-11" wire:navigate>Review Trash</flux:button>
                        @else
                            <flux:button :href="route('habits.create')" class="min-h-11" wire:navigate>Create a habit</flux:button>
                        @endif
                        @if(!$today['hasHabits'])<flux:button :href="route('journal.index')" variant="ghost" class="min-h-11" wire:navigate>Write in journal</flux:button>@endif
                    </div>
                </div>
            </div>
        @endif
    </section>

    <livewire:activity-heatmap />
</div>
