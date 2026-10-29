<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (getenv('HABIT_LOG_E2E') !== '1' || ! $app->environment('local') || $app->configurationIsCached()
        || config('database.connections.mysql.url') || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'habit_log_test'
        || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
        fwrite(STDERR, "Browser setup requires explicit local opt-in, uncached config and isolated habit_log_test.\n");
        exit(1);
    }

    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0
        || Artisan::call('db:seed', ['--class' => 'CategorySeeder', '--force' => true]) !== 0) {
        throw new RuntimeException('Fixture command failed.');
    }
    require __DIR__.'/entry-history.php';
    require __DIR__.'/shared-profiles.php';
    require __DIR__.'/heatmap-review.php';
    require __DIR__.'/email-change.php';
    require __DIR__.'/account-deletion.php';
    echo "Prepared isolated browser database.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Browser setup failed ('.$exception::class."). No browser server will be started.\n");
    exit(1);
}
