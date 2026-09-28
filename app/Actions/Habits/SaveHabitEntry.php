<?php

namespace App\Actions\Habits;

use App\Enums\HabitType;
use App\Livewire\Forms\HabitEntryForm;
use App\Models\HabitEntry;
use App\Models\User;
use App\Services\TrackingDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveHabitEntry
{
    public function handle(User $user, int $habitId, string $date, array $input, ?int $expectedRevision, int $versionId, int $versionRevision, string $timezone): HabitEntry
    {
        app(TrackingDate::class)->validate($date);
        try {
            return DB::transaction(function () use ($user, $habitId, $date, $input, $expectedRevision, $versionId, $versionRevision, $timezone) {
                $user = $user->newQuery()->whereKey($user->id)->sharedLock()->firstOrFail();
                $habit = $user->habits()->lockForUpdate()->findOrFail($habitId);
                Gate::forUser($user)->authorize('view', $habit);
                $dates = app(TrackingDate::class);
                if ($reason = $dates->reason($user, $habit, $date)) {
                    throw ValidationException::withMessages(['form.status' => $reason]);
                }
                Gate::forUser($user)->authorize('record', [$habit, $date]);
                $version = $dates->version($habit, $date);
                $entry = $habit->entries()->where('entry_date', $date)->first();
                if ($entry) {
                    Gate::forUser($user)->authorize('update', $entry);
                }
                if (($entry?->revision) !== $expectedRevision || $version->id !== $versionId || $version->revision !== $versionRevision || $timezone !== $user->timezone) {
                    throw ValidationException::withMessages(['conflict' => 'This day or its settings changed. Reload the entry before saving again.']);
                }
                $data = HabitEntryForm::validateInput($input, $version);
                $na = $data['status'] === 'not_applicable';
                $entry ??= new HabitEntry;
                $entry->forceFill([
                    'habit_id' => $habit->id, 'user_id' => $user->id, 'habit_tracking_version_id' => $version->id,
                    'entry_date' => $date, 'status' => $data['status'],
                    'value' => $na ? $entry->value : ($data['value'] ?? null),
                    'note' => $data['note'] ?? null, 'not_applicable_reason' => $data['not_applicable_reason'] ?? null,
                    'revision' => $entry->exists ? $entry->revision + 1 : 1,
                ])->save();
                if ($version->type === HabitType::Checkpoint) {
                    $existing = $entry->checkpoints()->get()->keyBy('habit_tracking_checkpoint_id');
                    foreach ($version->checkpoints as $checkpoint) {
                        $row = $existing->get($checkpoint->id) ?? $entry->checkpoints()->make();
                        $completed = $na ? (bool) $row->is_completed : in_array($checkpoint->id, $data['completed'], false);
                        $row->forceFill([
                            'habit_tracking_checkpoint_id' => $checkpoint->id, 'is_completed' => $completed,
                            'completed_at' => $completed ? ($row->completed_at ?? now()) : null,
                        ])->save();
                    }
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['conflict' => 'This day was saved in another request. Reload the entry before saving again.']);
        }
    }
}
