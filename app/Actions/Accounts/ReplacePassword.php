<?php

namespace App\Actions\Accounts;

use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ReplacePassword
{
    /** The caller must hold the user's row lock inside a transaction. */
    public function handle(User $user, #[\SensitiveParameter] string $password, ?string $keepSession = null): void
    {
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        Password::broker(config('fortify.passwords'))->deleteToken($user);
        PendingEmailChange::where('user_id', $user->id)->delete();

        // Explicit deletion also removes legacy sessions without a hash marker.
        // AuthenticateSession rejects stale sessions written back after this commit.
        if (config('session.driver') === 'database') {
            if (SessionSecurity::supported()) {
                SessionSecurity::revokeRows($user, DB::table('sessions')->where('user_id', $user->id)
                    ->when($keepSession !== null, fn ($query) => $query->where('id', '!=', $keepSession))->get(['id', 'origin_hash']));
            }
            DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $user->getAuthIdentifier())
                ->when($keepSession !== null, fn ($query) => $query->where('id', '!=', $keepSession))
                ->delete();
        }
    }
}
