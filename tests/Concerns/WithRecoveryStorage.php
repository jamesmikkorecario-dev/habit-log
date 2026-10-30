<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;

trait WithRecoveryStorage
{
    protected string $recoveryRoot;

    protected function createRecoveryStorage(): void
    {
        $this->recoveryRoot = sys_get_temp_dir().'/habit-recovery-test-'.Str::uuid();
        mkdir($this->recoveryRoot, 0700);
        foreach (['backups', 'ledger', 'keys'] as $part) {
            mkdir($this->recoveryRoot.'/'.$part, 0700);
        }
        file_put_contents($this->recoveryRoot.'/keys/key', random_bytes(32));
        chmod($this->recoveryRoot.'/keys/key', 0600);
        config(['recovery.mode' => 'live', 'recovery.backup_directory' => $this->recoveryRoot.'/backups',
            'recovery.ledger_directory' => $this->recoveryRoot.'/ledger', 'recovery.key_file' => $this->recoveryRoot.'/keys/key']);
    }

    protected function removeRecoveryStorage(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->recoveryRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->recoveryRoot);
    }
}
