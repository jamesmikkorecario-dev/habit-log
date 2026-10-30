<?php

namespace App\Services\Recovery;

final class PrivateFiles
{
    public function directory(string $path): string
    {
        clearstatcache();
        $real = realpath($path);
        if (! $real || $real !== $path || ! is_dir($path) || is_link($path)
            || (fileperms($path) & 0777) !== 0700
            || fileowner($path) !== posix_geteuid()
            || str_starts_with($path.'/', base_path().'/')) {
            throw new RecoveryFailure;
        }

        return $real;
    }

    public function file(string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600
            || fileowner($path) !== posix_geteuid() || file_exists($path) && stat($path)['nlink'] !== 1) {
            throw new RecoveryFailure;
        }
    }

    public function key(): string
    {
        $path = (string) config('recovery.key_file');
        $this->directory(dirname($path));
        $this->file($path);
        $key = @file_get_contents($path);
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RecoveryFailure;
        }

        return $key;
    }

    public function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RecoveryFailure;
            }
            $bytes = substr($bytes, $written);
        }
    }

    public function sync($stream): void
    {
        if (! @fflush($stream) || ! @fsync($stream)) {
            throw new RecoveryFailure;
        }
    }

    public function syncDirectory(string $path): void
    {
        $directory = @fopen($path, 'r');
        if (! $directory) {
            throw new RecoveryFailure;
        }
        try {
            if (! @fsync($directory)) {
                throw new RecoveryFailure;
            }
        } finally {
            fclose($directory);
        }
    }

    public function create(string $path)
    {
        $mask = umask(0077);
        try {
            $stream = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if (! $stream) {
            throw new RecoveryFailure;
        }

        return $stream;
    }

    public function replace(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(16)).'.tmp';
        $stream = $this->create($temporary);
        try {
            $this->write($stream, $contents);
            $this->sync($stream);
            fclose($stream);
            $stream = null;
            if (! @rename($temporary, $path)) {
                throw new RecoveryFailure;
            }
            $this->syncDirectory(dirname($path));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
