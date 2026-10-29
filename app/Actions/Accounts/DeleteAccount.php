<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function handle(Request $request, #[\SensitiveParameter] array $input): void
    {
        $user = $request->user();
        abort_unless($user && Auth::id() === $user->id, 403);
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'max:1024'],
            'acknowledge_deletion' => ['accepted'],
            'account_id' => ['required', 'integer'],
        ], ['acknowledge_deletion.accepted' => 'Confirm that account deletion is permanent and cannot be undone.'])->validate();
        // This is a stale-form check, never a selector for the account to delete.
        abort_unless((string) $data['account_id'] === (string) $user->id, 403);
        abort_unless(SessionSecurity::supported()
            && config('auth.passwords.'.config('fortify.passwords').'.driver', 'database') === 'database'
            && (config('auth.passwords.'.config('fortify.passwords').'.connection') ?? config('database.default')) === config('database.default'), 503);

        try {
            DB::transaction(function () use ($user, $request, $data) {
                $fresh = User::whereKey($user->id)->lockForUpdate()->first();
                $origin = $request->session()->get('revocation_origin', hash('sha256', $request->session()->getId()));
                if (! $fresh || ! app(SessionSecurity::class)->admitted($user, $request)
                    || SessionSecurity::revoked(hash('sha256', $request->session()->getId()), $origin)) {
                    throw ValidationException::withMessages(['current_password' => 'Your session changed. Sign in again before deleting your account.']);
                }
                Gate::forUser($user)->authorize('delete', $fresh);
                if (! Hash::check($data['current_password'], $fresh->password)) {
                    throw ValidationException::withMessages(['current_password' => 'Your current password could not be verified.']);
                }
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
                Password::broker(config('fortify.passwords'))->deleteToken($fresh);
                DB::table('pending_email_changes')->where('user_id', $fresh->id)->delete();
                DB::table('sessions')->where('user_id', $fresh->id)->delete();
                // Pending state and revocation hashes also have cascading user FKs.
                // The session writer rejects a missing user under this same lock.
                $fresh->delete();
            }, 3);
        } catch (QueryException) {
            // Never expose SQL bindings or private record content on a failed cleanup.
            throw ValidationException::withMessages(['deletion' => 'Your account could not be deleted. Nothing was removed. Please try again or contact the service operator.']);
        }
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
