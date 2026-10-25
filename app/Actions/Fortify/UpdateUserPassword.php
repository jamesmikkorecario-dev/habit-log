<?php

namespace App\Actions\Fortify;

use App\Actions\Accounts\ReplacePassword;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function update(User $user, #[\SensitiveParameter] array $input): void
    {
        abort_unless(Auth::id() === $user->getAuthIdentifier(), 403);

        Validator::make($input, [
            'current_password' => ['required', 'string'],
            'password' => ['bail', ...$this->passwordRules(), function (string $attribute, mixed $value, \Closure $fail): void {
                if (str_contains($value, "\0")) {
                    $fail(__('The password contains an unsupported character.'));
                } elseif (config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
                    // Bcrypt otherwise silently ignores bytes beyond this limit.
                    $fail(__('The password is too long. Please choose a shorter password.'));
                }
            }],
        ])->validateWithBag('updatePassword');

        $fresh = $user->getConnection()->transaction(function () use ($user, $input): User {
            $fresh = User::query()->lockForUpdate()->find($user->getKey());

            // Even a reset to the same password invalidates an admitted request.
            if (! $fresh || ! hash_equals($user->getAuthPassword(), $fresh->getAuthPassword())
                || ! Hash::check($input['current_password'], $fresh->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'current_password' => __('Your current password could not be verified. Reload this page and try again.'),
                ])->errorBag('updatePassword');
            }

            app(ReplacePassword::class)->handle($fresh, $input['password'], request()->session()->getId());

            return $fresh;
        });

        // Fortify and AuthenticateSession must see the committed hash when this
        // request finishes. Do not refresh from a later, unrelated write.
        $user->setRawAttributes($fresh->getAttributes(), true);
    }
}
