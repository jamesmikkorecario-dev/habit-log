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

<div class="max-w-2xl space-y-6" data-entry-editor x-data="{ dirty: false }"
    x-on:input="if ($event.target.closest('[data-entry-form]')) dirty = true" x-on:change="if ($event.target.closest('[data-entry-form]')) dirty = true" x-on:entry-loaded.window="dirty = false"
    x-on:livewire:navigate.window="if (dirty && !confirm('Leave this page and discard unsaved activity?')) $event.preventDefault()"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">
    <div><h1 class="break-words text-2xl font-semibold">{{ $this->habit->name }}</h1><p class="mt-2 text-sm text-zinc-400">{{ $date }} · {{ $timezone }}</p></div>
    <form wire:submit="goToDate" class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="selectedDate" type="date" label="Entry date" min="1000-01-01" :max="auth()->user()->localToday()" />
        <flux:button type="submit">Go to date</flux:button>
    </form>
    <flux:error name="date" />
    <p role="status" aria-live="polite" class="text-emerald-300">{{ $feedback }}</p>
    <flux:error name="conflict" />
    <flux:button wire:click="loadEntry" wire:confirm="Reload this entry and discard unsaved input?">Reload entry</flux:button>
    @if(!$this->version)
        <p>{{ $this->reason ?? 'The tracking setup changed. Reload this entry.' }}</p>
    @else
        <p class="text-sm text-zinc-400">{{ $this->version->type->label() }}@if(in_array($this->version->type->value, ['count', 'duration', 'avoidance'], true)) · {{ $this->version->type->value === 'avoidance' ? 'Maximum' : 'Goal' }}: {{ $this->version->goal_value ?? 'Not set' }} {{ $this->version->goal_unit }}@endif</p>
        @if($this->reason)<p role="status">{{ $this->reason }} Saved activity is read-only.</p>@endif
        <form wire:submit="save" class="space-y-5" data-entry-form>
            <fieldset class="space-y-5" @disabled($this->reason !== null)>
            <flux:select wire:model.live="form.status" :label="$this->version->type === HabitType::Checkpoint ? 'Applicability' : 'Your outcome'" :description="in_array($this->version->type->value, ['count', 'duration', 'avoidance'], true) ? 'Your assessment of the day. Measurements are saved separately.' : null">
                @if($this->version->type === HabitType::Checkpoint)
                    <flux:select.option value="missed">Track checkpoints</flux:select.option>
                    @if(in_array($form->status, ['partial', 'completed'], true))<flux:select.option :value="$form->status">Track checkpoints</flux:select.option>@endif
                @else
                    <flux:select.option value="">Choose an outcome</flux:select.option>
                    <flux:select.option value="completed">Completed</flux:select.option>
                    @if($this->version->type !== HabitType::Boolean)<flux:select.option value="partial">Partial</flux:select.option>@endif
                    <flux:select.option value="missed">Missed</flux:select.option>
                @endif
                <flux:select.option value="not_applicable">Not applicable</flux:select.option>
            </flux:select>
            @if($form->status === 'not_applicable')
                <p class="text-sm text-zinc-400">This date is neutral. Previously saved measurements and checkpoints are retained.</p>
                <div wire:key="entry-na-reason" class="space-y-2">
                    <label for="entry-reason" class="text-sm font-medium">Private N/A reason (optional)</label>
                    <flux:textarea id="entry-reason" wire:model="form.not_applicable_reason" maxlength="500" rows="2" />
                    <flux:error name="form.not_applicable_reason" />
                </div>
            @else
                @if(in_array($this->version->type->value, ['count', 'duration', 'avoidance'], true))
                    <div wire:key="entry-numeric-value" class="space-y-2">
                        <label for="entry-value" class="text-sm font-medium">{{ $this->version->type === HabitType::Duration ? 'Actual minutes' : ($this->version->type === HabitType::Avoidance ? 'Occurrences' : 'Actual count') }}</label>
                        <flux:input id="entry-value" wire:model="form.value" type="number" min="0" max="999999999" step="1" aria-describedby="entry-unit" />
                        <p id="entry-unit" class="text-sm text-zinc-400">{{ $this->version->goal_unit }}</p>
                    </div>
                @endif
                @if($this->version->type === HabitType::Checkpoint)
                    <fieldset class="space-y-3"><legend class="mb-3 font-medium">Daily checkpoints</legend>
                        @foreach($this->version->checkpoints as $checkpoint)<flux:checkbox wire:model="form.completed" :value="(string) $checkpoint->id" :label="$checkpoint->label" wire:key="daily-checkpoint-{{ $checkpoint->id }}" />@endforeach
                    </fieldset>
                    <p class="text-sm text-zinc-400">All checked: completed. Some: partial. None: missed.</p>
                @endif
            @endif
            <flux:error name="form.value" /><flux:error name="form.completed" /><flux:error name="form.completed.*" />
            <flux:textarea id="entry-note" label:id="entry-note-label" aria-labelledby="entry-note-label" wire:model="form.note" label="Private note (optional)" maxlength="2000" rows="3" description="Only you can read this note." />
            @if(!$this->reason)<flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save activity</flux:button>@endif
            </fieldset>
        </form>
    @endif
    <div class="flex flex-wrap gap-2">
        <flux:button :href="route('tracking.index', ['date' => $date])" wire:navigate>Daily overview</flux:button>
        <flux:button :href="route('entries.index', $habitId)" wire:navigate>Entry history</flux:button>
    </div>
</div>
