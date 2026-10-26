<?php

use App\Actions\Accounts\SessionSecurity;
use App\Auth\RevocableDatabaseSessionHandler;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('SESSION_TEST_DB'), true, flags: JSON_THROW_ON_ERROR), 'session.driver' => 'array']);
DB::purge('mysql');
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'habit_log_test'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
$user = User::findOrFail((int) $argv[1]);
request()->setLaravelSession(session()->driver());
Auth::setUser($user);
request()->setUserResolver(fn () => $user);
session()->put('revocation_origin', hash('sha256', 'target-session'));
config(['session.driver' => 'database']);
if ($argv[2] === 'remember') {
    session()->put('revocation_origin', hash('sha256', 'new-remember-origin'));
    request()->attributes->set('session_recalled_token', (string) $user->remember_token);
}
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    if ($argv[2] === 'login') {
        app(SessionSecurity::class)->login(new Login('web', $user, false));
        exit(3);
    }
    $handler = new RevocableDatabaseSessionHandler(DB::connection(), 'sessions', 120, app());
    $handler->write($argv[2] === 'same-id' ? 'target-session' : 'new-stale-session', '{}');
    echo "write finished\n";
} catch (AuthenticationException) {
    exit($argv[2] === 'login' ? 0 : 4);
}
