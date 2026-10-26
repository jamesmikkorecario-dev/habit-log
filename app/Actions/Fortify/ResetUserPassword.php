<?php

namespace App\Actions\Fortify;

use App\Actions\Accounts\ReplacePassword;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, #[\SensitiveParameter] array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $fresh = $user->getConnection()->transaction(function () use ($user, $input): User {
            $fresh = User::query()->lockForUpdate()->find($user->getKey());
            // The broker validated before acquiring this lock. Recheck after any
            // concurrent credential change and consume the token atomically.
            if (! $fresh || $fresh->email !== $user->email || ! hash_equals($user->getAuthPassword(), $fresh->getAuthPassword())
                || ! is_string($input['token'] ?? null)
                || ! Password::broker(config('fortify.passwords'))->tokenExists($fresh, $input['token'])) {
                throw ValidationException::withMessages(['email' => __(Password::INVALID_TOKEN)]);
            }

            app(ReplacePassword::class)->handle($fresh, $input['password']);

            return $fresh;
        });

        $user->setRawAttributes($fresh->getAttributes(), true);
    }
}
