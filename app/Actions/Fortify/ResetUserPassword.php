<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->getConnection()->transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => $input['password'],
                'remember_token' => Str::random(60),
            ])->save();

            // Remove even pre-existing sessions that have no password-hash marker.
            // AuthenticateSession also rejects stale/in-flight sessions on reuse.
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table'))
                    ->where('user_id', $user->getAuthIdentifier())
                    ->delete();
            }
        });
    }
}
