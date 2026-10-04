<?php

namespace App\Actions\Journals;

use App\Livewire\Forms\JournalForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteJournal
{
    public function handle(User $user, string $date, int $expectedId, int $expectedRevision, string $timezone): void
    {
        JournalForm::validateDate($date);
        DB::transaction(function () use ($user, $date, $expectedId, $expectedRevision, $timezone) {
            $user = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $journal = $user->journals()->where('journal_date', $date)->lockForUpdate()->first();
            if ($timezone !== $user->timezone || $journal?->id !== $expectedId || $journal?->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['conflict' => 'This journal or your timezone changed. Reload the journal before deleting it.']);
            }
            Gate::forUser($user)->authorize('delete', $journal);
            if ($date > $user->localToday()) {
                throw ValidationException::withMessages(['date' => 'Future journals are read-only. Choose today or an earlier date.']);
            }
            $journal->delete();
        });
    }
}
