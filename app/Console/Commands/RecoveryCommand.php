<?php

namespace App\Console\Commands;

use App\Services\Recovery\BackupManager;
use App\Services\Recovery\RecoveryFailure;
use App\Services\Recovery\RecoveryManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoveryCommand extends Command
{
    protected $signature = 'recovery:manage {operation : initialize, backup, purge, restore, reconcile, admit, or resolve-pending}
        {--backup= : Registered encrypted backup UUID}
        {--confirm-writers-stopped : Confirm schema changes and initialization writers are stopped}
        {--confirm-isolated : Confirm the target is isolated from traffic, workers, and outbound mail}
        {--confirm-source-stopped : Confirm the original installation is drained and will remain stopped}
        {--confirm-original-database : Confirm this is the authoritative original live database}';

    protected $description = 'Manage encrypted backups and fail-closed recovery on explicitly provisioned storage';

    public function handle(BackupManager $backups, RecoveryManager $recovery): int
    {
        try {
            switch ($this->argument('operation')) {
                case 'initialize':
                    $this->requireConfirmation('confirm-writers-stopped');
                    $recovery->initialize();
                    break;
                case 'backup':
                    $this->requireConfirmation('confirm-writers-stopped');
                    $id = $backups->create();
                    // An artifact selector is not an account identifier/secret.
                    $this->info('Encrypted backup created: '.$id);
                    break;
                case 'purge':
                    $count = $backups->purge();
                    $this->info('Expired managed artifacts removed: '.$count);
                    break;
                case 'restore':
                    $this->requireConfirmation('confirm-isolated');
                    $recovery->restore((string) $this->option('backup'));
                    break;
                case 'reconcile':
                    $this->requireConfirmation('confirm-isolated');
                    $recovery->reconcile();
                    break;
                case 'admit':
                    $this->requireConfirmation('confirm-isolated');
                    $this->requireConfirmation('confirm-source-stopped');
                    $recovery->admit();
                    break;
                case 'resolve-pending':
                    $this->requireConfirmation('confirm-original-database');
                    $recovery->resolvePending();
                    break;
                default:
                    throw new RecoveryFailure;
            }
            $this->info('Operation completed. Recovery mode continues to block HTTP until explicitly changed to live.');

            return self::SUCCESS;
        } catch (Throwable) {
            // Do not let Symfony render SQL bindings, paths or exception chains,
            // including when operators pass verbose console flags.
            Log::error('Backup or recovery operation failed. Operator review required.');
            $this->error('Operation failed safely. Access remains subject to recovery admission. Review configuration and restricted state.');

            return self::FAILURE;
        }
    }

    private function requireConfirmation(string $option): void
    {
        if (! $this->option($option)) {
            throw new RecoveryFailure;
        }
    }
}
