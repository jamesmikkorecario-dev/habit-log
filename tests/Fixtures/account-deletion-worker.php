<?php

use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SessionSecurity;
use App\Actions\Categories\CreateCategory;
use App\Actions\Follows\FollowUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Habits\SaveHabit;
use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Journals\SaveJournal;
use App\Actions\Profiles\SaveProfile;
use App\Auth\RevocableDatabaseSessionHandler;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql' => json_decode(getenv('DELETION_TEST_DB'), true, flags: JSON_THROW_ON_ERROR), 'session.driver' => 'array']);
DB::purge('mysql');
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'habit_log_test'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'habit_log_test') {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
$user = User::findOrFail((int) $argv[1]);
$foreign = User::findOrFail((int) $argv[3]);
$habit = $user->habits()->firstOrFail();
$version = $habit->versions()->firstOrFail();
request()->setLaravelSession(session()->driver());
Auth::setUser($user);
request()->setUserResolver(fn () => $user);
config(['session.driver' => 'database']);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    match ($argv[2]) {
        'habit' => app(SaveHabit::class)->handle($user, null, $habit->attributesToArray() + ['checkpoints' => []]),
        'entry' => app(SaveHabitEntry::class)->handle($user, $habit->id, '2024-01-03', ['status' => 'completed', 'completed' => [], 'value' => null], null, $version->id, $version->revision, $user->timezone),
        'journal' => app(SaveJournal::class)->handle($user, '2024-01-03', ['content' => 'Rejected late journal'], null, null, $user->timezone),
        'category-duplicate' => app(CreateCategory::class)->handle($user, ['name' => 'Same choice', 'normalized_name' => 'same choice', 'description' => null]),
        'category' => app(CreateCategory::class)->handle($user, ['name' => 'Late category', 'normalized_name' => 'late category', 'description' => null]),
        'follow' => app(FollowUser::class)->handle($foreign, $user->id),
        'profile' => app(SaveProfile::class)->handle($user, []),
        'reset' => app(ResetUserPassword::class)->reset($user, ['token' => getenv('DELETION_TEST_TOKEN'), 'password' => 'Late-password-456!', 'password_confirmation' => 'Late-password-456!']),
        'recovery' => Password::createToken($user),
        'session' => (new RevocableDatabaseSessionHandler(DB::connection(), 'sessions', 120, app()))->write('late-deleted-session', '{}'),
        'login' => app(SessionSecurity::class)->login(new Login('web', $user, false)),
        'delete' => app(DeleteAccount::class)->handle(request(), ['account_id' => $user->id, 'current_password' => 'password', 'acknowledge_deletion' => '1']),
    };
    exit(in_array($argv[2], ['recovery', 'session', 'category-duplicate']) ? 0 : 3);
} catch (ModelNotFoundException|ValidationException|AuthenticationException $e) {
    echo "stale operation rejected\n";
} catch (HttpExceptionInterface $e) {
    exit(in_array($e->getStatusCode(), [403, 404]) ? 0 : 4);
}
