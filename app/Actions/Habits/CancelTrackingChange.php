<?php

namespace App\Actions\Habits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelTrackingChange
{
    public function handle(User $user, int $habitId): void
    {
        DB::transaction(function () use ($user, $habitId) {
            $user = $user->newQuery()->whereKey($user->id)->sharedLock()->firstOrFail();
            $habit = $user->habits()->lockForUpdate()->findOrFail($habitId);
            Gate::forUser($user)->authorize('update', $habit);
            $pending = $habit->versions()->where('effective_date', '>', $user->localToday())->latest('effective_date')->first();
            $current = $habit->versions()->where('effective_date', '<=', $user->localToday())->latest('effective_date')->first();
            if (! $pending || ! $current || $pending->entries()->exists() || ! $habit->entries()->exists()) {
                throw ValidationException::withMessages(['form.type' => 'This tracking change can no longer be cancelled. Reload the habit.']);
            }
            $habit->forceFill($current->only(SyncTrackingVersion::FIELDS))->save();
            $habit->checkpoints()->delete();
            foreach ($current->checkpoints as $checkpoint) {
                $habit->checkpoints()->create(['label' => $checkpoint->label, 'sort_order' => $checkpoint->sort_order]);
            }
            $pending->delete();
        });
    }
}
