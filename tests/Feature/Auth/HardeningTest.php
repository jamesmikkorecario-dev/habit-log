<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_errors_are_reported_without_sql_bindings_or_private_exception_text(): void
    {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('privacy', [$handler]));
        Log::swap($logger);
        $this->app->instance(LoggerInterface::class, $logger);
        $exception = new QueryException('mysql', 'insert into habit_entries (note) values (?)', ['PRIVATE-NOTE-CANARY'], new \PDOException('PRIVATE-ERROR-CANARY', 23000));
        $this->app->make(ExceptionHandler::class)->report($exception);
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $encoded = json_encode($records);
        $this->assertStringNotContainsString('PRIVATE-NOTE-CANARY', $encoded);
        $this->assertStringNotContainsString('PRIVATE-ERROR-CANARY', $encoded);
        $this->assertStringNotContainsString('insert into', $encoded);
        $this->assertSame('23000', $records[0]['context']['sqlstate']);
        $this->assertSame('ERROR', $records[0]['level_name']);
    }

    public function test_sensitive_pages_are_not_cacheable_and_set_basic_browser_headers(): void
    {
        $this->get('/login')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $response = $this->actingAs(User::factory()->create())->get('/journal')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_public_auth_requests_are_rate_limited_without_throttling_other_addresses(): void
    {
        foreach (['register.store', 'password.email', 'password.update'] as $route) {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $this->postJson(route($route), [])->assertUnprocessable();
            }
            $this->postJson(route($route), [])->assertStatus(429)->assertHeader('Retry-After');
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->postJson(route($route), [])->assertUnprocessable();
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
            $this->travel(61)->seconds();
            $this->postJson(route($route), [])->assertUnprocessable();
        }
    }

    public function test_reset_token_expires_and_successful_reset_invalidates_reuse(): void
    {
        $user = User::factory()->create();
        $expired = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $expired, 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertSessionHasErrors('email');
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'];
        $this->post(route('password.update'), $payload)->assertRedirect(route('login', absolute: false));
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
    }

    public function test_login_rotates_session_and_logout_invalidates_session_state(): void
    {
        $user = User::factory()->create();
        $this->withSession(['private-sentinel' => 'old-session']);
        $oldId = session()->getId();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertNotSame($oldId, session()->getId());
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->assertNull(session('private-sentinel'));
    }
}
