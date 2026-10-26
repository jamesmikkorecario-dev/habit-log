<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SessionSecurity
{
    public static function supported(): bool
    {
        return config('session.driver') === 'database' && config('session.table') === 'sessions'
            && (config('session.connection') ?? config('database.default')) === config('database.default');
    }

    public static function revoked(string ...$hashes): bool
    {
        return DB::table('session_revocations')->whereIn('session_hash', $hashes)->exists();
    }

    public static function deny(Request $request): never
    {
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        throw new AuthenticationException;
    }

    public function admitted(User $user, Request $request): bool
    {
        $fresh = User::whereKey($user->id)->lockForUpdate()->first();
        if (! $fresh || ! hash_equals($fresh->password, $user->password) || $fresh->email !== $user->email) {
            return false;
        }
        $remember = $request->attributes->get('session_recalled_token');

        return $remember === null || hash_equals((string) $fresh->remember_token, $remember);
    }

    public function login(Login $event): void
    {
        if (! self::supported() || ! request()->hasSession()) {
            return;
        }
        $request = request();
        $guard = Auth::guard($event->guard);
        if ($guard->viaRemember()) {
            $request->attributes->set('session_recalled_token', (string) $event->user->remember_token);
        }
        $accepted = DB::transaction(function () use ($event, $request, $guard) {
            if (! $this->admitted($event->user, $request)) {
                return false;
            }
            $request->session()->put('revocation_origin', hash('sha256', $request->session()->getId()));
            $request->session()->put('password_hash_'.$event->guard, $guard->hashPasswordForCookie($event->user->password));

            return true;
        });
        if (! $accepted) {
            self::deny($request);
        }
    }

    /** Caller holds the account lock. Keep tombstones until account removal. */
    public static function revokeRows(User $user, $rows): void
    {
        foreach ($rows as $row) {
            foreach (array_unique([hash('sha256', $row->id), $row->origin_hash ?? hash('sha256', $row->id)]) as $hash) {
                DB::table('session_revocations')->insertOrIgnore([
                    'session_hash' => $hash, 'user_id' => $user->id, 'revoked_at' => now(),
                ]);
            }
            DB::table('sessions')->where('id', $row->id)->where('user_id', $user->id)->delete();
        }
    }
}
