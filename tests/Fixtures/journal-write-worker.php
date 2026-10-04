<?php

use App\Actions\Journals\DeleteJournal;
use App\Actions\Journals\SaveJournal;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('JOURNAL_TEST_DB'), true, flags: JSON_THROW_ON_ERROR)]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'habit_log_test' || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$user = User::findOrFail((int) $argv[1]);
$id = $argv[2] === '-' ? null : (int) $argv[2];
$revision = $id === null ? null : 1;
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    if ($argv[3] === 'delete') {
        app(DeleteJournal::class)->handle($user, '2026-10-01', $id, $revision, 'UTC');
    } else {
        app(SaveJournal::class)->handle($user, '2026-10-01', ['content' => 'Competing draft'], $id, $revision, 'UTC');
    }
    exit(3);
} catch (ValidationException $e) {
    if (isset($e->errors()['conflict'])) {
        echo "conflict\n";
        exit(0);
    }
    exit(4);
}
