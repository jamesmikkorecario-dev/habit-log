<?php

use App\Enums\HabitEntryStatus;
use App\Enums\HabitType;
use App\Livewire\Concerns\RequiresSameUser;
use App\Models\Habit;
use App\Services\Analytics\HabitScore;
use App\Services\TrackingDate;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Entry history')] class extends Component
{
    use RequiresSameUser;
    use WithPagination;

    #[Locked] public int $habitId;

    public function mount(int $habit): void { $this->habitId = $habit; }

    #[Computed]
    public function history(): array
    {
        // Keep the page's parent records, historical setup and child outcomes in one read snapshot.
        return DB::transaction(function () {
            $user = auth()->user()->fresh();
            $habit = $user->habits()->with('suspensions')->findOrFail($this->habitId);
            $this->authorize('view', $habit);
            $dates = app(TrackingDate::class);
            $scoring = app(HabitScore::class);
            $today = $user->localToday();
            $entries = $habit->entries()->where('user_id', $user->id)
                ->select(['id', 'habit_id', 'habit_tracking_version_id', 'entry_date', 'status', 'value'])
                ->with([
                    'version' => fn ($query) => $query->where('habit_id', $habit->id)
                        ->select(['id', 'habit_id', 'effective_date', 'start_date', 'end_date', 'type', 'goal_value', 'goal_unit'])
                        ->with('checkpoints:id,habit_tracking_version_id'),
                    'checkpoints:id,habit_entry_id,habit_tracking_checkpoint_id,is_completed',
                ])->orderByDesc('entry_date')->paginate(20);

            $entries->through(function ($entry) use ($habit, $user, $dates, $scoring) {
                $version = $entry->version;
                $date = $entry->entry_date->toDateString();
                $datedHabit = clone $habit;
                $datedHabit->setRelation('versions', collect($version ? [$version] : []));
                $reason = $dates->reason($user, $datedHabit, $date);
                $ids = $version?->checkpoints->modelKeys() ?? [];
                $actualIds = $entry->checkpoints->pluck('habit_tracking_checkpoint_id')->all();
                $valid = count($actualIds) === count($ids) && count(array_diff($actualIds, $ids)) === 0;
                $checked = $entry->checkpoints->where('is_completed', true)->count();
                $result = ! $version || $date < $version->effective_date->toDateString()
                    ? $scoring->state('unavailable')
                    : ($reason ? $scoring->state('inactive') : $scoring->calculate($version->type->value, $version->goal_value, $entry->status->value, $entry->value, $checked, count($ids), $valid));

                return [
                    'entry' => $entry, 'date' => $date, 'version' => $version, 'reason' => $reason,
                    'performance' => $result['score'] === null ? null : round($result['score'] * 100, 1),
                    'state' => $result['state'], 'checked' => $checked, 'total' => count($ids), 'validCheckpoints' => $valid && count($ids) >= 1 && count($ids) <= 20,
                    'na' => $entry->status === HabitEntryStatus::NotApplicable,
                    'assessment' => $version && in_array($version->type, [HabitType::Count, HabitType::Duration, HabitType::Avoidance], true),
                ];
            });

            return ['habit' => $habit, 'entries' => $entries, 'today' => $today, 'timezone' => $user->timezone, 'todayReason' => $dates->reason($user, $habit, $today)];
        });
    }
}; ?>

@php($history = $this->history)
@php($entries = $history['entries'])
<div class="min-w-0 space-y-6 sm:space-y-8" data-entry-history>
    <header class="flex flex-wrap items-start justify-between gap-5">
        <div class="min-w-0 flex-1">
            <x-terminal-path path="~/tracking/history" />
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Entry history</h1>
            <p class="mt-3 break-words text-lg font-medium text-zinc-200">{{ $history['habit']->name }}</p>
            <p class="mt-2 break-words text-sm text-zinc-400">{{ $history['timezone'] }} · Newest entries first</p>
        </div>
        <flux:button :href="route('habits.show', $habitId)" class="min-h-11 w-full sm:w-auto" icon="arrow-left" wire:navigate>Back to habit</flux:button>
    </header>

    <section aria-label="About this history" class="space-y-3 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 text-sm leading-relaxed text-zinc-400 sm:p-6">
        <p>Performance uses the tracking setup saved for each date. Personal assessment is your recorded judgment; it can differ from goal-based performance.</p>
        <p>Not applicable is neutral. Saved calendar dates stay unchanged when you change timezone. Private notes and reasons are only shown in your entry editor.</p>
        @if($history['habit']->archived_at)<p class="text-zinc-300">Archived habit · Your history is preserved. Earlier eligible dates can still be edited.</p>@endif
        <div class="flex flex-wrap gap-2 pt-1">
            @if(!$history['todayReason'])<flux:button :href="route('entries.edit', ['habit' => $habitId, 'date' => $history['today']])" class="min-h-11 w-full sm:w-auto" wire:navigate>Open today's entry</flux:button>@endif
            <flux:button :href="route('tracking.index')" variant="ghost" class="min-h-11 w-full sm:w-auto" wire:navigate>Daily tracking</flux:button>
        </div>
    </section>

    @if($entries->isEmpty())
        <section aria-labelledby="history-empty-heading" class="rounded-xl border border-zinc-800 bg-zinc-900/30 p-6 sm:p-8">
            <h2 id="history-empty-heading" class="text-2xl font-semibold">{{ $entries->total() > 0 ? 'No entries on this page.' : 'No recorded activity yet.' }}</h2>
            <p class="mt-3 max-w-xl text-sm leading-relaxed text-zinc-400">
                @if($entries->total() > 0) Your {{ $entries->total() }} saved entries are still available. Return to the first page to view them.
                @elseif(!$history['todayReason']) Start with today's entry. Nothing is recorded until you save.
                @else History will appear here after activity is saved for an eligible date. {{ $history['todayReason'] }}
                @endif
            </p>
            @if($entries->total() > 0)<flux:button wire:click="setPage(1)" class="mt-5 min-h-11" variant="primary">Back to first page</flux:button>@endif
        </section>
    @else
        <section aria-label="Saved entries" class="min-w-0 space-y-4">
            <p class="text-sm text-zinc-400" role="status">Showing {{ $entries->firstItem() }}–{{ $entries->lastItem() }} of {{ $entries->total() }} saved entries</p>
            @foreach($entries as $row)
                <article wire:key="history-entry-{{ $row['entry']->id }}" aria-labelledby="history-date-{{ $row['entry']->id }}" class="min-w-0 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6" data-history-entry>
                    <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h2 id="history-date-{{ $row['entry']->id }}" class="text-lg font-semibold"><time datetime="{{ $row['date'] }}">{{ $row['date'] }}</time></h2>
                            <p class="mt-1 text-sm text-zinc-400">{{ $row['version']?->type->label() ?? 'Tracking setup unavailable' }}</p>
                        </div>
                        <flux:button :href="route('entries.edit', ['habit' => $habitId, 'date' => $row['date']])" :aria-label="'View entry for '.$row['date']" class="min-h-11 w-full shrink-0 sm:w-auto" wire:navigate>View entry <span aria-hidden="true">→</span></flux:button>
                    </div>
                    <dl class="mt-5 grid min-w-0 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="min-w-0"><dt class="text-xs font-medium text-zinc-400">{{ $row['assessment'] ? 'Personal assessment' : 'Recorded outcome' }}</dt><dd class="mt-1 text-sm font-medium">{{ $row['entry']->status->label() }}</dd></div>
                        <div class="min-w-0"><dt class="text-xs font-medium text-zinc-400">Historical performance</dt><dd class="mt-1 font-mono text-lg {{ $row['performance'] !== null ? 'text-emerald-300' : 'text-zinc-400' }}" data-history-performance>{{ $row['performance'] !== null ? $row['performance'].'%' : ($row['na'] ? 'N/A · Neutral' : ($row['state'] === 'inactive' ? 'Neutral · Inactive date' : 'Unavailable')) }}</dd></div>
                        @if($row['version'] && $row['assessment'])
                            <div class="min-w-0"><dt class="text-xs font-medium text-zinc-400">{{ $row['version']->type === HabitType::Avoidance ? 'Historical maximum' : 'Historical goal' }}</dt><dd class="mt-1 break-words text-sm">{{ $row['version']->goal_value === null ? 'Not set · Uses personal assessment' : $row['version']->goal_value.' '.$row['version']->goal_unit }}</dd></div>
                            @unless($row['na'])<div class="min-w-0"><dt class="text-xs font-medium text-zinc-400">Recorded measurement</dt><dd class="mt-1 break-words text-sm" data-history-measurement>{{ $row['entry']->value === null ? 'Unavailable' : $row['entry']->value.' '.$row['version']->goal_unit }}</dd></div>@endunless
                        @elseif($row['version']?->type === HabitType::Checkpoint && !$row['na'])
                            <div class="min-w-0"><dt class="text-xs font-medium text-zinc-400">Checkpoint progress</dt><dd class="mt-1 text-sm" data-history-checkpoints>{{ $row['validCheckpoints'] ? $row['checked'].' of '.$row['total'].' completed' : 'Unavailable' }}</dd></div>
                        @endif
                    </dl>
                    @if($row['reason'])<p class="mt-4 text-sm leading-relaxed text-zinc-400">Read-only · {{ $row['reason'] }}</p>@endif
                </article>
            @endforeach
        </section>
        @if($entries->hasPages())
            <nav aria-label="Entry history pages" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-800 p-4">
                <p class="w-full text-sm text-zinc-400 sm:w-auto">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</p>
                <div class="flex flex-wrap gap-2">
                    <flux:button wire:click="previousPage" :disabled="$entries->onFirstPage()" class="min-h-11" aria-label="Previous history page" icon="chevron-left">Previous</flux:button>
                    <flux:button wire:click="nextPage" :disabled="!$entries->hasMorePages()" class="min-h-11" aria-label="Next history page" icon:trailing="chevron-right">Next</flux:button>
                </div>
            </nav>
        @endif
    @endif
</div>
