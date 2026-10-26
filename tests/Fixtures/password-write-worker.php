<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('PASSWORD_TEST_DB'), true, flags: JSON_THROW_ON_ERROR), 'session.driver' => 'array']);
DB::purge('mysql');
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'habit_log_test'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$user = User::findOrFail((int) $argv[1]);
$input = ['current_password' => 'password', 'password' => 'Competing-password-789!', 'password_confirmation' => 'Competing-password-789!'];
if ($argv[2] === 'reset') {
    $input['token'] = $argv[3];
}
Auth::login($user);
request()->setLaravelSession(session()->driver());
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    if ($argv[2] === 'reset') {
        app(ResetUserPassword::class)->reset($user, $input);
    } else {
        app(UpdateUserPassword::class)->update($user, $input);
    }
    exit(3);
} catch (ValidationException) {
    echo "stale write rejected\n";
    exit(0);
}
