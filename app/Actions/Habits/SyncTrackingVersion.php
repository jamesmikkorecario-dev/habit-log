<?php

namespace App\Actions\Habits;

use App\Models\Habit;
use App\Models\HabitTrackingVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// Called only inside a transaction holding the parent habit lock (or during creation).
class SyncTrackingVersion
{
    public const FIELDS = ['type', 'goal_value', 'goal_unit', 'start_date', 'end_date'];

    public function handle(User $user, Habit $habit): void
    {
        $hasEntries = $habit->entries()->exists();
        $latest = $habit->versions()->latest('effective_date')->first();
        $checkpoints = $habit->checkpoints()->get();
        $values = $habit->only(self::FIELDS);
        if ($latest && $latest->only(self::FIELDS) == $values && $latest->checkpoints->map->only(['label', 'sort_order'])->all() == $checkpoints->map->only(['label', 'sort_order'])->all()) {
            return;
        }
        $today = $user->localToday();
        $tomorrow = now($user->timezone ?? 'UTC')->addDay()->toDateString();
        $pending = $hasEntries && $latest && $latest->effective_date->toDateString() > $today ? $latest : null;
        if ($pending && ($pending->effective_date->toDateString() !== $tomorrow || $pending->entries()->exists())) {
            throw ValidationException::withMessages(['form.type' => 'Keep or cancel the pending tracking change before submitting another change.']);
        }
        $version = $hasEntries ? ($pending ?? new HabitTrackingVersion) : ($latest ?? new HabitTrackingVersion);
        $version->forceFill($values + [
            'habit_id' => $habit->id, 'effective_date' => $hasEntries ? $tomorrow : $habit->start_date->toDateString(),
            'revision' => $version->exists ? $version->revision + 1 : 1,
        ])->save();
        $version->checkpoints()->delete();
        foreach ($checkpoints as $checkpoint) {
            $version->checkpoints()->make()->forceFill([
                'habit_checkpoint_id' => $checkpoint->id, 'label' => $checkpoint->label, 'sort_order' => $checkpoint->sort_order,
            ])->save();
        }
    }
}
