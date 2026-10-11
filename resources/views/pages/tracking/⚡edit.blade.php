<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Actions\Habits\SaveHabitEntry;
use App\Enums\HabitType;
use App\Livewire\Forms\HabitEntryForm;
use App\Models\Habit;
use App\Services\TrackingDate;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Record activity')] class extends Component
{
    use RequiresSameUser;

    public HabitEntryForm $form;
    #[Locked] public int $habitId;
    #[Locked] public string $date;
    #[Locked] public ?int $entryRevision = null;
    #[Locked] public int $versionId = 0;
    #[Locked] public int $versionRevision = 0;
    #[Locked] public string $timezone = 'UTC';
    public string $selectedDate = '';
    public string $feedback = '';

    public function mount(int $habit, string $date): void
    {
        $this->habitId = $habit;
        try { app(TrackingDate::class)->validate($date); } catch (\Illuminate\Validation\ValidationException) { abort(404); }
        $this->date = $this->selectedDate = $date;
        $this->loadEntry();
    }

    #[Computed]
    public function habit(): Habit
    {
        $habit = auth()->user()->habits()->findOrFail($this->habitId);
        $this->authorize('view', $habit);
        return $habit;
    }

    #[Computed]
    public function version()
    {
        return $this->habit->versions()->with('checkpoints')->find($this->versionId);
    }

    #[Computed]
    public function reason(): ?string
    {
        return app(TrackingDate::class)->reason(auth()->user()->fresh(), $this->habit, $this->date);
    }

    public function loadEntry(): void
    {
        unset($this->habit, $this->version, $this->reason);
        $this->resetValidation();
        $user = auth()->user()->fresh();
        $version = app(TrackingDate::class)->version($this->habit, $this->date);
        $entry = $this->habit->entries()->with('checkpoints')->where('entry_date', $this->date)->first();
        if ($entry) { $this->authorize('view', $entry); }
        $this->entryRevision = $entry?->revision;
        $this->versionId = $version?->id ?? 0;
        $this->versionRevision = $version?->revision ?? 0;
        $this->timezone = $user->timezone;
        $this->form->fill([
            'status' => $entry?->status->value ?? ($version?->type === HabitType::Checkpoint ? 'missed' : ''),
            'value' => $entry?->value ?? '', 'note' => $entry?->note ?? '',
            'not_applicable_reason' => $entry?->not_applicable_reason ?? '',
            'completed' => $entry?->checkpoints->where('is_completed', true)->pluck('habit_tracking_checkpoint_id')->map(fn ($id) => (string) $id)->all() ?? [],
        ]);
        $this->dispatch('entry-loaded');
    }

    public function goToDate(): void
    {
        app(TrackingDate::class)->validate($this->selectedDate);
        $this->redirectRoute('entries.edit', ['habit' => $this->habit->id, 'date' => $this->selectedDate], navigate: true);
    }

    public function save(): void
    {
        $this->feedback = '';
        app(SaveHabitEntry::class)->handle(auth()->user(), $this->habitId, $this->date, $this->form->all(), $this->entryRevision, $this->versionId, $this->versionRevision, $this->timezone);
        $this->loadEntry();
        $this->feedback = 'Activity saved.';
    }
}; ?>

<div class="min-w-0 space-y-6 sm:space-y-8" data-entry-editor x-data="{ dirty: false }"
    x-on:input="if ($event.target.closest('[data-entry-form]')) dirty = true" x-on:change="if ($event.target.closest('[data-entry-form]')) dirty = true" x-on:entry-loaded.window="dirty = false"
    x-on:livewire:navigate.window="if (dirty && !confirm('Leave this page and discard unsaved activity?')) $event.preventDefault()"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">
    @php($version = $this->version)
    @php($reason = $this->reason)
    <header>
        <x-terminal-path path="~/tracking/edit" />
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Record activity</h1>
        <p class="mt-3 break-words text-lg font-medium text-zinc-200">{{ $this->habit->name }}</p>
        <p class="mt-2 break-words text-sm text-zinc-400">Editing <time datetime="{{ $date }}">{{ $date }}</time> · {{ $timezone }}</p>
    </header>

    <div class="grid min-w-0 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:gap-8">
        <section aria-labelledby="entry-date-heading" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6" data-entry-date-navigation>
            <div>
                <h2 id="entry-date-heading" class="text-lg font-semibold text-zinc-100">Choose a date</h2>
                <p class="mt-2 text-sm leading-relaxed text-zinc-400">Revisit a day using the tracking setup that applied then.</p>
            </div>
            <form wire:submit="goToDate" class="flex min-w-0 flex-wrap items-end gap-3">
                <div class="w-full min-w-0 sm:max-w-56">
                    <flux:input wire:model="selectedDate" type="date" label="Entry date" min="1000-01-01" :max="auth()->user()->localToday()" />
                </div>
                <flux:button type="submit" class="min-h-11" wire:loading.attr="disabled">Go to date</flux:button>
                <p class="w-full text-xs leading-relaxed text-zinc-400">Choose a date, then select Go to date. Unsaved activity stays here unless you confirm leaving.</p>
            </form>
            <flux:error name="date" />
            <div class="space-y-3 border-t border-zinc-800 pt-5">
                <flux:button wire:click="loadEntry" wire:confirm="Reload this entry and discard unsaved input?" variant="outline" class="min-h-11" wire:loading.attr="disabled">Reload entry</flux:button>
                <p class="text-xs leading-relaxed text-zinc-400">Reload the saved activity for {{ $date }}. This keeps the same date and replaces your unsaved input after confirmation.</p>
            </div>
        </section>

        <section aria-labelledby="daily-check-in-heading" class="w-full min-w-0 max-w-3xl justify-self-center rounded-xl border border-zinc-800 bg-zinc-900/30 lg:justify-self-end" data-entry-writing>
            <header class="space-y-3 border-b border-zinc-800 p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="daily-check-in-heading" class="text-xl font-semibold text-zinc-100">Daily check-in</h2>
                    @if($version)<span class="rounded-md border border-zinc-700 px-2.5 py-1 font-mono text-xs text-zinc-300">{{ $version->type->label() }}</span>@endif
                </div>
                <p class="text-sm leading-relaxed text-zinc-400" data-entry-state>{{ $entryRevision === null ? 'No saved activity for this date. Your choices are not recorded until you save.' : 'Editing saved activity. Save to apply your changes.' }}</p>
                @if($version && in_array($version->type->value, ['count', 'duration', 'avoidance'], true))
                    <p class="break-words text-sm text-zinc-300">{{ $version->type->value === 'avoidance' ? 'Acceptable maximum' : 'Goal' }}: {{ $version->goal_value ?? 'Not set' }}@if($version->goal_value !== null && $version->goal_unit) {{ $version->goal_unit }}@endif</p>
                @endif
            </header>
            <div class="space-y-5 p-5 sm:p-6">
                @if($errors->has('form.*') || $errors->has('conflict'))
                    <p role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus())" class="rounded-lg border border-red-900/60 p-4 text-sm text-red-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-300">Review the highlighted fields. Your changes have not been saved.</p>
                @endif
                <flux:error name="conflict" />
                @if(!$version)
                    <p class="rounded-lg border border-zinc-800 px-4 py-3 text-sm leading-relaxed text-zinc-400" role="status">{{ $reason ?? 'The tracking setup changed. Reload this entry.' }}</p>
                @else
                    @if($reason)<p role="status" class="rounded-lg border border-zinc-800 px-4 py-3 text-sm leading-relaxed text-zinc-400">{{ $reason }} Saved activity is read-only.</p>@endif
                    <form wire:submit="save" class="space-y-5" data-entry-form>
                        <fieldset class="min-w-0 space-y-5" @disabled($reason !== null) wire:loading.attr="disabled" wire:target="save,loadEntry">
                            <div class="max-w-sm">
                                <flux:select wire:model.live="form.status" :label="$version->type === HabitType::Checkpoint ? 'Applicability' : 'Your outcome'" :description="in_array($version->type->value, ['count', 'duration', 'avoidance'], true) ? 'Your assessment of the day. Measurements are saved separately.' : null">
                                    @if($version->type === HabitType::Checkpoint)
                                        <flux:select.option value="missed">Track checkpoints</flux:select.option>
                                        @if(in_array($form->status, ['partial', 'completed'], true))<flux:select.option :value="$form->status">Track checkpoints</flux:select.option>@endif
                                    @else
                                        <flux:select.option value="">Choose an outcome</flux:select.option>
                                        <flux:select.option value="completed">Completed</flux:select.option>
                                        @if($version->type !== HabitType::Boolean)<flux:select.option value="partial">Partial</flux:select.option>@endif
                                        <flux:select.option value="missed">Missed</flux:select.option>
                                    @endif
                                    <flux:select.option value="not_applicable">Not applicable</flux:select.option>
                                </flux:select>
                            </div>
                            @if($form->status === 'not_applicable')
                                <p class="text-sm leading-relaxed text-zinc-400">This date is neutral. Previously saved measurements and checkpoints are retained.</p>
                                <div wire:key="entry-na-reason" class="space-y-2">
                                    <label for="entry-reason" class="text-sm font-medium text-zinc-200">Private N/A reason (optional)</label>
                                    <flux:textarea id="entry-reason" wire:model="form.not_applicable_reason" maxlength="500" rows="3" aria-describedby="entry-reason-help" />
                                    <p id="entry-reason-help" class="text-xs leading-relaxed text-zinc-400">Only you can read this reason. Up to 500 characters.</p>
                                    <flux:error name="form.not_applicable_reason" />
                                </div>
                            @else
                                @if(in_array($version->type->value, ['count', 'duration', 'avoidance'], true))
                                    <div wire:key="entry-numeric-value" class="max-w-sm space-y-2">
                                        <label for="entry-value" class="text-sm font-medium text-zinc-200">{{ $version->type === HabitType::Duration ? 'Actual minutes' : ($version->type === HabitType::Avoidance ? 'Occurrences' : 'Actual count') }}</label>
                                        <flux:input id="entry-value" wire:model="form.value" type="number" min="0" max="999999999" step="1" inputmode="numeric" aria-required="true" aria-describedby="entry-unit entry-measurement-help" />
                                        <p id="entry-unit" class="break-words text-sm text-zinc-400">{{ $version->goal_unit }}</p>
                                        <p id="entry-measurement-help" class="text-xs leading-relaxed text-zinc-400">{{ $version->type === HabitType::Duration ? 'Enter the whole number of minutes spent, from 0 to 999,999,999.' : ($version->type === HabitType::Avoidance ? 'Enter how many times the behavior occurred, from 0 to 999,999,999.' : 'Enter a whole-number measurement from 0 to 999,999,999.') }}</p>
                                    </div>
                                    <p class="text-sm leading-relaxed text-zinc-400">{{ $version->goal_value === null ? 'Without a numeric goal, Completed / Partial / Missed scores 100% / 50% / 0%.' : ($version->type === HabitType::Avoidance ? 'At or below your maximum counts as success. A zero maximum means abstinence.' : 'Performance uses this measurement and the goal for this date; it may differ from your assessment.') }}</p>
                                @endif
                                @if($version->type === HabitType::Checkpoint)
                                    <fieldset class="min-w-0 space-y-3">
                                        <legend class="mb-3 text-sm font-medium text-zinc-200">Daily checkpoints</legend>
                                        @foreach($version->checkpoints as $checkpoint)
                                            <div class="min-w-0 rounded-lg border border-zinc-800 px-3 [&_[data-flux-field]]:items-center [&_[data-flux-label]]:block [&_[data-flux-label]]:min-h-11 [&_[data-flux-label]]:min-w-0 [&_[data-flux-label]]:break-words [&_[data-flux-label]]:py-3" data-entry-checkpoint wire:key="daily-checkpoint-{{ $checkpoint->id }}">
                                                <flux:checkbox wire:model="form.completed" :value="(string) $checkpoint->id" :label="$checkpoint->label" />
                                            </div>
                                        @endforeach
                                    </fieldset>
                                    <p class="text-sm leading-relaxed text-zinc-400">All checked: completed. Some: partial. None: missed.</p>
                                @endif
                            @endif
                            <flux:error name="form.value" /><flux:error name="form.completed" /><flux:error name="form.completed.*" />
                            <div class="space-y-3 border-t border-zinc-800 pt-5">
                                <flux:textarea id="entry-note" label:id="entry-note-label" aria-labelledby="entry-note-label" aria-describedby="entry-note-help" wire:model="form.note" label="Private note (optional)" maxlength="2000" rows="5" class="text-base! leading-relaxed!" description="Only you can read this note." />
                                <p id="entry-note-help" class="text-xs text-zinc-400">Up to 2,000 characters of plain text.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-5">
                                @if(!$reason)
                                    <flux:button type="submit" variant="primary" :loading="false" class="min-h-11 w-full sm:w-auto" wire:loading.attr="disabled" wire:target="save">
                                        <span wire:loading.remove wire:target="save">Save activity</span><span wire:loading wire:target="save">Saving…</span>
                                    </flux:button>
                                @endif
                                <span x-show="dirty" x-cloak class="text-sm text-zinc-400">Unsaved changes</span>
                            </div>
                        </fieldset>
                    </form>
                @endif
                <p role="status" aria-live="polite" x-show="!dirty" class="empty:hidden text-sm text-accent-content">{{ $feedback }}</p>
            </div>
        </section>
    </div>
    <nav aria-label="Activity navigation" class="flex flex-wrap gap-2 border-t border-zinc-800 pt-5">
        <flux:button :href="route('tracking.index', ['date' => $date])" wire:navigate variant="ghost" class="min-h-11">Daily overview</flux:button>
        <flux:button :href="route('entries.index', $habitId)" wire:navigate variant="ghost" class="min-h-11">Entry history</flux:button>
    </nav>
</div>
