<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Caller must hold the owner lock inside a transaction. */
class EraseAccount
{
    public function handle(User $fresh): void
    {
        $this->assertOwnership($fresh->id);
        $habits = DB::table('habits')->where('user_id', $fresh->id)->select('id');
        DB::table('habit_entries')->where('user_id', $fresh->id)->delete();
        DB::table('habit_tracking_versions')->whereIn('habit_id', $habits)->delete();
        DB::table('habit_suspensions')->whereIn('habit_id', $habits)->delete();
        DB::table('habits')->where('user_id', $fresh->id)->delete();
        DB::table('categories')->where('user_id', $fresh->id)->where('is_system', false)->delete();
        DB::table('journals')->where('user_id', $fresh->id)->delete();
        DB::table('follows')->where('follower_id', $fresh->id)->orWhere('followed_id', $fresh->id)->delete();
        // Delete only the currently owned address's token while the user still exists.
        // Use the caller's transaction/connection, including an isolated restore.
        // The fresh owner is already exclusively locked and still exists here.
        DB::table(config('auth.passwords.'.config('fortify.passwords').'.table'))
            ->where('email', $fresh->getEmailForPasswordReset())->delete();
        DB::table('pending_email_changes')->where('user_id', $fresh->id)->delete();
        DB::table('sessions')->where('user_id', $fresh->id)->delete();
        // Pending state and revocation hashes also have cascading user FKs.
        // The session writer rejects a missing user under this same lock.
        $fresh->delete();
    }

    private function assertOwnership(int $id): void
    {
        $habits = DB::table('habits')->where('user_id', $id)->select('id');
        $versions = DB::table('habit_tracking_versions')->whereIn('habit_id', $habits)->select('id');
        $snapshots = DB::table('habit_tracking_checkpoints')->whereIn('habit_tracking_version_id', $versions)->select('id');
        $entries = DB::table('habit_entries')->where('user_id', $id)->select('id');

        $badEntries = DB::table('habit_entries as e')->join('habits as h', 'h.id', '=', 'e.habit_id')
            ->join('habit_tracking_versions as v', 'v.id', '=', 'e.habit_tracking_version_id')
            ->where(fn ($q) => $q->where('e.user_id', $id)->orWhere('h.user_id', $id)->orWhereIn('v.id', $versions))
            ->where(fn ($q) => $q->whereColumn('e.user_id', '!=', 'h.user_id')->orWhereColumn('v.habit_id', '!=', 'e.habit_id'))->exists();
        $badCompletions = DB::table('habit_checkpoint_entries as c')->join('habit_entries as e', 'e.id', '=', 'c.habit_entry_id')
            ->join('habit_tracking_checkpoints as s', 's.id', '=', 'c.habit_tracking_checkpoint_id')
            ->where(fn ($q) => $q->whereIn('e.id', $entries)->orWhereIn('s.id', $snapshots))
            ->whereColumn('e.habit_tracking_version_id', '!=', 's.habit_tracking_version_id')->exists();
        $badSnapshots = DB::table('habit_tracking_checkpoints as s')->join('habit_tracking_versions as v', 'v.id', '=', 's.habit_tracking_version_id')
            ->join('habit_checkpoints as c', 'c.id', '=', 's.habit_checkpoint_id')
            ->where(fn ($q) => $q->whereIn('v.habit_id', $habits)->orWhereIn('c.habit_id', $habits))
            ->whereColumn('v.habit_id', '!=', 'c.habit_id')->exists();
        $badCategories = DB::table('habits as h')->join('categories as c', 'c.id', '=', 'h.category_id')
            ->where(fn ($q) => $q->where('c.user_id', $id)->orWhere('h.user_id', $id))
            ->whereNotNull('c.user_id')->whereColumn('c.user_id', '!=', 'h.user_id')->exists();
        if ($badEntries || $badCompletions || $badSnapshots || $badCategories) {
            throw ValidationException::withMessages(['deletion' => 'Your account could not be deleted because some records need review. Nothing was removed. Contact the service operator.']);
        }
    }
}
