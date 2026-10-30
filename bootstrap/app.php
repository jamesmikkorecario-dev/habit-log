<?php

use App\Auth\CredentialUrls;
use App\Http\Middleware\RejectRevokedSession;
use App\Http\Middleware\WebSecurity;
use App\Mail\ProductionMailFailure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(at: fn () => ['^'.preg_quote(parse_url(CredentialUrls::origin(), PHP_URL_HOST), '/').'$'], subdomains: false);
        // Forwarded host/prefix are deliberately excluded. Ingress must retain
        // the canonical Host; forwarded scheme/IP/port require explicit trust.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $middleware->web(append: [RejectRevokedSession::class, AuthenticateSession::class, WebSecurity::class]);
        $middleware->prependToPriorityList(AuthenticateSession::class, RejectRevokedSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'signature']);
        $exceptions->report(function (ProductionMailFailure $exception): void {
            Log::error('Production security mail unavailable. Check transport configuration and delivery.');
        })->stop();
        $exceptions->render(function (ProductionMailFailure $exception, Request $request) {
            $message = 'Security email is temporarily unavailable. Please try again later.';

            $response = $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : response($message, 503);

            return $response->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
        });
        $exceptions->report(function (QueryException $exception): void {
            // QueryException embeds SQL, bindings and sometimes private values in
            // the driver message. Report useful metadata without that exception.
            $frame = collect($exception->getTrace())->first(fn ($frame) => str_starts_with($frame['class'] ?? '', 'App\\'));
            Log::error('Database operation failed.', [
                'exception' => $exception::class,
                'sqlstate' => (string) $exception->getCode(),
                'operation' => $frame ? $frame['class'].'::'.$frame['function'] : 'framework',
            ]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
