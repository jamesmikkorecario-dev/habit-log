<?php

namespace App\Services\Recovery;

use Closure;
use Illuminate\Support\Str;
use Throwable;

final class DeletionLedger
{
    private bool $locked = false;

    private array $state = [];

    public function __construct(private PrivateFiles $files) {}

    public function enabled(): bool
    {
        return config('recovery.mode') !== 'off';
    }

    public function exclusive(Closure $callback): mixed
    {
        if ($this->locked) {
            return $callback($this);
        }
        $lock = null;
        try {
            $directory = $this->directory();
            $this->files->file($directory.'/lock');
            $lock = @fopen($directory.'/lock', 'r+');
            if (! $lock || ! @flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RecoveryFailure;
            }
            $this->locked = true;
            $this->state = $this->read();

            return $callback($this);
        } finally {
            $this->locked = false;
            $this->state = [];
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function initialize(): void
    {
        $directory = $this->directory();
        if (count(scandir($directory)) !== 2) {
            throw new RecoveryFailure;
        }
        $lock = $this->files->create($directory.'/lock');
        try {
            if (! @flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RecoveryFailure;
            }
            $this->state = ['version' => 1, 'id' => (string) Str::uuid(), 'revision' => 0,
                'deletions' => [], 'backups' => [], 'active' => null];
            $this->locked = true;
            $this->save();
            $this->files->sync($lock);
        } finally {
            $this->locked = false;
            $this->state = [];
            fclose($lock);
        }
    }

    public function state(): array
    {
        if (! $this->locked) {
            throw new RecoveryFailure;
        }

        return $this->state;
    }

    public function snapshot(): array
    {
        // Atomic replacement permits admission reads without blocking behind a
        // long snapshot. Operators must drain old requests before cutover.
        return $this->read();
    }

    public function deletion(string $uuid, string $status): void
    {
        if (! Str::isUuid($uuid) || ! in_array($status, ['pending', 'committed', 'cancelled'], true)) {
            throw new RecoveryFailure;
        }
        $this->state['deletions'][$uuid] = $status;
        $this->save();
    }

    public function backup(string $id, array $metadata): void
    {
        if (! Str::isUuid($id)) {
            throw new RecoveryFailure;
        }
        $this->state['backups'][$id] = $metadata;
        $this->save();
    }

    public function activate(string $generation): void
    {
        if (! Str::isUuid($generation)) {
            throw new RecoveryFailure;
        }
        $this->state['active'] = $generation;
        $this->save();
    }

    public function assertComplete(): void
    {
        if (in_array('pending', $this->state()['deletions'], true)) {
            throw new RecoveryFailure;
        }
    }

    private function directory(): string
    {
        $directory = $this->files->directory((string) config('recovery.ledger_directory'));
        $backup = $this->files->directory((string) config('recovery.backup_directory'));
        $keyDirectory = $this->files->directory(dirname((string) config('recovery.key_file')));
        foreach ([[$directory, $backup], [$directory, $keyDirectory], [$backup, $keyDirectory]] as [$a, $b]) {
            if (str_starts_with($a.'/', $b.'/') || str_starts_with($b.'/', $a.'/')) {
                throw new RecoveryFailure;
            }
        }

        return $directory;
    }

    private function read(): array
    {
        try {
            $path = $this->directory().'/state.json';
            $this->files->file($path);
            $envelope = json_decode(@file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $body = $envelope['body'] ?? null;
            if (! is_string($body) || ! is_string($envelope['mac'] ?? null)
                || ! hash_equals(hash_hmac('sha256', $body, $this->files->key()), $envelope['mac'])) {
                throw new RecoveryFailure;
            }
            $state = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (($state['version'] ?? null) !== 1 || ! is_string($state['id'] ?? null) || ! Str::isUuid($state['id'])
                || ! is_int($state['revision'] ?? null) || $state['revision'] < 1
                || ! is_array($state['deletions'] ?? null) || ! is_array($state['backups'] ?? null)
                || ! array_key_exists('active', $state)
                || ($state['active'] !== null && (! is_string($state['active']) || ! Str::isUuid($state['active'])))) {
                throw new RecoveryFailure;
            }
            foreach ($state['deletions'] as $uuid => $status) {
                if (! Str::isUuid($uuid) || ! in_array($status, ['pending', 'committed', 'cancelled'], true)) {
                    throw new RecoveryFailure;
                }
            }

            return $state;
        } catch (Throwable) {
            throw new RecoveryFailure;
        }
    }

    private function save(): void
    {
        $this->state();
        $this->state['revision']++;
        $body = json_encode($this->state, JSON_THROW_ON_ERROR);
        $envelope = json_encode(['body' => $body, 'mac' => hash_hmac('sha256', $body, $this->files->key())], JSON_THROW_ON_ERROR);
        $this->files->replace($this->directory().'/state.json', $envelope);
    }
}
