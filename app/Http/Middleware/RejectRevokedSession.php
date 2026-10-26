<?php

namespace App\Http\Middleware;

use App\Actions\Accounts\SessionSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RejectRevokedSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! SessionSecurity::supported() || ! $request->hasSession()) {
            return $next($request);
        }
        $hash = hash('sha256', $request->session()->getId());
        $origin = $request->session()->get('revocation_origin', $hash);
        // Check before the guard can turn an old remember cookie into a new ID.
        if (SessionSecurity::revoked($hash, $origin)) {
            SessionSecurity::deny($request);
        }
        if ($user = $request->user()) {
            $accepted = DB::transaction(function () use ($request, $user, $hash, $origin) {
                if (! app(SessionSecurity::class)->admitted($user, $request) || SessionSecurity::revoked($hash, $origin)) {
                    return false;
                }
                if (! $request->session()->has('revocation_origin')) {
                    $request->session()->put('revocation_origin', $hash);
                }

                return true;
            });
            if (! $accepted) {
                SessionSecurity::deny($request);
            }
        }

        return $next($request);
    }
}
