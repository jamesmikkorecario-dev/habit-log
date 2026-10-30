<?php

namespace App\Services\Recovery;

use Illuminate\Support\Str;
use Throwable;

final class BackupManager
{
    public function __construct(private DeletionLedger $ledger, private PrivateFiles $files,
        private EncryptedArchive $archive, private DatabaseSnapshot $snapshot) {}

    public function create(): string
    {
        if (config('recovery.mode') !== 'live') {
            throw new RecoveryFailure;
        }

        return $this->ledger->exclusive(function (DeletionLedger $ledger) {
            app(RecoveryAccess::class)->assertAdmitted($ledger);
            $ledger->assertComplete();
            $this->purge();
            $id = (string) Str::uuid();
            $created = now()->timestamp;
            $metadata = ['created' => $created, 'expires' => $created + 29 * 86400, 'status' => 'writing', 'hash' => null];
            $ledger->backup($id, $metadata);
            $partial = $this->path($id, '.partial');
            $final = $this->path($id);
            try {
                $header = ['version' => 1, 'id' => $id, 'ledger_id' => $ledger->state()['id'],
                    'revision' => $ledger->state()['revision'], 'created' => $created, 'expires' => $metadata['expires']];
                $this->archive->encrypt($this->snapshot->export($header), $partial);
                if (file_exists($final) || ! @rename($partial, $final)) {
                    throw new RecoveryFailure;
                }
                $this->files->syncDirectory(dirname($final));
                $metadata['status'] = 'complete';
                $metadata['hash'] = @hash_file('sha256', $final);
                $ledger->backup($id, $metadata);

                return $id;
            } catch (Throwable) {
                // Only this operation's random, registered partial is eligible
                // for immediate cleanup. A completed but unregistered archive
                // remains unusable and expires through the same registry.
                if (is_file($partial) && ! is_link($partial)) {
                    @unlink($partial);
                    $this->files->syncDirectory(dirname($partial));
                }
                throw new RecoveryFailure;
            }
        });
    }

    public function purge(): int
    {
        if (config('recovery.mode') !== 'live') {
            throw new RecoveryFailure;
        }

        return $this->ledger->exclusive(function (DeletionLedger $ledger) {
            $removed = 0;
            foreach ($ledger->state()['backups'] as $id => $metadata) {
                $this->validateMetadata($id, $metadata);
                if ($metadata['expires'] > now()->timestamp || $metadata['status'] === 'purged') {
                    continue;
                }
                foreach (['.hlb', '.partial'] as $extension) {
                    $path = $this->path($id, $extension);
                    if (file_exists($path) || is_link($path)) {
                        $this->files->file($path);
                        if (! @unlink($path)) {
                            throw new RecoveryFailure;
                        }
                        $removed++;
                    }
                }
                $this->files->syncDirectory((string) config('recovery.backup_directory'));
                $metadata['status'] = 'purged';
                $ledger->backup($id, $metadata);
            }

            return $removed;
        });
    }

    public function inspect(string $id, DeletionLedger $ledger): array
    {
        $metadata = $ledger->state()['backups'][$id] ?? null;
        $this->validateMetadata($id, $metadata);
        $path = $this->path($id);
        $this->files->file($path);
        if ($metadata['status'] !== 'complete' || $metadata['expires'] <= now()->timestamp
            || ! is_string($metadata['hash']) || ! hash_equals($metadata['hash'], @hash_file('sha256', $path))) {
            throw new RecoveryFailure;
        }
        // Authenticate the entire stream, including FINAL/EOF, before any DB
        // mutation. Restore streams it again inside a rollbackable transaction.
        $header = null;
        foreach ($this->archive->decrypt($path) as $record) {
            $header ??= $record;
        }
        if (($header['type'] ?? null) !== 'header' || ($header['version'] ?? null) !== 1
            || ($header['id'] ?? null) !== $id || ($header['ledger_id'] ?? null) !== $ledger->state()['id']
            || ! is_int($header['revision'] ?? null) || $header['revision'] > $ledger->state()['revision']
            || ($header['created'] ?? null) !== $metadata['created'] || ($header['expires'] ?? null) !== $metadata['expires']) {
            throw new RecoveryFailure;
        }

        return $header;
    }

    public function path(string $id, string $extension = '.hlb'): string
    {
        if (! Str::isUuid($id) || ! in_array($extension, ['.hlb', '.partial'], true)) {
            throw new RecoveryFailure;
        }

        return $this->files->directory((string) config('recovery.backup_directory')).'/'.$id.$extension;
    }

    private function validateMetadata(string $id, mixed $metadata): void
    {
        if (! Str::isUuid($id) || ! is_array($metadata) || ! is_int($metadata['created'] ?? null)
            || ! is_int($metadata['expires'] ?? null) || 29 * 86400 !== $metadata['expires'] - $metadata['created']
            || ! array_key_exists('hash', $metadata)
            || ! in_array($metadata['status'] ?? null, ['writing', 'complete', 'purged'], true)) {
            throw new RecoveryFailure;
        }
    }
}
