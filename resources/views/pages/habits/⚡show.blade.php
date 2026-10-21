<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Models\Habit;
use App\Services\TrackingDate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Habit')] class extends Component
{
    use RequiresSameUser;

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
        $habit = $this->habit;
        $today = $this->recording['date'];
        $current = $habit->versions()->with('checkpoints')->where('effective_date', '<=', $today)->latest('effective_date')->first();
        $pending = $habit->entries()->exists()
            ? $habit->versions()->with('checkpoints')->where('effective_date', '>', $today)->latest('effective_date')->first()
            : null;
        $scheduled = $current === null && $pending === null
            ? $habit->versions()->with('checkpoints')->where('effective_date', '>', $today)->oldest('effective_date')->first()
            : null;
        // Compare display values strictly: an absent goal and a zero maximum
        // must not look equivalent when saved settings differ from a version.
        $configuration = static fn ($record) => [
            $record->type->value, $record->goal_value, $record->goal_unit,
            $record->start_date->toDateString(), $record->end_date?->toDateString(),
            $record->checkpoints->map(fn ($checkpoint) => [$checkpoint->label, $checkpoint->sort_order])->all(),
        ];
        $displayed = $pending ?? $scheduled ?? $current;

        return [
            'current' => $current,
            'pending' => $pending,
            // A future first setup is scheduled, not a change to recorded history.
            'scheduled' => $scheduled,
            'requestedDiffers' => $displayed !== null && $configuration($displayed) !== $configuration($habit),
        ];
    }

    #[Computed]
    public function recording(): array
    {
        $user = auth()->user()->fresh();
        $today = $user->localToday();
        $allowed = $user->can('record', [$this->habit, $today]);

        return [
            'date' => $today,
            'timezone' => $user->timezone,
            'allowed' => $allowed,
            'reason' => $allowed ? null : app(TrackingDate::class)->reason($user, $this->habit, $today),
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

<div class="min-w-0 space-y-6 sm:space-y-8" data-habit-details>
    @php($habit = $this->habit)
    @php($setup = $this->trackingSetup)
    @php($recording = $this->recording)
    <a href="{{ route('habits.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded text-sm text-zinc-400 hover:text-accent-content focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent">
        <span aria-hidden="true">←</span> Back to habits
    </a>
    <header class="space-y-5">
        <div>
            <x-terminal-path path="~/habits/details" />
            <h1 class="mt-3 break-words text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">{{ $habit->name }}</h1>
            @if($habit->description !== null)<p class="mt-3 max-w-3xl whitespace-pre-wrap break-words text-sm leading-relaxed text-zinc-400">{{ $habit->description }}</p>@endif
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap" data-habit-detail-actions>
                @if($recording['allowed'])
                    <flux:button :href="route('entries.edit', ['habit' => $habitId, 'date' => $recording['date']])" wire:navigate variant="primary" class="col-span-2 min-h-11">Record today</flux:button>
                @endif
                @can('update', $habit)
                    <flux:button :href="route('habits.edit', $habitId)" wire:navigate variant="outline" class="min-h-11">Edit habit</flux:button>
                @endcan
                <flux:button :href="route('entries.index', $habitId)" wire:navigate variant="ghost" class="min-h-11">Entry history</flux:button>
            </div>
            <p class="text-xs text-zinc-400">Today · {{ $recording['date'] }} · {{ $recording['timezone'] }}</p>
        </div>
        @unless($recording['allowed'])
            <p class="rounded-lg border border-zinc-800 px-4 py-3 text-sm leading-relaxed text-zinc-400" data-recording-unavailable>Recording is unavailable today. {{ $recording['reason'] }}</p>
        @endunless
    </header>
    @if(session('status'))<p role="status" class="text-emerald-300">{{ session('status') }}</p>@endif
    <livewire:activity-heatmap :habit-id="$habitId" />
    <div class="grid min-w-0 items-start gap-6 lg:grid-cols-3" data-habit-detail-information>
        <div class="min-w-0 space-y-5 lg:col-span-2">
            @if($setup['current'])
                <x-habit-configuration-card :setup="$setup['current']" heading="Current tracking setup" id="current-tracking-setup"
                    :description="'Effective from '.$setup['current']->effective_date->toDateString().'. Dates use '.$recording['timezone'].'.'" />
            @elseif($setup['scheduled'])
                <x-habit-configuration-card :setup="$setup['scheduled']" heading="Scheduled tracking setup" id="scheduled-tracking-setup"
                    :description="'Your first setup takes effect on '.$setup['scheduled']->effective_date->toDateString().' in '.$recording['timezone'].'. Tracking has not started yet.'" />
            @else
                <x-habit-configuration-card :setup="$habit" heading="Saved tracking settings" id="saved-tracking-settings"
                    description="No effective tracking setup is available for today. These saved settings do not establish which setup applies to a historical date." />
            @endif
            @if($setup['pending'])
                <x-habit-configuration-card :setup="$setup['pending']" heading="Upcoming tracking change" id="upcoming-tracking-change"
                    :description="'Takes effect on '.$setup['pending']->effective_date->toDateString().' in '.$recording['timezone'].'. Earlier entries keep their original setup.'" />
            @endif
            @if($setup['requestedDiffers'])
                <x-habit-configuration-card :setup="$habit" heading="Saved settings differ" id="saved-tracking-difference"
                    description="These saved habit settings differ from the dated setup above. No additional effective change is recorded for these differences. Daily entries continue to use their dated setup." />
            @endif
            <p class="text-sm leading-relaxed text-zinc-400">Entry history opens each recorded date with the setup that applied then. Changes after the first entry take effect tomorrow; earlier entries and their configuration stay intact.</p>
        </div>
        <section class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6" aria-labelledby="habit-information">
            <h2 id="habit-information" class="text-lg font-semibold text-zinc-100">Habit information</h2>
            <dl class="grid gap-5 text-sm sm:grid-cols-2 lg:grid-cols-1">
                <div class="min-w-0"><dt class="text-zinc-400">Category</dt><dd class="mt-1 break-words text-zinc-200">{{ $habit->category->name }}</dd></div>
                <div><dt class="text-zinc-400">Lifecycle status</dt><dd class="mt-1 text-zinc-200">{{ $habit->archived_at ? 'Archived' : 'Active' }}</dd></div>
                <div><dt class="text-zinc-400">Visibility</dt><dd class="mt-1 text-zinc-200">{{ $habit->visibility->label() }}</dd></div>
            </dl>
            @if($habit->archived_at)<p class="text-sm leading-relaxed text-zinc-400">Archived habits keep their history. Recording availability follows the archive dates; unarchive from your habits list to resume.</p>@endif
            <p class="border-t border-zinc-800 pt-4 text-sm leading-relaxed text-zinc-400">Only you can edit this habit. Its visibility and your account privacy control read-only sharing through People. Notes and N/A reasons stay private.</p>
        </section>
    </div>
</div>
