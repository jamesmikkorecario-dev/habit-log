<?php

use App\Actions\Accounts\EmailChanges;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

if (getenv('HABIT_LOG_E2E') !== '1' || ! app()->environment('local') || app()->configurationIsCached()
    || config('database.default') !== 'mysql' || config('database.connections.mysql.url')
    || config('database.connections.mysql.database') !== 'habit_log_test'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
    throw new RuntimeException('Email fixtures require the isolated browser database.');
}
$links = [];
foreach (['lifecycle', 'expired', 'validation'] as $scenario) {
    $owner = User::factory()->create(['email' => 'email-'.$scenario.'@example.test', 'username' => 'email_'.$scenario, 'password' => bcrypt('Browser-test-123!')]);
    Auth::login($owner);
    [$pending, $url] = app(EmailChanges::class)->request($owner, ['new_email' => $scenario.'-proposed@example.test', 'current_password' => 'Browser-test-123!', 'generation' => 'none']);
    if ($scenario === 'lifecycle') {
        $links['replaced'] = $url;
        PendingEmailChange::whereKey($pending->id)->update(['last_sent_at' => now()->subMinutes(2)]);
        [$pending, $url] = app(EmailChanges::class)->request($owner, ['new_email' => 'replacement-proposed@example.test', 'current_password' => 'Browser-test-123!', 'generation' => $pending->id]);
        PendingEmailChange::whereKey($pending->id)->update(['last_sent_at' => now()->subMinutes(2)]);
    } elseif ($scenario === 'expired') {
        PendingEmailChange::whereKey($pending->id)->update(['expires_at' => now()->subMinute(), 'last_sent_at' => now()->subHours(2)]);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        unset($parameters['expires'], $parameters['signature']);
        $url = URL::temporarySignedRoute('settings.email.verify', now()->subMinute(), $parameters);
    }
    $links[$scenario] = $url;
}
Auth::logout();
$directory = base_path('storage/app/email-change');
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
file_put_contents($directory.'/fixtures.json', json_encode($links, JSON_THROW_ON_ERROR));
echo "Prepared isolated email-change fixtures.\n";
