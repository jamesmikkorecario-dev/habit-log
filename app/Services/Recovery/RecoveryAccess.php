<?php

namespace App\Services\Recovery;

use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoveryAccess
{
    public function assertAdmitted(DeletionLedger $ledger, bool $locked = true): void
    {
        try {
            if (config('recovery.mode') !== 'live') {
                throw new RecoveryFailure;
            }
            $external = $locked ? $ledger->state() : $ledger->snapshot();
            $local = DB::table('recovery_state')->where('id', 1)->first();
            if (! $local || $local->status !== 'ready' || $local->ledger_id !== $external['id']
                || $local->generation !== $external['active']) {
                throw new RecoveryFailure;
            }
        } catch (Throwable) {
            throw new RecoveryFailure;
        }
    }
}
