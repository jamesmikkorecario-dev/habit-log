<?php

use App\Mail\ProductionMailFailure;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

// Database-free container regression. Print outcomes only, never mail content.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isProduction()) {
    throw new RuntimeException('This check requires a disposable production container.');
}
$request = Request::create('http://untrusted.example.test/forgot-password', 'POST', [], [], [], [
    'HTTP_X_FORWARDED_HOST' => 'forwarded.example.test', 'HTTP_X_FORWARDED_PROTO' => 'http',
]);
$app->instance('request', $request);
URL::setRequest($request);
$user = new User;
$user->forceFill(['email' => 'synthetic@example.test', 'name' => 'Synthetic']);
$url = (new ResetPassword('production-mail-secret-canary'))->toMail($user)->actionUrl;
if (parse_url($url, PHP_URL_HOST) !== parse_url(config('app.url'), PHP_URL_HOST) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
    throw new RuntimeException('Credential URL origin check failed.');
}
foreach (['log', 'array', 'failover'] as $mailer) {
    config(['mail.default' => $mailer]);
    try {
        $user->sendPasswordResetNotification('production-mail-secret-canary');
        throw new RuntimeException('Unsafe production mail was accepted.');
    } catch (ProductionMailFailure $exception) {
        if ($exception->getPrevious() !== null) {
            throw new RuntimeException('Unsafe diagnostic chain retained.');
        }
    }
}
echo "Production credential origin and unsafe-mail rejection passed without database access or mail delivery.\n";
