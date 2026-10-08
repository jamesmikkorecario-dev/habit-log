<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // This runs before RefreshDatabase can migrate or reset any tables.
        if (! $app->environment('testing') || $app->configurationIsCached()
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.url')
            || config('database.connections.mysql.database') !== 'habit_log_test'
            || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
            throw new \RuntimeException('Tests require uncached testing configuration and isolated habit_log_test.');
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
