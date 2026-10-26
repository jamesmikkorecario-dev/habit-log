<?php

namespace App\Actions\Accounts;

use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailChanges
{
    public const INVALID = 'This email change is no longer available. Return to Security Settings to request a new link.';

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message])->errorBag('emailChange');
    }

    private function owner(User $user, #[\SensitiveParameter] ?string $password = null): User
    {
        abort_unless(Auth::id() === $user->id, 403);
        $fresh = User::query()->lockForUpdate()->find($user->id);
        if (! $fresh || ! hash_equals($user->password, $fresh->password) || $user->email !== $fresh->email) {
            $this->fail('email_change', self::INVALID);
        }
        if ($password !== null && ! Hash::check($password, $fresh->password)) {
            $this->fail('current_password', 'Your current password could not be verified.');
        }

        return $fresh;
    }

    /** Replace/resend always creates a new generation. Mail is delivered after commit. */
    public function request(User $user, #[\SensitiveParameter] array $input, bool $resend = false): array
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string'],
            'new_email' => $resend ? ['prohibited'] : ['required', 'string', 'email', 'max:255'],
            'generation' => ['required', 'string', 'max:36'],
        ])->validateWithBag('emailChange');

        return $user->getConnection()->transaction(function () use ($user, $data, $resend) {
            $fresh = $this->owner($user, $data['current_password']);
            $pending = PendingEmailChange::where('user_id', $fresh->id)->lockForUpdate()->first();
            if (($pending?->id ?? 'none') !== $data['generation'] || ($resend && ! $pending)) {
                $this->fail('email_change', self::INVALID);
            }
            if ($pending && $pending->last_sent_at->addMinute()->isFuture()) {
                $this->fail('email_change', 'Please wait one minute before requesting another email.');
            }
            $email = $resend ? $pending->new_email : $data['new_email'];
            if (strcasecmp($email, $fresh->email) === 0) {
                $this->fail('new_email', 'Choose a different email address.');
            }
            $pending?->delete();
            $token = bin2hex(random_bytes(32));
            $pending = PendingEmailChange::create([
                'id' => (string) Str::uuid(), 'user_id' => $fresh->id,
                'source_email' => $fresh->email, 'new_email' => $email,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'last_sent_at' => now(),
            ]);
            $url = URL::temporarySignedRoute('settings.email.verify', $pending->expires_at, [
                'generation' => $pending->id, 'owner' => $fresh->id, 'token' => $token,
            ]);

            return [$pending, $url];
        });
    }

    public function cancel(User $user, string $generation): void
    {
        $user->getConnection()->transaction(function () use ($user, $generation) {
            $fresh = $this->owner($user);
            $pending = PendingEmailChange::where('user_id', $fresh->id)->lockForUpdate()->first();
            if (! $pending || $pending->id !== $generation) {
                $this->fail('email_change', self::INVALID);
            }
            $pending->delete();
        });
    }

    public function pending(User $user, array $link, bool $lock = false): ?PendingEmailChange
    {
        $pending = PendingEmailChange::where('user_id', $user->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $pending || ! is_string($link['token'] ?? null) || ! is_string($link['generation'] ?? null)
            || ! is_scalar($link['owner'] ?? null) || (string) $link['owner'] !== (string) $user->id
            || $pending->id !== $link['generation'] || $pending->source_email !== $user->email
            || $pending->expires_at->lessThanOrEqualTo(now())
            || ! hash_equals($pending->token_hash, hash('sha256', $link['token']))) {
            return null;
        }

        return $pending;
    }

    public function confirm(User $user, array $link, #[\SensitiveParameter] array $input, string $sessionId): string
    {
        $data = Validator::make($input, ['current_password' => ['required', 'string']])->validateWithBag('emailChange');
        try {
            $fresh = $user->getConnection()->transaction(function () use ($user, $link, $data, $sessionId) {
                $fresh = $this->owner($user, $data['current_password']);
                $pending = $this->pending($fresh, $link, true);
                if (! $pending) {
                    $this->fail('email_change', self::INVALID);
                }
                // Availability is disclosed only after proof of mailbox control.
                // The database unique index is authoritative for concurrent claims.
                if (User::where('email', $pending->new_email)->whereKeyNot($fresh->id)->exists()) {
                    $this->fail('email_change', 'This address cannot be used. Your current email has not changed. Choose another address in Security Settings.');
                }
                Password::broker(config('fortify.passwords'))->deleteToken($fresh);
                $fresh->forceFill(['email' => $pending->new_email, 'email_verified_at' => now()])->save();
                app(ReplacePassword::class)->handle($fresh, $data['current_password'], $sessionId);

                return $fresh;
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('email_change', 'This address cannot be used. Your current email has not changed. Choose another address in Security Settings.');
        }
        $oldEmail = $user->email;
        $user->setRawAttributes($fresh->getAttributes(), true);

        return $oldEmail;
    }
}
