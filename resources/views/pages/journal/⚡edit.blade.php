<?php

use App\Actions\Journals\DeleteJournal;
use App\Actions\Journals\SaveJournal;
use App\Livewire\Forms\JournalForm;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Private journal')] class extends Component
{
    public JournalForm $form;
    #[Locked] public int $ownerId;
    #[Locked] public string $date;
    #[Locked] public ?int $entryId = null;
    #[Locked] public ?int $entryRevision = null;
    #[Locked] public string $timezone = 'UTC';
    #[Locked] public bool $deletionRequested = false;
    public bool $showDeleteModal = false;
    public string $selectedDate = '';
    public string $feedback = '';

    public function mount(): void
    {
        $this->ownerId = auth()->id();
        $user = $this->owner();
        try {
            $this->date = JournalForm::validateDate(array_key_exists('date', request()->query()) ? request()->query('date') : $user->localToday());
        } catch (ValidationException) { abort(404); }
        abort_if($this->date > $user->localToday() && ! $user->journals()->where('journal_date', $this->date)->exists(), 404);
        $this->loadEntry();
    }

    public function hydrate(): void
    {
        $this->owner();
    }

    private function owner(): User
    {
        abort_unless(auth()->check() && auth()->id() === $this->ownerId, 403);
        $user = auth()->user()->fresh();
        $this->authorize('create', Journal::class);
        return $user;
    }

    #[Computed]
    public function today(): string
    {
        return $this->owner()->localToday();
    }

    #[Computed]
    public function previousDate(): ?string
    {
        return $this->date > '1000-01-01' ? CarbonImmutable::parse($this->date)->subDay()->toDateString() : null;
    }

    #[Computed]
    public function nextDate(): ?string
    {
        return $this->date < $this->today && $this->date < '9999-12-31' ? CarbonImmutable::parse($this->date)->addDay()->toDateString() : null;
    }

    public function loadEntry(): void
    {
        $user = $this->owner();
        JournalForm::validateDate($this->date);
        $entry = $user->journals()->where('journal_date', $this->date)->first();
        if ($entry) { $this->authorize('view', $entry); }
        $this->entryId = $entry?->id;
        $this->entryRevision = $entry?->revision;
        $this->timezone = $user->timezone;
        $this->selectedDate = $this->date;
        $this->form->fill(['content' => $entry?->content ?? '', 'mood' => $entry?->mood ?? '']);
        $this->deletionRequested = $this->showDeleteModal = false;
        $this->resetValidation();
        $this->feedback = '';
        $this->dispatch('journal-loaded');
    }

    public function goToDate(): void
    {
        $user = $this->owner();
        $date = JournalForm::validateDate($this->selectedDate, 'selectedDate');
        if ($date > $user->localToday() && ! $user->journals()->where('journal_date', $date)->exists()) {
            throw ValidationException::withMessages(['selectedDate' => 'Choose today or an earlier date.']);
        }
        $this->redirectRoute('journal.index', ['date' => $date], navigate: true);
    }

    public function save(): void
    {
        $this->feedback = '';
        app(SaveJournal::class)->handle($this->owner(), $this->date, $this->form->all(), $this->entryId, $this->entryRevision, $this->timezone);
        $this->loadEntry();
        $this->feedback = 'Journal saved.';
    }

    public function confirmDeletion(): void
    {
        $user = $this->owner();
        abort_if($this->date > $user->localToday() || $this->entryId === null, 403);
        $entry = $user->journals()->findOrFail($this->entryId);
        $this->authorize('delete', $entry);
        $this->deletionRequested = $this->showDeleteModal = true;
    }

    public function cancelDeletion(): void
    {
        $this->owner();
        $this->deletionRequested = $this->showDeleteModal = false;
    }

    public function delete(): void
    {
        $user = $this->owner();
        abort_unless($this->deletionRequested && $this->entryId !== null && $this->entryRevision !== null, 403);
        // Close the dialog even on conflict so its feedback and reload control are accessible.
        $this->deletionRequested = $this->showDeleteModal = false;
        app(DeleteJournal::class)->handle($user, $this->date, $this->entryId, $this->entryRevision, $this->timezone);
        $this->loadEntry();
        $this->feedback = 'Journal and mood deleted.';
        $this->dispatch('journal-deleted');
    }
}; ?>

<div class="max-w-2xl space-y-6" data-journal-editor x-data="{ dirty: false }"
    x-on:input="if ($event.target.closest('[data-journal-form]')) dirty = true"
    x-on:change="if ($event.target.closest('[data-journal-form]')) dirty = true"
    x-on:journal-loaded.window="dirty = false"
    x-on:journal-deleted.window="$nextTick(() => document.getElementById('journal-heading')?.focus())"
    x-on:livewire:navigate.window="if (!$event.detail.history && dirty && !confirm('Leave this page and discard unsaved journal changes?')) { $event.preventDefault(); $wire.selectedDate = $wire.date; }"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">
    <div>
        <h1 id="journal-heading" tabindex="-1" class="text-2xl font-semibold focus-visible:outline-2 focus-visible:outline-emerald-300">Private journal</h1>
        <p class="mt-2 text-sm text-zinc-400">{{ $date }} · {{ $timezone }}</p>
        <p class="mt-2 text-sm text-zinc-400">Only you can read your journal and mood.</p>
    </div>
    <div class="flex flex-wrap gap-2" aria-label="Journal dates">
        @if($this->previousDate)<flux:button :href="route('journal.index', ['date' => $this->previousDate])" wire:navigate>Previous day</flux:button>@endif
        <flux:button :href="route('journal.index')" wire:navigate>Today</flux:button>
        @if($this->nextDate)<flux:button :href="route('journal.index', ['date' => $this->nextDate])" wire:navigate>Next day</flux:button>@endif
    </div>
    <form wire:submit="goToDate" class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="selectedDate" type="date" label="Journal date" min="1000-01-01" :max="max($date, $this->today)" />
        <flux:button type="submit" wire:loading.attr="disabled">Go to date</flux:button>
    </form>
    <p role="status" aria-live="polite" class="text-emerald-300">{{ $feedback }}</p>
    <flux:error name="date" />
    <flux:error name="conflict" />
    <flux:button wire:click="loadEntry" wire:confirm="Reload this journal and discard unsaved input?" wire:loading.attr="disabled">Reload journal</flux:button>
    @if($date > $this->today)
        <p role="status">This saved date is in the future in your current timezone. Your journal is read-only until that day.</p>
    @elseif($entryId === null)
        <p class="text-sm text-zinc-400">No journal for this date. Write when you are ready.</p>
    @endif
    <form wire:submit="save" class="space-y-5" data-journal-form>
        <fieldset class="space-y-5" wire:loading.attr="disabled" @disabled($date > $this->today)>
            <flux:textarea id="journal-content" label:id="journal-content-label" aria-labelledby="journal-content-label" wire:model="form.content" label="Journal text" description="Required. Up to 10,000 characters. Plain text only." maxlength="10000" rows="10" />
            <flux:select id="journal-mood" wire:model="form.mood" label="Mood (optional)">
                <flux:select.option value="">Not set</flux:select.option>
                @foreach([1 => 'Very low', 2 => 'Low', 3 => 'Okay', 4 => 'Good', 5 => 'Very good'] as $value => $label)
                    <flux:select.option :value="$value">{{ $value }} — {{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            @if($date <= $this->today)<flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save journal</flux:button>@endif
        </fieldset>
    </form>
    @if($entryId !== null && $date <= $this->today)
        <flux:button id="journal-delete-trigger" variant="danger" x-on:click="if (!dirty || confirm('Deleting this journal will also discard your unsaved changes. Continue to confirmation?')) $wire.confirmDeletion()" wire:loading.attr="disabled">Delete journal</flux:button>
    @endif
    <flux:modal name="delete-journal" wire:model="showDeleteModal" wire:close="cancelDeletion"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'journal-delete-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'journal-delete-description')"
        x-on:close="$nextTick(() => (document.getElementById('journal-delete-trigger') ?? document.getElementById('journal-heading'))?.focus())"
        class="w-[calc(100%-2rem)] min-w-0! space-y-5">
        <h2 id="journal-delete-title" class="pr-6 text-lg font-semibold">Delete journal for {{ $date }}?</h2>
        <p id="journal-delete-description" class="text-sm text-zinc-300">This permanently removes this day's text and mood. You cannot undo this deletion.</p>
        <div class="flex flex-wrap justify-end gap-3">
            <flux:button wire:click="cancelDeletion" autofocus>Cancel</flux:button>
            <flux:button wire:click="delete" variant="danger" wire:loading.attr="disabled">Permanently delete journal</flux:button>
        </div>
    </flux:modal>
</div>
