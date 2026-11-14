<?php

namespace Tests\Feature;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $environment = [$_ENV, $_SERVER];
        $_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'database';

        try {
            // Select the deployment store before providers resolve the limiter.
            return parent::createApplication();
        } finally {
            [$_ENV, $_SERVER] = $environment;
        }
    }

    public function test_migrations_support_database_cache_storage_and_expiration(): void
    {
        $this->assertInstanceOf(DatabaseStore::class, Cache::store()->getStore());
        $this->assertTrue(Schema::hasIndex('cache', ['key'], 'primary'));
        $this->assertTrue(Schema::hasIndex('cache', ['expiration']));

        Cache::put('deployment-cache-probe', ['status' => 'ready'], 60);

        $this->assertDatabaseCount('cache', 1);
        $this->assertSame(['status' => 'ready'], Cache::get('deployment-cache-probe'));
        $this->travel(61)->seconds();
        $this->assertNull(Cache::get('deployment-cache-probe'));

        Cache::put('deployment-cache-probe', 'replacement', 60);
        $this->assertTrue(Cache::forget('deployment-cache-probe'));
        $this->assertNull(Cache::get('deployment-cache-probe'));
    }

    public function test_migrations_support_database_lock_contention_and_release(): void
    {
        $this->assertTrue(Schema::hasIndex('cache_locks', ['key'], 'primary'));
        $this->assertTrue(Schema::hasIndex('cache_locks', ['expiration']));

        $owner = Cache::lock('deployment-lock-probe', 60);
        $contender = Cache::lock('deployment-lock-probe', 60);

        $this->assertTrue($owner->get());
        $this->assertDatabaseCount('cache_locks', 1);
        $this->assertFalse($contender->get());
        $this->assertFalse($contender->release());
        $this->assertTrue($owner->release());
        $this->assertTrue($contender->get());
        $this->assertTrue($contender->release());
    }

    public function test_registration_succeeds_with_database_backed_rate_limiting(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Cache Deployment Probe',
            'email' => 'cache-probe@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'cache-probe@example.com']);
        $this->assertGreaterThan(0, DB::table('cache')->count());
    }

    public function test_registration_still_enforces_its_database_backed_rate_limit(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson(route('register.store'), [])->assertUnprocessable();
        }

        $this->postJson(route('register.store'), [])->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->assertGreaterThan(0, DB::table('cache')->count());
        $this->assertGuest();
    }
}
