<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_responses_match_for_known_unknown_and_throttled_addresses_in_html_and_json(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $expected = null;
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            $response = $this->postJson('/forgot-password', ['email' => $email])->assertOk();
            $expected ??= $response->json();
            $this->assertSame($expected, $response->json());
        }
        $message = $expected['message'];
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')->assertSessionHasNoErrors()
                ->assertSessionHas('status', $message)->assertSessionHasInput('email', $email);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertCount(1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_mail_transport_exceptions_are_not_suppressed_by_generic_feedback(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic mail transport failure'));
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Synthetic mail transport failure');
        $this->postJson('/forgot-password', ['email' => $user->email]);
    }

    public function test_bad_reset_token_does_not_disclose_whether_email_exists(): void
    {
        $user = User::factory()->create();
        $responses = [];
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $responses[] = $this->postJson('/reset-password', [
                'email' => $email, 'token' => 'invalid', 'password' => 'New-password-123!',
                'password_confirmation' => 'New-password-123!',
            ])->assertUnprocessable()->json();
        }
        $this->assertSame($responses[0], $responses[1]);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_invalid_recovery_input_retains_email_but_never_flashes_passwords(): void
    {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'invalid-email'])
            ->assertSessionHasErrors('email')->assertSessionHasInput('email', 'invalid-email');
        $this->get('/forgot-password')->assertSee('value="invalid-email"', false);
        $user = User::factory()->create();
        $this->from('/reset-password/token?email=original@example.test')->post('/reset-password', [
            'email' => $user->email, 'token' => Password::createToken($user), 'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password')->assertSessionHasInput('email', $user->email);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->get('/reset-password/token?email=original@example.test')->assertSee('value="'.$user->email.'"', false);
    }

    public function test_successful_reset_removes_all_database_sessions_including_legacy_rows_only_for_that_user(): void
    {
        config(['session.driver' => 'database']);
        [$user, $other] = User::factory()->count(2)->create()->all();
        foreach (['device-one' => $user->id, 'legacy-no-marker' => $user->id, 'other-user' => $other->id, 'guest' => null] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
        }
        $remember = $user->remember_token;
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => Password::createToken($user),
            'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'other-user']);
        $this->assertDatabaseHas('sessions', ['id' => 'guest']);
        $this->assertTrue(Hash::check('New-password-123!', $user->fresh()->password));
        $this->assertNotSame($remember, $user->fresh()->remember_token);
    }

    public function test_invalid_reset_does_not_revoke_sessions_or_change_credentials(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        DB::table('sessions')->insert(['id' => 'keep-device', 'user_id' => $user->id, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
        try {
            app(ResetUserPassword::class)->reset($user, ['password' => 'short', 'password_confirmation' => 'different']);
            $this->fail('Invalid password was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertDatabaseHas('sessions', ['id' => 'keep-device']);
        }
    }

    public function test_stale_password_hash_rejects_livewire_read_and_write_before_hydration(): void
    {
        $user = User::factory()->create(['bio' => 'Private original']);
        $response = $this->actingAs($user)->get('/settings/profile')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $oldHash = session('password_hash_web');
        $this->assertNotEmpty($oldHash);
        app(ResetUserPassword::class)->reset($user, ['token' => Password::createToken($user), 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!']);
        foreach (['$refresh', 'save'] as $method) {
            $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
                ->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), [
                    'components' => [['snapshot' => $snapshot, 'updates' => ['bio' => 'Unauthorized change'],
                        'calls' => [['path' => '', 'method' => $method, 'params' => []]]]],
                ])->assertUnauthorized()->assertDontSee('Private original');
            $this->assertSame('Private original', $user->fresh()->bio);
        }
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->get('/settings/profile')->assertRedirect('/login');
    }

    public function test_confirmation_requires_authentication_validates_input_and_preserves_intended_destination(): void
    {
        $this->get('/user/confirm-password')->assertRedirect('/login');
        $this->postJson('/user/confirm-password', ['password' => 'password'])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('Confirm your password');
        $this->getJson('/user/confirmed-password-status')->assertJson(['confirmed' => false]);
        $this->postJson('/user/confirm-password', ['password' => ['invalid']])->assertUnprocessable();
        $this->from('/user/confirm-password')->post('/user/confirm-password', ['password' => 'wrong'])
            ->assertRedirect('/user/confirm-password')->assertSessionHasErrors('password');
        $this->getJson('/user/confirmed-password-status')->assertJson(['confirmed' => false]);
        $this->withSession(['url.intended' => url('/settings/profile')])
            ->post('/user/confirm-password', ['password' => 'password'])->assertRedirect('/settings/profile');
        $this->getJson('/user/confirmed-password-status')->assertJson(['confirmed' => true]);
        $this->travel(10801)->seconds();
        $this->getJson('/user/confirmed-password-status')->assertJson(['confirmed' => false]);
    }

    public function test_confirmation_limiter_is_per_user_cannot_be_evaded_by_ip_and_expires(): void
    {
        [$user, $other] = User::factory()->count(2)->create()->all();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/user/confirm-password', ['password' => 'wrong'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->postJson('/user/confirm-password', ['password' => 'password'])->assertStatus(429)->assertHeader('Retry-After');
        Auth::logout();
        session()->flush();
        $this->actingAs($other)->postJson('/user/confirm-password', ['password' => 'password'])->assertCreated();
        Auth::logout();
        session()->flush();
        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/user/confirm-password', ['password' => 'password'])->assertCreated();
    }

    public function test_password_confirm_middleware_redirects_to_working_screen_then_resumes(): void
    {
        Route::middleware(['web', 'auth', 'password.confirm'])->get('/test-confirmed', fn () => 'Confirmed')->name('test-confirmed');
        $this->actingAs(User::factory()->create())->get('/test-confirmed')->assertRedirect('/user/confirm-password');
        $this->get('/user/confirm-password')->assertOk();
        $this->post('/user/confirm-password', ['password' => 'password'])->assertRedirect('/test-confirmed');
        $this->get('/test-confirmed')->assertOk()->assertSee('Confirmed');
    }
}
