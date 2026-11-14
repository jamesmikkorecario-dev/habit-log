<?php

namespace Tests\Feature\Auth;

use App\Auth\CredentialUrls;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tracking\TrackingTestCase;

class EmailVerificationTest extends TrackingTestCase
{
    use RefreshDatabase;

    private function link(User $user, array $parameters = []): string
    {
        return CredentialUrls::generator()->temporarySignedRoute('verification.verify', now()->addHour(), $parameters + [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
    }

    public function test_registration_sends_an_expiring_signed_notification_without_accepting_a_verification_timestamp(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'New owner', 'email' => 'verify@example.test',
            'password' => 'password', 'password_confirmation' => 'password', 'email_verified_at' => now()->toDateTimeString()])
            ->assertRedirect('/email/verify');
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame(now()->addHour()->timestamp, (int) $query['expires']);
            $this->get($url)->assertRedirect('/dashboard?verified=1');

            return true;
        });
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_existing_unverified_accounts_can_log_in_resend_and_log_out_without_being_backfilled(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->get('/email/verify')->assertOk()->assertSee('Verify your email')->assertSee(e($owner->email), false)
            ->assertSee('Resend verification email')->assertSee('Log out')->assertDontSee('Account menu')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->from('/email/verify')->post(route('verification.send'))->assertRedirect('/email/verify')
            ->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($owner, VerifyEmail::class);
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_verified_accounts_keep_access_and_do_not_receive_resends(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $timestamp = $owner->getRawOriginal('email_verified_at');
        $this->actingAs($owner)->get('/email/verify')->assertRedirect('/dashboard');
        $this->post(route('verification.send'))->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        Notification::assertNothingSent();
        $this->assertSame($timestamp, $owner->fresh()->getRawOriginal('email_verified_at'));
    }

    public function test_verification_is_idempotent_and_emits_the_verified_event_once(): void
    {
        Event::fake([Verified::class]);
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = $this->link($owner);
        $this->actingAs($owner)->get($url)->assertRedirect('/dashboard?verified=1');
        $timestamp = $owner->fresh()->getRawOriginal('email_verified_at');
        $this->travel(1)->minutes();
        $this->get($url)->assertRedirect('/dashboard?verified=1');
        $this->assertSame($timestamp, $owner->fresh()->getRawOriginal('email_verified_at'));
        $this->assertNull($other->fresh()->email_verified_at);
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_foreign_signed_links_do_not_verify_either_account(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->get($this->link($owner))->assertForbidden()
            ->assertSee('This verification link is invalid or has expired.');
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_links_are_rejected_with_a_safe_retry_path(string $kind): void
    {
        Event::fake([Verified::class]);
        $owner = User::factory()->unverified()->create();
        $url = match ($kind) {
            'wrong-id' => $this->link($owner, ['id' => $owner->id + 1000]),
            'wrong-email' => $this->link($owner, ['hash' => sha1('foreign@example.test')]),
            default => $this->link($owner),
        };
        if ($kind === 'tampered') {
            $url .= 'tampered';
        } elseif ($kind === 'expired') {
            $this->travel(61)->minutes();
        } elseif ($kind === 'changed-email') {
            $owner->update(['email' => 'changed@example.test']);
        }
        $this->actingAs($owner->fresh())->get($url)->assertForbidden()
            ->assertSee('This verification link is invalid or has expired.')
            ->assertSee('Resend verification email')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNull($owner->fresh()->email_verified_at);
        Event::assertNotDispatched(Verified::class);
    }

    public static function invalidLinks(): array
    {
        return [['tampered'], ['expired'], ['wrong-id'], ['wrong-email'], ['changed-email']];
    }

    public function test_guest_verification_requires_login_and_preserves_the_intended_signed_link(): void
    {
        $owner = User::factory()->unverified()->create();
        $url = $this->link($owner);
        $this->get($url)->assertRedirect('/login');
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect($url);
        $this->get($url)->assertRedirect('/dashboard?verified=1');
        $this->get('/dashboard')->assertOk();
        Auth::logout();
        $this->get('/email/verify')->assertRedirect('/login');
        $this->postJson(route('verification.send'))->assertUnauthorized();
    }

    public function test_resends_and_link_requests_are_rate_limited_per_account(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson(route('verification.send'))->assertStatus(202);
        }
        $this->postJson(route('verification.send'))->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson($this->link($owner))->assertStatus(429);
        Notification::assertSentToTimes($owner, VerifyEmail::class, 6);
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->postJson(route('verification.send'))->assertStatus(202);
        $this->actingAs($owner);
        $this->travel(61)->seconds();
        $this->get($this->link($owner))->assertRedirect('/dashboard?verified=1');
    }

    #[DataProvider('protectedReads')]
    public function test_unverified_accounts_cannot_read_application_pages(string $path): void
    {
        $owner = User::factory()->unverified()->create();
        $habit = $this->habit(user: $owner);
        $other = User::factory()->create();
        $path = str_replace(['{habit}', '{username}'], [$habit->id, $other->username], $path);
        $this->actingAs($owner)->get($path)->assertRedirect(route('verification.notice'));
        $this->getJson($path)->assertForbidden();
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public static function protectedReads(): array
    {
        return array_map(fn ($path) => [$path], ['/dashboard', '/tracking', '/journal', '/habits', '/habits/create',
            '/habits/{habit}', '/habits/{habit}/edit', '/habits/{habit}/entries', '/habits/{habit}/entries/2026-09-27',
            '/categories', '/people', '/people/{username}', '/people/{username}/habits/{habit}',
            '/settings/profile', '/settings/security', '/user/confirm-password', '/user/confirmed-password-status']);
    }

    #[DataProvider('protectedWrites')]
    public function test_unverified_accounts_cannot_mutate_security_settings(string $method, string $route): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        $before = $owner->fresh()->getAttributes();
        $this->actingAs($owner)->json($method, route($route), [
            'current_password' => 'password', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!',
            'new_email' => 'changed@example.test', 'generation' => 'none', 'account_id' => $owner->id, 'acknowledge_deletion' => '1',
        ])->assertForbidden();
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertDatabaseCount('pending_email_changes', 0);
        Notification::assertNothingSent();
    }

    public static function protectedWrites(): array
    {
        return [['PUT', 'user-password.update'], ['POST', 'password.confirm.store'],
            ['POST', 'settings.email.request'], ['POST', 'settings.email.resend'], ['POST', 'settings.email.cancel'],
            ['POST', 'settings.email.confirm'], ['POST', 'settings.sessions.revoke'], ['DELETE', 'settings.account.destroy']];
    }

    public function test_livewire_rechecks_verification_on_previously_authorized_snapshots(): void
    {
        $owner = User::factory()->create();
        foreach (['/dashboard', '/tracking', '/journal', '/habits', '/categories', '/people', '/settings/profile'] as $path) {
            $owner->forceFill(['email_verified_at' => now()])->save();
            $response = $this->actingAs($owner)->get($path)->assertOk();
            preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
            $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            DB::table('users')->where('id', $owner->id)->update(['email_verified_at' => null]);
            $this->actingAs($owner->fresh())->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), [
                'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]],
            ])->assertForbidden();
        }
        $this->assertDatabaseCount('habits', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    #[DataProvider('staleIdentities')]
    public function test_verification_rechecks_locked_account_identity_and_credentials(string $change): void
    {
        $owner = User::factory()->unverified()->create();
        $stale = $owner->fresh();
        if (in_array($change, ['deleted', 'replaced'], true)) {
            DB::table('users')->where('id', $owner->id)->delete();
            if ($change === 'replaced') {
                $replacement = User::factory()->unverified()->create([
                    'id' => $owner->id, 'email' => $owner->email, 'password' => $owner->password,
                ]);
                $this->assertNotSame($owner->recovery_uuid, $replacement->recovery_uuid);
            }
        } else {
            $owner->update($change === 'email' ? ['email' => 'changed@example.test'] : ['password' => 'new-password']);
        }
        try {
            $stale->markEmailAsVerified();
            $this->fail('A stale identity was verified.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseMissing('users', ['id' => $owner->id, 'email_verified_at' => now()]);
            $this->assertNull($owner->fresh()?->email_verified_at);
        }
    }

    public static function staleIdentities(): array
    {
        return [['email'], ['password'], ['deleted'], ['replaced']];
    }

    public function test_resend_requires_csrf(): void
    {
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner)->withSession(['_token' => 'known-csrf']);
        $this->app->instance('env', 'local');
        $this->post(route('verification.send'), ['_token' => 'wrong-csrf'])->assertStatus(419);
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_password_reset_does_not_verify_an_existing_unverified_email(): void
    {
        $owner = User::factory()->unverified()->create();
        $token = Password::createToken($owner);
        $this->post(route('password.update'), ['email' => $owner->email, 'token' => $token,
            'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertRedirect('/login');
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->post('/login', ['email' => $owner->email, 'password' => 'New-password-123!'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_a_second_admitted_verification_preserves_the_first_timestamp(): void
    {
        $owner = User::factory()->unverified()->create();
        $stale = $owner->fresh();
        $this->assertTrue($owner->markEmailAsVerified());
        $timestamp = $owner->fresh()->getRawOriginal('email_verified_at');
        $this->travel(1)->minutes();
        $this->assertFalse($stale->markEmailAsVerified());
        $this->assertSame($timestamp, $owner->fresh()->getRawOriginal('email_verified_at'));
    }
}
