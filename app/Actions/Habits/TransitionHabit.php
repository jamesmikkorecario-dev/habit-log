<?php

namespace App\Actions\Habits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TransitionHabit
{
    public function handle(User $user, int $id, string $action): void
    {
        abort_unless(in_array($action, ['archive', 'unarchive', 'delete', 'restore'], true), 403);
        DB::transaction(function () use ($user, $id, $action) {
            $user = $user->newQuery()->whereKey($user->id)->sharedLock()->firstOrFail();
            $query = $user->habits();
            if ($action === 'restore') {
                $query->onlyTrashed();
            }
            $habit = $query->lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize($action, $habit);
            $reason = in_array($action, ['archive', 'unarchive'], true) ? 'archive' : 'trash';
            if (in_array($action, ['archive', 'delete'], true)) {
                $habit->suspensions()->make()->forceFill([
                    'reason' => $reason, 'start_date' => now($user->timezone ?? 'UTC')->addDay()->toDateString(),
                ])->save();
            } else {
                $period = $habit->suspensions()->where('reason', $reason)->whereNull('end_date')->first();
                if ($period) {
                    $period->forceFill(['end_date' => max($period->start_date->toDateString(), $user->localToday())])->save();
                }
            }
            match ($action) {
                'archive' => $habit->forceFill(['archived_at' => now()])->save(),
                'unarchive' => $habit->forceFill(['archived_at' => null])->save(),
                'delete' => $habit->delete(), 'restore' => $habit->restore(),
            };
        });
    }
}
