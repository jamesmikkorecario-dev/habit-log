<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CredentialUrls
{
    public static function origin(): string
    {
        $origin = config('app.url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! $parts || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/[\s\\\\]/', $origin)
            || (app()->isProduction() && $parts['scheme'] !== 'https')) {
            throw new ServiceUnavailableHttpException(null, 'The application origin is not configured securely.');
        }

        return rtrim($origin, '/');
    }

    public static function generator(): UrlGenerator
    {
        $origin = self::origin();
        // Reset the clone's route generator/request caches; never mutate the
        // shared generator or derive a credential URL from inbound headers.
        $urls = clone app('url');
        $urls->setRequest(Request::create($origin));
        $urls->useOrigin($origin);
        $urls->forceScheme(parse_url($origin, PHP_URL_SCHEME));

        return $urls;
    }
}
