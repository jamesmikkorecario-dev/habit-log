<?php

namespace Tests\Feature\Recovery;

use App\Services\Recovery\DeletionLedger;
use App\Services\Recovery\EncryptedArchive;
use App\Services\Recovery\PrivateFiles;
use App\Services\Recovery\RecoveryFailure;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StorageSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/habit-recovery-test-'.Str::uuid();
        mkdir($this->root, 0700);
        foreach (['backups', 'ledger', 'keys'] as $part) {
            mkdir($this->root.'/'.$part, 0700);
        }
        file_put_contents($this->root.'/keys/key', random_bytes(32));
        chmod($this->root.'/keys/key', 0600);
        config(['recovery.mode' => 'live', 'recovery.backup_directory' => $this->root.'/backups',
            'recovery.ledger_directory' => $this->root.'/ledger', 'recovery.key_file' => $this->root.'/keys/key']);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_archive_is_encrypted_private_and_round_trips_streamed_records(): void
    {
        $records = [['email' => 'synthetic-secret@example.test'], ['note' => str_repeat('synthetic journal ', 20000)], ['zero' => 0, 'null' => null]];
        $path = $this->root.'/backups/test.hlb';
        app(EncryptedArchive::class)->encrypt($records, $path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertStringNotContainsString('synthetic-secret', file_get_contents($path));
        $this->assertStringNotContainsString('synthetic journal', file_get_contents($path));
        $this->assertSame($records, iterator_to_array(app(EncryptedArchive::class)->decrypt($path)));
    }

    public function test_archive_rejects_tampering_truncation_trailing_bytes_and_wrong_keys(): void
    {
        $archive = app(EncryptedArchive::class);
        $path = $this->root.'/backups/test.hlb';
        $archive->encrypt([['private' => 'synthetic']], $path);
        $valid = file_get_contents($path);
        $tampered = $valid;
        $tampered[40] = chr(ord($tampered[40]) ^ 1);
        foreach ([$tampered, substr($valid, 0, -1), $valid.'extra', substr($valid, 0, 10)] as $bytes) {
            file_put_contents($path, $bytes);
            try {
                iterator_to_array($archive->decrypt($path));
                $this->fail('Damaged archive accepted.');
            } catch (RecoveryFailure $exception) {
                $this->assertNull($exception->getPrevious());
            }
        }
        file_put_contents($path, $valid);
        file_put_contents($this->root.'/keys/key', random_bytes(32));
        $this->expectException(RecoveryFailure::class);
        iterator_to_array($archive->decrypt($path));
    }

    public function test_pending_deletion_survives_new_service_and_blocks_recovery_until_completed(): void
    {
        $ledger = app(DeletionLedger::class);
        $ledger->initialize();
        $uuid = (string) Str::uuid();
        $ledger->exclusive(fn ($store) => $store->deletion($uuid, 'pending'));
        $fresh = app(DeletionLedger::class);
        $this->assertSame('pending', $fresh->snapshot()['deletions'][$uuid]);
        try {
            $fresh->exclusive(fn ($store) => $store->assertComplete());
            $this->fail('Pending operation accepted.');
        } catch (RecoveryFailure) {
            $this->assertSame(0600, fileperms($this->root.'/ledger/state.json') & 0777);
        }
        $fresh->exclusive(fn ($store) => $store->deletion($uuid, 'committed'));
        $fresh->exclusive(fn ($store) => $store->assertComplete());
        $this->assertSame('committed', $fresh->snapshot()['deletions'][$uuid]);
        $this->assertSame(['version', 'id', 'revision', 'deletions', 'backups', 'active'], array_keys($fresh->snapshot()));
    }

    public function test_missing_and_modified_ledger_fail_closed_and_cannot_be_reinitialized(): void
    {
        $ledger = app(DeletionLedger::class);
        $ledger->initialize();
        try {
            $ledger->initialize();
            $this->fail('Existing ledger was replaced.');
        } catch (RecoveryFailure) {
            $this->assertFileExists($this->root.'/ledger/state.json');
        }
        $path = $this->root.'/ledger/state.json';
        $envelope = json_decode(file_get_contents($path), true);
        $envelope['body'] = str_replace('"revision":1', '"revision":0', $envelope['body']);
        file_put_contents($path, json_encode($envelope));
        try {
            $ledger->snapshot();
            $this->fail('Altered ledger accepted.');
        } catch (RecoveryFailure) {
            unlink($path);
        }
        $this->expectException(RecoveryFailure::class);
        $ledger->snapshot();
    }

    public function test_concurrent_ledger_writer_fails_without_changing_records(): void
    {
        $ledger = app(DeletionLedger::class);
        $ledger->initialize();
        $before = file_get_contents($this->root.'/ledger/state.json');
        $held = fopen($this->root.'/ledger/lock', 'r+');
        flock($held, LOCK_EX);
        try {
            $ledger->exclusive(fn ($store) => $store->deletion((string) Str::uuid(), 'pending'));
            $this->fail('Concurrent lock accepted.');
        } catch (RecoveryFailure) {
            $this->assertSame($before, file_get_contents($this->root.'/ledger/state.json'));
        } finally {
            fclose($held);
        }
    }

    public function test_permissions_symlinks_and_overlapping_stores_are_rejected(): void
    {
        $files = app(PrivateFiles::class);
        chmod($this->root.'/keys/key', 0644);
        try {
            $files->key();
            $this->fail('Readable key accepted.');
        } catch (RecoveryFailure) {
            chmod($this->root.'/keys/key', 0600);
        }
        symlink($this->root.'/keys/key', $this->root.'/keys/link');
        try {
            $files->file($this->root.'/keys/link');
            $this->fail('Symlink accepted.');
        } catch (RecoveryFailure) {
            config(['recovery.ledger_directory' => $this->root.'/backups']);
        }
        $this->expectException(RecoveryFailure::class);
        app(DeletionLedger::class)->initialize();
    }

    public function test_real_scheduler_invokes_purge_only_when_managed_backups_are_enabled(): void
    {
        $ledger = app(DeletionLedger::class);
        $ledger->initialize();
        $id = (string) Str::uuid();
        $path = $this->root.'/backups/'.$id.'.hlb';
        app(EncryptedArchive::class)->encrypt([['synthetic' => true]], $path);
        $expires = time() - 60;
        $ledger->exclusive(fn ($store) => $store->backup($id, ['created' => $expires - 29 * 86400,
            'expires' => $expires, 'status' => 'complete', 'hash' => hash_file('sha256', $path)]));
        $environment = ['APP_ENV' => 'testing', 'RECOVERY_MODE' => 'off',
            'RECOVERY_BACKUP_DIRECTORY' => $this->root.'/backups', 'RECOVERY_LEDGER_DIRECTORY' => $this->root.'/ledger',
            'RECOVERY_KEY_FILE' => $this->root.'/keys/key', 'LOG_CHANNEL' => 'stderr'];
        $process = new Process([PHP_BINARY, 'artisan', 'schedule:run'], base_path(), $environment);
        $process->mustRun();
        $this->assertFileExists($path);
        $environment['RECOVERY_MODE'] = 'live';
        $process = new Process([PHP_BINARY, 'artisan', 'schedule:run'], base_path(), $environment);
        $process->mustRun();
        $this->assertFileDoesNotExist($path);
        $this->assertSame('purged', $ledger->snapshot()['backups'][$id]['status']);
        $this->assertStringNotContainsString('synthetic', $process->getOutput().$process->getErrorOutput());
    }
}
