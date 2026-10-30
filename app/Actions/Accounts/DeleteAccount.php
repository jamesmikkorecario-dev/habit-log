<?php

namespace App\Actions\Accounts;

use App\Models\User;
use App\Services\Recovery\DeletionLedger;
use App\Services\Recovery\RecoveryAccess;
use App\Services\Recovery\RecoveryFailure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function handle(Request $request, #[\SensitiveParameter] array $input): void
    {
        $user = $request->user();
        abort_unless($user && Auth::id() === $user->id, 403);
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'max:1024'],
            'acknowledge_deletion' => ['accepted'],
            'account_id' => ['required', 'integer'],
        ], ['acknowledge_deletion.accepted' => 'Confirm that account deletion is permanent and cannot be undone.'])->validate();
        // This is a stale-form check, never a selector for the account to delete.
        abort_unless((string) $data['account_id'] === (string) $user->id, 403);
        abort_unless(SessionSecurity::supported()
            && config('auth.passwords.'.config('fortify.passwords').'.driver', 'database') === 'database'
            && (config('auth.passwords.'.config('fortify.passwords').'.connection') ?? config('database.default')) === config('database.default'), 503);

        $ledger = app(DeletionLedger::class);
        if ($ledger->enabled() && DB::connection()->transactionLevel() !== 0) {
            throw new RecoveryFailure;
        }
        $operation = function () use ($ledger, $user, $request, $data): void {
            if ($ledger->enabled()) {
                app(RecoveryAccess::class)->assertAdmitted($ledger);
            }
            $deletedUuid = null;
            DB::transaction(function () use ($user, $request, $data, $ledger, &$deletedUuid) {
                $fresh = User::whereKey($user->id)->lockForUpdate()->first();
                $origin = $request->session()->get('revocation_origin', hash('sha256', $request->session()->getId()));
                if (! $fresh || ! app(SessionSecurity::class)->admitted($user, $request)
                    || SessionSecurity::revoked(hash('sha256', $request->session()->getId()), $origin)) {
                    throw ValidationException::withMessages(['current_password' => 'Your session changed. Sign in again before deleting your account.']);
                }
                Gate::forUser($user)->authorize('delete', $fresh);
                if (! Hash::check($data['current_password'], $fresh->password)) {
                    throw ValidationException::withMessages(['current_password' => 'Your current password could not be verified.']);
                }
                app(EraseAccount::class)->handle($fresh);
                if ($ledger->enabled()) {
                    $ledger->deletion($fresh->recovery_uuid, 'pending');
                    $deletedUuid = $fresh->recovery_uuid;
                }
            }, 3);
            if ($deletedUuid !== null) {
                try {
                    $ledger->deletion($deletedUuid, 'committed');
                } catch (\Throwable) {
                    // Live deletion committed. Sign out normally; pending intent
                    // prevents recovery admission until the operator reconciles it.
                    Log::error('Deletion completion record unavailable; recovery remains blocked.');
                }
            }
        };
        try {
            $ledger->enabled() ? $ledger->exclusive($operation) : $operation();
        } catch (QueryException) {
            // Never expose SQL bindings or private record content on a failed cleanup.
            if ($ledger->enabled()) {
                // A lost commit acknowledgment can be ambiguous. The durable
                // pending intent must be resolved from the authoritative DB;
                // do not promise the user that nothing was removed.
                Log::error('Account deletion database outcome requires review of restricted deletion state.');
                throw ValidationException::withMessages(['deletion' => 'Your account deletion could not be confirmed. Please try signing in again or contact the service operator.']);
            }
            throw ValidationException::withMessages(['deletion' => 'Your account could not be deleted. Nothing was removed. Please try again or contact the service operator.']);
        }
    }
}
