<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;

class AccountTokenRepository extends DatabaseTokenRepository
{
    private function current(CanResetPassword $user): bool
    {
        $fresh = User::query()->lockForUpdate()->find($user->getAuthIdentifier());

        return $fresh && $fresh->email === $user->getEmailForPasswordReset()
            && hash_equals($fresh->password, $user->getAuthPassword());
    }

    public function create(CanResetPassword $user)
    {
        return $this->connection->transaction(function () use ($user) {
            // A broker lookup may precede an email change. Never create a token
            // for an identity the admitted owner no longer holds. Keep the public
            // broker response uniform; any already-scheduled stale mail is inert.
            return $this->current($user) ? parent::create($user) : $this->createNewToken();
        });
    }

    public function delete(CanResetPassword $user)
    {
        $this->connection->transaction(function () use ($user) {
            // Fortify's post-action cleanup can run after another credential
            // change. Do not delete a newly acquired owner's recovery token.
            if ($this->current($user)) {
                parent::delete($user);
            }
        });
    }
}
