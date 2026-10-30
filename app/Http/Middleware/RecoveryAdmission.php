<?php

namespace App\Http\Middleware;

use App\Services\Recovery\DeletionLedger;
use App\Services\Recovery\RecoveryAccess;
use App\Services\Recovery\RecoveryFailure;
use Closure;
use Illuminate\Http\Request;

final class RecoveryAdmission
{
    public function handle(Request $request, Closure $next)
    {
        if (config('recovery.mode') === 'off' || $request->is('up')) {
            return $next($request);
        }
        try {
            app(RecoveryAccess::class)->assertAdmitted(app(DeletionLedger::class), locked: false);
        } catch (RecoveryFailure) {
            return response('Recovery safeguards are active. Application access is unavailable.', 503)
                ->header('Cache-Control', 'private, no-store')->header('Retry-After', '60');
        }

        return $next($request);
    }
}
