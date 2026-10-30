<?php

namespace App\Services\Recovery;

use Generator;
use Throwable;

final class EncryptedArchive
{
    private const MAGIC = "HLB1\n";

    private const MAX_RECORD = 8388608;

    public function __construct(private PrivateFiles $files) {}

    public function encrypt(iterable $records, string $path): void
    {
        $stream = null;
        try {
            $stream = $this->files->create($path);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->files->key());
            $this->files->write($stream, self::MAGIC.$header);
            foreach ($records as $record) {
                $plain = json_encode($record, JSON_THROW_ON_ERROR);
                if (strlen($plain) > self::MAX_RECORD) {
                    throw new RecoveryFailure;
                }
                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain);
                $this->files->write($stream, pack('N', strlen($encrypted)).$encrypted);
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->files->write($stream, pack('N', strlen($final)).$final);
            $this->files->sync($stream);
        } catch (Throwable) {
            throw new RecoveryFailure;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function decrypt(string $path): Generator
    {
        $stream = null;
        try {
            $this->files->file($path);
            $stream = @fopen($path, 'rb');
            if ($this->read($stream, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RecoveryFailure;
            }
            $header = $this->read($stream, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->files->key());
            while (true) {
                $size = unpack('Nsize', $this->read($stream, 4))['size'];
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $size > self::MAX_RECORD + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RecoveryFailure;
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($stream, $size));
                if ($result === false) {
                    throw new RecoveryFailure;
                }
                [$plain, $tag] = $result;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($plain !== '' || @fread($stream, 1) !== '' || ! feof($stream)) {
                        throw new RecoveryFailure;
                    }

                    return;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new RecoveryFailure;
                }
                $record = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record)) {
                    throw new RecoveryFailure;
                }
                yield $record;
            }
        } catch (Throwable) {
            throw new RecoveryFailure;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function read($stream, int $size): string
    {
        $bytes = '';
        while (strlen($bytes) < $size) {
            $part = @fread($stream, $size - strlen($bytes));
            if ($part === false || $part === '') {
                throw new RecoveryFailure;
            }
            $bytes .= $part;
        }

        return $bytes;
    }
}
