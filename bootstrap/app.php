<?php

use App\Http\Middleware\RejectRevokedSession;
use App\Http\Middleware\WebSecurity;
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
        $middleware->web(append: [RejectRevokedSession::class, AuthenticateSession::class, WebSecurity::class]);
        $middleware->prependToPriorityList(AuthenticateSession::class, RejectRevokedSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'token', 'signature']);
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
