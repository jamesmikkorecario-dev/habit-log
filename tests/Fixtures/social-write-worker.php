<?php

use App\Actions\Follows\FollowUser;
use App\Actions\Follows\TransitionFollow;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('SOCIAL_TEST_DB'), true, flags: JSON_THROW_ON_ERROR)]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'habit_log_test' || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$actor = User::findOrFail((int) $argv[1]);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    if ($argv[3] === 'follow') {
        $follow = app(FollowUser::class)->handle($actor, (int) $argv[2]);
        echo $follow->status->value;
    } else {
        app(TransitionFollow::class)->handle($actor, (int) $argv[2], $argv[3]);
        echo 'changed';
    }
} catch (ValidationException $e) {
    if (! isset($e->errors()['relationship'])) {
        exit(3);
    }
    echo 'conflict';
}
