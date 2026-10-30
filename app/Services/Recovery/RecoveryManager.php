<?php

namespace App\Services\Recovery;

use App\Actions\Accounts\EraseAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoveryManager
{
    public function __construct(private DeletionLedger $ledger, private PrivateFiles $files,
        private DatabaseSnapshot $snapshot, private BackupManager $backups, private EncryptedArchive $archive) {}

    public function initialize(): void
    {
        if (config('recovery.mode') !== 'live') {
            throw new RecoveryFailure;
        }
        $connection = $this->snapshot->connection();
        $this->snapshot->schema($connection);
        if ($connection->table('recovery_state')->exists()) {
            throw new RecoveryFailure;
        }
        $this->ledger->initialize();
        $this->ledger->exclusive(function (DeletionLedger $ledger) use ($connection) {
            $generation = (string) Str::uuid();
            $connection->table('recovery_state')->insert(['id' => 1, 'ledger_id' => $ledger->state()['id'],
                'generation' => $generation, 'status' => 'ready', 'revision' => $ledger->state()['revision']]);
            $ledger->activate($generation);
        });
    }

    public function restore(string $id): void
    {
        $this->assertRecoveryTarget();
        $this->ledger->exclusive(function (DeletionLedger $ledger) use ($id) {
            $ledger->assertComplete();
            $connection = $this->snapshot->connection();
            $header = $this->backups->inspect($id, $ledger);
            $schema = $this->snapshot->schema($connection);
            if ($header['schema'] !== $schema['hash'] || $header['source_database'] === $connection->getDatabaseName()) {
                throw new RecoveryFailure;
            }
            $this->snapshot->assertEmpty($connection);
            // Durable quarantine precedes data import; failures never mark ready.
            $connection->table('recovery_state')->insert(['id' => 1, 'ledger_id' => $ledger->state()['id'],
                'generation' => (string) Str::uuid(), 'backup_id' => $id, 'status' => 'importing', 'revision' => 0]);
            foreach (DatabaseSnapshot::TABLES as $table) {
                $next = $header['sequences'][$table] ?? null;
                if ($next !== null) {
                    if (! ctype_digit((string) $next) || (int) $next < 1) {
                        throw new RecoveryFailure;
                    }
                    // DDL is confined to empty tables before the import transaction.
                    $connection->statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.(int) $next);
                }
            }
            $connection->transaction(function () use ($connection, $schema, $id, $ledger) {
                $this->snapshot->import($this->archive->decrypt($this->backups->path($id)), $connection, $schema);
                $this->reconcileLocked($ledger);
            });
        });
    }

    public function reconcile(): void
    {
        $this->assertRecoveryTarget();
        $this->ledger->exclusive(function (DeletionLedger $ledger) {
            $this->assertRestored($ledger);
            DB::transaction(fn () => $this->reconcileLocked($ledger));
        });
    }

    public function admit(): void
    {
        $this->assertRecoveryTarget();
        $this->ledger->exclusive(function (DeletionLedger $ledger) {
            $this->assertRestored($ledger);
            $generation = (string) Str::uuid();
            DB::transaction(function () use ($ledger, $generation) {
                $this->reconcileLocked($ledger);
                DB::table('recovery_state')->where('id', 1)->update(['generation' => $generation]);
            });
            // Until this durable publication succeeds, the new generation fails
            // admission. Afterwards the old installation fails admission.
            $ledger->activate($generation);
            DB::table('recovery_state')->where('id', 1)->update(['status' => 'ready']);
        });
    }

    public function resolvePending(): void
    {
        $this->snapshot->connection();
        $this->ledger->exclusive(function (DeletionLedger $ledger) {
            // Never resolve an ambiguous live commit from an old restored copy.
            app(RecoveryAccess::class)->assertAdmitted($ledger);
            DB::transaction(function () use ($ledger) {
                foreach ($ledger->state()['deletions'] as $uuid => $status) {
                    if ($status === 'pending') {
                        $exists = User::where('recovery_uuid', $uuid)->lockForUpdate()->first() !== null;
                        $ledger->deletion($uuid, $exists ? 'cancelled' : 'committed');
                    }
                }
            });
        });
    }

    private function assertRecoveryTarget(): void
    {
        $connection = $this->snapshot->connection();
        if (config('recovery.mode') !== 'recovery' || ! config('recovery.target_database')
            || config('recovery.target_database') !== $connection->getDatabaseName()
            || $connection->selectOne('SELECT DATABASE() AS name')->name !== config('recovery.target_database')
            || (int) $connection->selectOne('SELECT @@foreign_key_checks AS enabled')->enabled !== 1) {
            throw new RecoveryFailure;
        }
    }

    private function assertRestored(DeletionLedger $ledger): void
    {
        $ledger->assertComplete();
        $state = DB::table('recovery_state')->where('id', 1)->first();
        if (! $state || $state->status !== 'reconciled'
            || $state->ledger_id !== $ledger->state()['id'] || ! $state->backup_id) {
            throw new RecoveryFailure;
        }
        $this->backups->inspect($state->backup_id, $ledger);
    }

    private function reconcileLocked(DeletionLedger $ledger): void
    {
        $ledger->assertComplete();
        foreach ($ledger->state()['deletions'] as $uuid => $status) {
            if ($status !== 'committed') {
                continue;
            }
            $user = User::where('recovery_uuid', $uuid)->lockForUpdate()->first();
            if ($user) {
                app(EraseAccount::class)->handle($user);
            }
            if (User::where('recovery_uuid', $uuid)->exists()) {
                throw new RecoveryFailure;
            }
        }
        // No pre-recovery authentication survives, including email-keyed tokens
        // and anonymous/stale session rows that do not have an ownership FK.
        foreach (['sessions', 'password_reset_tokens', 'pending_email_changes', 'session_revocations'] as $table) {
            DB::table($table)->delete();
            if (DB::table($table)->exists()) {
                throw new RecoveryFailure;
            }
        }
        DB::table('users')->update(['remember_token' => null]);
        DB::table('recovery_state')->where('id', 1)->update(['status' => 'reconciled', 'revision' => $ledger->state()['revision']]);
    }
}
