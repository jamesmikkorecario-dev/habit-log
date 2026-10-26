<?php

use App\Actions\Accounts\EmailChanges;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('EMAIL_TEST_DB'), true, flags: JSON_THROW_ON_ERROR), 'session.driver' => 'array']);
DB::purge('mysql');
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'habit_log_test'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
$user = User::findOrFail((int) $argv[1]);
$data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
Auth::login($user);
request()->setLaravelSession(session()->driver());
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    match ($argv[2]) {
        'request' => app(EmailChanges::class)->request($user, ['current_password' => 'password', 'new_email' => 'loser@example.test', 'generation' => $data['generation']]),
        'confirm' => app(EmailChanges::class)->confirm($user, $data, ['current_password' => 'password'], 'worker'),
        'reset' => app(ResetUserPassword::class)->reset($user, ['token' => $data['token'], 'password' => 'Competing-password-789!', 'password_confirmation' => 'Competing-password-789!']),
        'token' => Password::createToken($user),
    };
    if ($argv[2] === 'token') {
        echo "stale token creation completed without storing authority\n";
        exit(0);
    }
    exit(3);
} catch (ValidationException) {
    echo "competing write rejected\n";
    exit(0);
}
