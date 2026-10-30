<?php

namespace App\Http\Middleware;

use App\Auth\CredentialUrls;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class WebSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('PUT') && $request->routeIs('user-password.update') && $request->user()) {
            $response = app(ThrottleRequests::class)->handle($request, $next, 'password-change');
        } elseif ($request->isMethod('POST') && $request->routeIs('password.confirm.store') && $request->user()) {
            $response = app(ThrottleRequests::class)->handle($request, function (Request $request) use ($next) {
                $request->validate(['password' => ['required', 'string']]);

                return $next($request);
            }, 'password-confirmation');
        } else {
            $response = $request->isMethod('POST') && $request->routeIs('register.store', 'password.email', 'password.update')
                ? app(ThrottleRequests::class)->handle($request, function (Request $request) use ($next) {
                    if ($request->routeIs('password.email')) {
                        // Run after throttling but before user lookup/token creation.
                        app('mail.manager')->assertSafeMailer();
                        CredentialUrls::origin();
                    }

                    return $next($request);
                }, 'public-auth')
                : $next($request);
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
