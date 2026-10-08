<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class WebSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $request->isMethod('POST') && $request->routeIs('register.store', 'password.email', 'password.update')
            ? app(ThrottleRequests::class)->handle($request, $next, 'public-auth')
            : $next($request);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
