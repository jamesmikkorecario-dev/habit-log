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

<div class="min-w-0 space-y-6 sm:space-y-8" data-journal-editor x-data="{
        dirty: false,
        get characterCount() {
            return Array.from(String($wire.form.content ?? '').replace(/^\p{White_Space}+|\p{White_Space}+$/gu, '')).length;
        }
    }"
    x-on:input="if ($event.target.closest('[data-journal-form]')) dirty = true"
    x-on:change="if ($event.target.closest('[data-journal-form]')) dirty = true"
    x-on:journal-loaded.window="dirty = false"
    x-on:journal-deleted.window="$nextTick(() => document.getElementById('journal-heading')?.focus())"
    x-on:livewire:navigate.window="if (!$event.detail.history && dirty && !confirm('Leave this page and discard unsaved journal changes?')) { $event.preventDefault(); $wire.selectedDate = $wire.date; }"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <x-terminal-path path="~/journal" />
            <h1 id="journal-heading" tabindex="-1" class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 focus-visible:outline-2 focus-visible:outline-emerald-300 sm:text-4xl">Private journal</h1>
            <p class="mt-3 text-zinc-400">A quiet space to reflect, one day at a time.</p>
        </div>
        <p class="flex items-center gap-2 text-sm text-zinc-400"><flux:icon.lock-closed class="size-4 shrink-0 text-emerald-300" aria-hidden="true" />Only you can read your journal and mood.</p>
    </header>

    <div class="grid min-w-0 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:gap-8">
        <section aria-label="Choose a journal date" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
            <div>
                <p class="text-xs font-medium text-zinc-400">Selected day{{ $date === $this->today ? ' · Today' : '' }}</p>
                <p class="mt-2 text-xl font-semibold"><time datetime="{{ $date }}">{{ \Carbon\CarbonImmutable::parse($date)->format('l, F j, Y') }}</time></p>
                <p class="mt-2 break-words font-mono text-xs text-zinc-400">{{ $date }} · {{ $timezone }}</p>
            </div>
            <div class="flex flex-wrap gap-2" role="group" aria-label="Journal dates">
                @if($this->previousDate)<flux:button :href="route('journal.index', ['date' => $this->previousDate])" aria-label="Previous day" icon="chevron-left" class="min-h-11" wire:navigate>Previous</flux:button>@endif
                <flux:button :href="route('journal.index')" class="min-h-11" wire:navigate>Today</flux:button>
                @if($this->nextDate)<flux:button :href="route('journal.index', ['date' => $this->nextDate])" aria-label="Next day" icon:trailing="chevron-right" class="min-h-11" wire:navigate>Next</flux:button>@endif
            </div>
            <form wire:submit="goToDate" class="flex min-w-0 flex-wrap items-end gap-3 border-t border-zinc-800 pt-5">
                <div class="min-w-0 w-full sm:max-w-56">
                    <flux:input wire:model="selectedDate" type="date" label="Journal date" min="1000-01-01" :max="max($date, $this->today)" />
                </div>
                <flux:button type="submit" class="min-h-11" wire:loading.attr="disabled">Go to date</flux:button>
                <p class="w-full text-xs leading-relaxed text-zinc-400">Choose a day to revisit. Unsaved writing stays here unless you confirm leaving.</p>
            </form>
            <flux:error name="date" />
        </section>

        <section aria-labelledby="reflection-heading" class="w-full min-w-0 max-w-3xl justify-self-center rounded-xl border border-zinc-800 bg-zinc-900/30 lg:justify-self-end" data-journal-writing>
            <header class="border-b border-zinc-800 p-5 sm:p-6">
                <h2 id="reflection-heading" class="text-xl font-semibold">{{ $date === $this->today ? "Today's reflection" : 'Daily reflection' }}</h2>
                @if($date > $this->today)
                    <p role="status" class="mt-2 text-sm leading-relaxed text-zinc-400">This saved date is in the future in your current timezone. Your journal is read-only until that day.</p>
                @elseif($entryId === null)
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">No entry for this day yet. Your space is ready whenever you are.</p>
                @else
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">Return to your thoughts. Save when you are ready.</p>
                @endif
            </header>
            <div class="space-y-5 p-5 sm:p-6">
                <p role="status" aria-live="polite" class="empty:hidden text-sm text-emerald-300">{{ $feedback }}</p>
                <flux:error name="conflict" />
                <form wire:submit="save" class="space-y-5" data-journal-form>
                    <fieldset class="min-w-0 space-y-5" wire:loading.attr="disabled" @disabled($date > $this->today)>
                        <div class="space-y-2">
                            <flux:textarea id="journal-content" label:id="journal-content-label" aria-labelledby="journal-content-label" aria-describedby="journal-text-help journal-count" wire:model="form.content" label="Journal text" placeholder="What's on your mind today?" rows="12" class="text-base! leading-relaxed! p-4!" />
                            <div class="flex flex-wrap justify-between gap-x-4 gap-y-2 text-xs text-zinc-400">
                                <p id="journal-text-help" class="max-w-sm leading-relaxed">Required. Plain text only. Leading and trailing whitespace is ignored.</p>
                                <p id="journal-count" class="font-mono tabular-nums" :class="characterCount > 10000 ? 'text-red-300' : 'text-zinc-400'"><span x-text="characterCount.toLocaleString('en-US')">{{ is_string($form->content) ? number_format(mb_strlen(preg_replace('/^\s+|\s+$/u', '', $form->content))) : 0 }}</span> / 10,000 characters</p>
                            </div>
                        </div>
                        <div class="max-w-sm">
                            <flux:select id="journal-mood" wire:model="form.mood" label="Mood (optional)" description="How did this day feel? Leave it unset if you prefer.">
                                <flux:select.option value="">Not set</flux:select.option>
                                @foreach([1 => 'Very low', 2 => 'Low', 3 => 'Okay', 4 => 'Good', 5 => 'Very good'] as $value => $label)
                                    <flux:select.option :value="$value">{{ $value }} — {{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-5">
                            @if($date <= $this->today)<flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled">Save journal</flux:button>@endif
                            @if($date <= $this->today)<flux:button type="button" wire:click="loadEntry" wire:confirm="Reload this journal and discard unsaved input?" variant="ghost" class="min-h-11" wire:loading.attr="disabled">Reload journal</flux:button>@endif
                            <span x-show="dirty" x-cloak class="text-xs text-zinc-400">Unsaved changes</span>
                        </div>
                    </fieldset>
                </form>
                @if($date > $this->today)
                    <flux:button wire:click="loadEntry" wire:confirm="Reload this journal and discard unsaved input?" variant="ghost" class="min-h-11" wire:loading.attr="disabled">Reload journal</flux:button>
                @endif
                @if($entryId !== null && $date <= $this->today)
                    <div class="border-t border-zinc-800 pt-4">
                        <flux:button id="journal-delete-trigger" variant="ghost" class="min-h-11 text-red-300!" x-on:click="if (!dirty || confirm('Deleting this journal will also discard your unsaved changes. Continue to confirmation?')) $wire.confirmDeletion()" wire:loading.attr="disabled">Delete journal</flux:button>
                    </div>
                @endif
            </div>
        </section>
    </div>
    {{-- Restore focus after cancellation finishes; loading disables the trigger during that request. --}}
    <flux:modal name="delete-journal" wire:model="showDeleteModal"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'journal-delete-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'journal-delete-description')"
        x-on:close="await $wire.cancelDeletion(); $nextTick(() => (document.getElementById('journal-delete-trigger') ?? document.getElementById('journal-heading'))?.focus())"
        class="w-[calc(100%-2rem)] min-w-0! space-y-5">
        <h2 id="journal-delete-title" class="pr-6 text-lg font-semibold">Delete journal for {{ $date }}?</h2>
        <p id="journal-delete-description" class="text-sm text-zinc-300">This permanently removes this day's text and mood. You cannot undo this deletion.</p>
        <div class="flex flex-wrap justify-end gap-3">
            <flux:button wire:click="cancelDeletion" autofocus>Cancel</flux:button>
            <flux:button wire:click="delete" variant="danger" wire:loading.attr="disabled">Permanently delete journal</flux:button>
        </div>
    </flux:modal>
</div>
