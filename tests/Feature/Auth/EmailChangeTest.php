<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\EmailChanges;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Notifications\EmailChanged;
use App\Notifications\VerifyEmailChange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class EmailChangeTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function begin(User $user, string $email = 'proposed@example.test'): string
    {
        $this->actingAs($user)->get('/settings/security')->assertOk();
        $this->post(route('settings.email.request'), ['new_email' => $email, 'current_password' => 'password', 'generation' => PendingEmailChange::where('user_id', $user->id)->value('id') ?? 'none'])
            ->assertRedirect(route('settings.security'))->assertSessionHasNoErrors();
        $url = null;
        Notification::assertSentOnDemand(VerifyEmailChange::class, function ($notification, $channels, $notifiable) use (&$url, $email) {
            if ($notifiable->routes['mail'] === $email) {
                $url = $notification->url;

                return true;
            }

            return false;
        });
        $this->assertNotNull($url);

        return $url;
    }

    private function link(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $link);

        return $link;
    }

    public function test_request_is_private_and_read_only_until_password_confirmed_then_rotates_credentials(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test', 'username' => 'emailowner', 'bio' => 'Private original']);
        $habit = $this->habit(user: $user);
        $this->editor($habit)->set('form.status', 'completed')->set('form.note', 'Private note')->call('save')->assertHasNoErrors();
        DB::table('journals')->insert(['user_id' => $user->id, 'journal_date' => '2026-01-01', 'content' => 'Private journal', 'mood' => 3, 'revision' => 1]);
        $other = User::factory()->create();
        DB::table('follows')->insert(['follower_id' => $user->id, 'followed_id' => $other->id, 'status' => 'pending', 'requested_at' => now()]);
        $tables = ['habits', 'habit_entries', 'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_checkpoint_entries', 'habit_suspensions', 'journals', 'follows'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
        $profile = $user->fresh()->getAttributes();
        $oldToken = Password::createToken($user);
        DB::table('password_reset_tokens')->insert(['email' => 'proposed@example.test', 'token' => Hash::make('stale'), 'created_at' => now()]);
        $url = $this->begin($user);
        $pending = PendingEmailChange::sole();
        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->assertSame($profile['email_verified_at'], $user->fresh()->getRawOriginal('email_verified_at'));
        $this->assertTrue(Password::tokenExists($user, $oldToken));
        $this->assertSame(hash('sha256', $this->link($url)['token']), $pending->token_hash);
        $this->assertArrayNotHasKey('token_hash', $pending->toArray());
        $this->get($url)->assertOk()->assertSee('proposed@example.test')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->post($url, ['current_password' => 'wrong'])->assertSessionHasErrorsIn('emailConfirm', 'current_password');
        $this->assertSame('old@example.test', $user->fresh()->email);
        $oldSession = session()->getId();
        $oldCsrf = session()->token();
        $oldHash = $user->password;
        $this->post($url, ['current_password' => 'password'])->assertRedirect(route('settings.security'))->assertSessionHas('email-status');
        $fresh = $user->fresh();
        $this->assertSame('proposed@example.test', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $this->assertSame(Auth::guard('web')->hashPasswordForCookie($fresh->password), session('password_hash_web'));
        $this->assertDatabaseCount('pending_email_changes', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'proposed@example.test']);
        Notification::assertSentOnDemand(EmailChanged::class, fn ($n, $c, $to) => $to->routes['mail'] === 'old@example.test');
        foreach ($before as $table => $data) {
            $this->assertSame($data, DB::table($table)->get()->toJson(), $table);
        }
        foreach (array_diff_key($profile, array_flip(['email', 'email_verified_at', 'password', 'remember_token', 'updated_at'])) as $key => $value) {
            $this->assertSame($value, $fresh->getRawOriginal($key), $key);
        }
        $this->get('/settings/security')->assertOk();
        Livewire::test('pages::settings.profile')->set('bio', 'Saved after email change')->call('save')->assertHasNoErrors();
        $this->get($url)->assertOk()->assertSee(EmailChanges::INVALID)->assertDontSee('proposed@example.test');
        $this->post($url, ['current_password' => 'password'])->assertSessionHasErrorsIn('emailConfirm');
    }

    public function test_request_and_resend_do_not_disclose_membership_and_duplicates_fail_only_at_confirmation(): void
    {
        $existing = User::factory()->create(['email' => 'occupied@example.test']);
        $reset = Password::createToken($existing);
        $owner = User::factory()->create();
        $url = $this->begin($owner, 'OCCUPIED@example.test');
        $this->assertSame('Verification email sent. Open it to confirm the change within 60 minutes. Your current email remains active.', session('email-status'));
        $this->get($url)->assertOk();
        $this->post($url, ['current_password' => 'password'])->assertSessionHasErrorsIn('emailConfirm', 'email_change');
        $this->assertNotSame('OCCUPIED@example.test', $owner->fresh()->email);
        $this->assertTrue(Password::tokenExists($existing, $reset));
        $this->assertDatabaseCount('pending_email_changes', 1);
        $this->travel(61)->seconds();
        $this->post(route('settings.email.resend'), ['current_password' => 'password', 'generation' => PendingEmailChange::sole()->id])->assertSessionHasNoErrors();
        Notification::assertSentOnDemandTimes(VerifyEmailChange::class, 2);
    }

    public function test_validation_guests_wrong_owner_and_no_global_verification_gate(): void
    {
        $this->get('/settings/security')->assertRedirect('/login');
        $this->postJson(route('settings.email.request'), [])->assertUnauthorized();
        $owner = User::factory()->unverified()->create();
        foreach ([['new_email' => 'bad'], ['new_email' => ['bad']], ['new_email' => str_repeat('a', 256).'@example.test'], ['current_password' => ['bad']], ['current_password' => 'wrong'], ['new_email' => strtoupper($owner->email)]] as $invalid) {
            $this->travel(61)->seconds();
            $this->actingAs($owner)->from('/settings/security')->post(route('settings.email.request'), $invalid + ['new_email' => 'valid@example.test', 'current_password' => 'password', 'generation' => 'none'])
                ->assertSessionHasErrorsIn('emailRequest');
            $this->assertNull(session()->getOldInput('current_password'));
            $this->assertDatabaseCount('pending_email_changes', 0);
        }
        $this->travel(61)->seconds();
        $url = $this->begin($owner);
        $other = User::factory()->create();
        Auth::logout();
        session()->flush();
        $this->actingAs($other)->get($url)->assertOk()->assertDontSee('proposed@example.test');
        $this->post($url, ['current_password' => 'password'])->assertSessionHasErrorsIn('emailConfirm');
        $this->post(route('settings.email.cancel'), ['generation' => PendingEmailChange::sole()->id])->assertSessionHasErrorsIn('emailCancel');
        $this->assertDatabaseCount('pending_email_changes', 1);
        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_resend_replacement_cancellation_expiry_and_stale_forms(): void
    {
        $owner = User::factory()->create();
        $url = $this->begin($owner);
        $first = PendingEmailChange::sole()->id;
        $this->post(route('settings.email.resend'), ['current_password' => 'password', 'generation' => $first])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->post(route('settings.email.resend'), ['current_password' => 'password', 'generation' => $first])->assertSessionHasNoErrors();
        $second = PendingEmailChange::sole()->id;
        $this->assertNotSame($first, $second);
        $this->get($url)->assertOk();
        $this->post(route('settings.email.cancel'), ['generation' => $first])->assertSessionHasErrorsIn('emailCancel');
        $this->assertSame($second, PendingEmailChange::sole()->id);
        $this->travel(61)->seconds();
        $replacement = $this->begin($owner, 'replacement@example.test');
        $this->travel(61)->minutes();
        $this->get($replacement)->assertOk();
        $this->post($replacement, ['current_password' => 'password'])->assertRedirect(route('settings.security'));
        $this->get('/settings/security')->assertSee('Expired email change');
        $this->post(route('settings.email.cancel'), ['generation' => PendingEmailChange::sole()->id])->assertSessionHas('email-status');
        $this->assertDatabaseCount('pending_email_changes', 0);
        $this->get($replacement)->assertOk();
    }

    public function test_bad_signatures_tokens_source_and_generation_never_change_email(): void
    {
        $owner = User::factory()->create();
        $url = $this->begin($owner);
        $link = $this->link($url);
        foreach ([$url.'x', URL::temporarySignedRoute('settings.email.verify', now()->addHour(), array_replace(array_diff_key($link, array_flip(['signature', 'expires'])), ['token' => 'bad']))] as $bad) {
            $this->get($bad)->assertOk();
            $this->post($bad, ['current_password' => 'password']);
            $this->assertSame($owner->email, $owner->fresh()->email);
        }
        PendingEmailChange::query()->update(['source_email' => 'obsolete@example.test']);
        $this->get($url)->assertOk();
        $this->post($url, ['current_password' => 'password'])->assertSessionHasErrorsIn('emailConfirm');
    }

    public function test_password_change_and_reset_cancel_pending_and_stale_recovery_cannot_target_reassigned_email(): void
    {
        foreach (['change', 'reset'] as $operation) {
            Auth::logout();
            session()->flush();
            $user = User::factory()->create();
            $url = $this->begin($user, $operation.'@example.test');
            $token = Password::createToken($user);
            $input = ['current_password' => 'password', 'password' => 'Changed-password-456!', 'password_confirmation' => 'Changed-password-456!', 'token' => $token];
            if ($operation === 'change') {
                app(UpdateUserPassword::class)->update($user, $input);
            } else {
                app(ResetUserPassword::class)->reset($user, $input);
            }
            $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);
        }
        Auth::logout();
        session()->flush();
        $user = User::factory()->create(['email' => 'original@example.test']);
        $stale = $user->fresh();
        $url = $this->begin($user, 'acquired@example.test');
        $this->post($url, ['current_password' => 'password'])->assertSessionHas('email-status');
        $newOwner = User::factory()->create(['email' => 'original@example.test']);
        $newToken = Password::createToken($newOwner);
        $staleToken = Password::createToken($stale);
        Password::deleteToken($stale);
        $this->assertTrue(Password::tokenExists($newOwner, $newToken));
        $this->assertFalse(Password::tokenExists($newOwner, $staleToken));
        try {
            app(ResetUserPassword::class)->reset($stale, ['password' => 'Bad-password-789!', 'password_confirmation' => 'Bad-password-789!', 'token' => $newToken]);
            $this->fail('Stale reset accepted');
        } catch (ValidationException) {
            $this->assertTrue(Hash::check('password', $user->fresh()->password));
        }
    }

    public function test_delivery_failures_are_retryable_and_alert_failure_does_not_undo_confirmation(): void
    {
        $user = User::factory()->create();
        Notification::swap(new class
        {
            public function route($channel, $address)
            {
                return $this;
            }

            public function notify($notification)
            {
                throw new \RuntimeException('sensitive transport details');
            }
        });
        $this->actingAs($user)->post(route('settings.email.request'), ['new_email' => 'retry@example.test', 'current_password' => 'password', 'generation' => 'none'])
            ->assertSessionHasErrorsIn('emailRequest', 'email_change');
        $this->assertDatabaseCount('pending_email_changes', 1);
        $this->assertNotSame('retry@example.test', $user->fresh()->email);
        Notification::fake();
        $this->travel(61)->seconds();
        $url = $this->begin($user);
        Notification::swap(new class
        {
            public function route($channel, $address)
            {
                return $this;
            }

            public function notify($notification)
            {
                throw new \RuntimeException('sensitive transport details');
            }
        });
        $this->post($url, ['current_password' => 'password'])->assertSessionHas('email-status', fn ($message) => str_contains($message, 'Email changed.') && str_contains($message, 'could not deliver'));
        $this->assertSame('proposed@example.test', $user->fresh()->email);
    }

    public function test_atomic_failure_rolls_back_email_tokens_and_pending_state(): void
    {
        $user = User::factory()->create();
        $url = $this->begin($user);
        $before = $user->fresh()->getAttributes();
        $token = Password::createToken($user);
        config(['session.driver' => 'database', 'session.table' => 'missing_email_sessions']);
        try {
            app(EmailChanges::class)->confirm($user, $this->link($url), ['current_password' => 'password'], 'current');
            $this->fail('Cleanup failure ignored');
        } catch (QueryException) {
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertDatabaseCount('pending_email_changes', 1);
            $this->assertTrue(Password::tokenExists($user, $token));
        }
    }

    public function test_session_revocation_covers_legacy_rows_and_stale_livewire_markers(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['bio' => 'Private retained']);
        $other = User::factory()->create();
        $url = $this->begin($user);
        $response = $this->get('/settings/profile');
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $marker = session('password_hash_web');
        $remember = $user->remember_token;
        foreach (['legacy-no-marker' => $user->id, 'stale-email-session' => $user->id, 'other-email-owner' => $other->id] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
        }
        $this->post($url, ['current_password' => 'password'])->assertSessionHas('email-status');
        $this->assertDatabaseMissing('sessions', ['id' => 'legacy-no-marker']);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-email-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-email-owner']);
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        foreach (['$refresh', 'save'] as $method) {
            $this->actingAs($user->fresh())->withSession(['password_hash_web' => $marker])->withHeader('X-Livewire', 'true')
                ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => ['bio' => 'Rejected'], 'calls' => [['path' => '', 'method' => $method, 'params' => []]]]]])
                ->assertUnauthorized()->assertDontSee('Private retained');
        }
        $this->assertSame('Private retained', $user->fresh()->bio);
    }

    public function test_email_submission_limit_survives_session_and_ip_changes(): void
    {
        $user = User::factory()->create();
        $url = $this->begin($user);
        for ($i = 0; $i < 4; $i++) {
            $this->post($url, ['current_password' => 'wrong'])->assertSessionHasErrorsIn('emailConfirm', 'current_password');
        }
        Auth::logout();
        session()->flush();
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->post($url, ['current_password' => 'password'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNotSame('proposed@example.test', $user->fresh()->email);
        $this->travel(61)->seconds();
        $this->post($url, ['current_password' => 'password'])->assertSessionHas('email-status');
    }

    public function test_production_mail_cannot_log_verification_tokens_even_through_failover(): void
    {
        config(['app.url' => 'https://localhost']);
        $this->app->instance('env', 'production');
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            Auth::logout();
            session()->flush();
            $user = User::factory()->create();
            $this->actingAs($user)->withSession(['_token' => 'test-csrf'])->post(route('settings.email.request'), ['_token' => 'test-csrf', 'new_email' => 'safe@example.test', 'current_password' => 'password', 'generation' => 'none'])
                ->assertSessionHasErrorsIn('emailRequest', 'email_change');
            $this->assertDatabaseHas('pending_email_changes', ['user_id' => $user->id]);
        }
        Notification::assertNothingSent();
    }
}
