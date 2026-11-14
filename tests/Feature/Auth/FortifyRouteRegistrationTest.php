<?php

namespace Tests\Feature\Auth;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class FortifyRouteRegistrationTest extends TestCase
{
    public function test_owned_routes_match_enabled_package_routes_except_for_relative_verification(): void
    {
        $this->assertFalse(Fortify::$registersRoutes);
        $applicationRouter = Route::getFacadeRoot();
        $packageRouter = new Router($this->app['events'], $this->app);
        Route::swap($packageRouter);
        try {
            Route::group([
                'namespace' => 'Laravel\\Fortify\\Http\\Controllers',
                'domain' => config('fortify.domain', null),
                'prefix' => config('fortify.prefix'),
            ], fn () => require base_path('vendor/laravel/fortify/routes/routes.php'));
        } finally {
            Route::swap($applicationRouter);
        }

        $describe = fn ($routes) => collect($routes)->filter(fn ($route) => str_starts_with(
            $route->getActionName(), 'Laravel\\Fortify\\Http\\Controllers\\',
        ))->map(fn ($route) => [
            'name' => $route->getName(), 'uri' => $route->uri(), 'methods' => $route->methods(),
            'domain' => $route->getDomain(), 'action' => $route->getActionName(), 'middleware' => $route->gatherMiddleware(),
        ])->sortBy('name')->values()->all();
        $expected = $describe($packageRouter->getRoutes());
        foreach ($expected as &$route) {
            if ($route['name'] === 'verification.verify') {
                $route['middleware'] = array_map(fn ($middleware) => $middleware === 'signed' ? 'signed:relative' : $middleware, $route['middleware']);
            }
        }
        unset($route);

        $actual = $describe($applicationRouter->getRoutes());
        $this->assertSame($expected, $actual);
        $names = array_column($actual, 'name');
        $this->assertSame($names, array_values(array_unique($names)));
        // Compiling all application routes also rejects duplicate named routes.
        $compiled = $applicationRouter->getRoutes()->compile();
        $this->assertContains('signed:relative', $compiled['attributes']['verification.verify']['action']['middleware']);
    }
}
