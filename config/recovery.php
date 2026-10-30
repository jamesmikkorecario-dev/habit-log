<?php

return [
    // off preserves installations without this managed backup workflow.
    // Once enabled, never switch off to bypass a failed recovery check.
    'mode' => env('RECOVERY_MODE', 'off'), // off, live, recovery
    'backup_directory' => env('RECOVERY_BACKUP_DIRECTORY'),
    'ledger_directory' => env('RECOVERY_LEDGER_DIRECTORY'),
    'key_file' => env('RECOVERY_KEY_FILE'),
    // Explicitly identifies the isolated database allowed to receive a restore.
    'target_database' => env('RECOVERY_TARGET_DATABASE'),
];
