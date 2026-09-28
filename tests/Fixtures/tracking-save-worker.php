<?php

use App\Actions\Habits\SaveHabitEntry;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Launched only by ConcurrencyTest, with the isolated database configuration supplied by its parent.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('TRACKING_TEST_DB'), true, flags: JSON_THROW_ON_ERROR)]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'habit_log_test' || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
$user = User::findOrFail((int) $argv[1]);
DB::listen(function ($query) {
    if (str_contains($query->sql, 'lock in share mode')) {
        fwrite(STDOUT, "waiting\n");
        fflush(STDOUT);
    }
});
try {
    app(SaveHabitEntry::class)->handle($user, (int) $argv[2], '2026-09-27', [
        'status' => 'missed', 'value' => null, 'note' => null, 'not_applicable_reason' => null, 'completed' => [],
    ], (int) $argv[4], (int) $argv[3], 1, 'UTC');
    echo "overwritten\n";
    exit(3);
} catch (ValidationException $exception) {
    if (isset($exception->errors()['conflict'])) {
        echo "conflict\n";
        exit(0);
    }
    exit(4);
}
