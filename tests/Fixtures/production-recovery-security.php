<?php

use App\Services\Recovery\DeletionLedger;
use App\Services\Recovery\EncryptedArchive;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

// Database-free synthetic probe, streamed into a disposable container only.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isProduction()) {
    throw new RuntimeException('This check requires a disposable production container.');
}
$root = sys_get_temp_dir().'/habit-recovery-container-'.Str::uuid();
mkdir($root, 0700);
foreach (['backups', 'ledger', 'keys'] as $part) {
    mkdir($root.'/'.$part, 0700);
}
try {
    file_put_contents($root.'/keys/key', random_bytes(32));
    chmod($root.'/keys/key', 0600);
    config(['recovery.mode' => 'live', 'recovery.backup_directory' => $root.'/backups',
        'recovery.ledger_directory' => $root.'/ledger', 'recovery.key_file' => $root.'/keys/key']);
    $ledger = app(DeletionLedger::class);
    $ledger->initialize(); // Exercises fsync/atomic replacement under runtime UID.
    $uuid = (string) Str::uuid();
    $ledger->exclusive(fn ($store) => $store->deletion($uuid, 'pending'));
    if (app(DeletionLedger::class)->snapshot()['deletions'][$uuid] !== 'pending') {
        throw new RuntimeException('Independent state persistence failed.');
    }
    $records = [['private' => 'production-recovery-synthetic-canary']];
    $archive = app(EncryptedArchive::class);
    $archive->encrypt($records, $root.'/backups/synthetic.hlb');
    if (str_contains(file_get_contents($root.'/backups/synthetic.hlb'), 'production-recovery-synthetic-canary')
        || iterator_to_array($archive->decrypt($root.'/backups/synthetic.hlb')) !== $records) {
        throw new RuntimeException('Runtime encrypted round trip failed.');
    }
    echo "Production recovery storage passed: restrictive files, durable state, authenticated encrypted round trip.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
