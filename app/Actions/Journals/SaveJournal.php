<?php

namespace App\Actions\Journals;

use App\Livewire\Forms\JournalForm;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveJournal
{
    public function handle(User $user, string $date, array $input, ?int $expectedId, ?int $expectedRevision, string $timezone): Journal
    {
        JournalForm::validateDate($date);
        $data = JournalForm::validateInput($input);

        try {
            return DB::transaction(function () use ($user, $date, $data, $expectedId, $expectedRevision, $timezone) {
                $user = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('create', Journal::class);
                $journal = $user->journals()->where('journal_date', $date)->lockForUpdate()->first();
                if ($journal) {
                    Gate::forUser($user)->authorize('update', $journal);
                }
                if ($timezone !== $user->timezone || $journal?->id !== $expectedId || $journal?->revision !== $expectedRevision) {
                    throw ValidationException::withMessages(['conflict' => 'This journal or your timezone changed. Reload the journal before saving again.']);
                }
                if ($date > $user->localToday()) {
                    throw ValidationException::withMessages(['date' => 'Future journals are read-only. Choose today or an earlier date.']);
                }
                $journal ??= new Journal;
                $journal->forceFill([
                    'user_id' => $user->id, 'journal_date' => $date, 'content' => $data['content'], 'mood' => $data['mood'] ?? null,
                    'revision' => $journal->exists ? $journal->revision + 1 : 1,
                ])->save();

                return $journal;
            });
        } catch (QueryException) {
            // SQL exceptions can contain journal text in their bindings. Do not report them.
            throw ValidationException::withMessages(['form.content' => 'The journal could not be saved. Please try again.']);
        }
    }
}
