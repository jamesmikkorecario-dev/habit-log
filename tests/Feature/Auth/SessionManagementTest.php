<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\ManageSessions;
use App\Actions\Accounts\SessionSecurity;
use App\Auth\RevocableDatabaseSessionHandler;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tracking\TrackingTestCase;

class SessionManagementTest extends TrackingTestCase
{
    use RefreshDatabase;

    private function row(User $user, string $id, int $age = 0): string
    {
        $key = (string) Str::uuid();
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => base64_encode('{}'), 'last_activity' => now()->subMinutes($age)->timestamp,
            'ip_address' => '192.0.2.77', 'user_agent' => 'Chrome/100 Windows <script>private marker</script>', 'management_id' => $key, 'origin_hash' => hash('sha256', $id)]);

        return $key;
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/security')->assertOk();
        // Feature requests share Laravel's in-memory test session. Exercise the
        // database management contract with explicit rows; browser tests cover
        // real cookie persistence and the database handler across requests.
        config(['session.driver' => 'database']);

        return $user;
    }

    public function test_list_is_owner_scoped_paginated_and_never_exposes_private_identifiers_or_payloads(): void
    {
        $user = $this->owner();
        $other = User::factory()->create();
        $key = $this->row($user, 'secret-session-id');
        $foreign = $this->row($other, 'foreign-session-id');
        $expired = $this->row($user, 'expired-session-id', 121);
        $this->get('/settings/security')->assertOk()->assertSee('This session')->assertSee('Chrome on Windows')->assertSee($key)
            ->assertDontSee($foreign)->assertDontSee($expired)->assertDontSee('secret-session-id')->assertDontSee('192.0.2.77')->assertDontSee('private marker')->assertDontSee(base64_encode('{}'));
        for ($i = 0; $i < 12; $i++) {
            $this->row($user, 'paging-'.$i);
        }
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/settings/security?page=2')->assertOk()->assertSee('This session');
        $this->assertSame(13, $response->viewData('sessionListing')['sessions']->total());
        $this->assertSame(3, $response->viewData('sessionListing')['sessions']->count());
    }

    public function test_individual_revocation_preserves_other_sessions_password_and_activity(): void
    {
        $user = $this->owner();
        $before = $user->fresh()->getAttributes();
        $habit = $this->habit(user: $user);
        DB::table('journals')->insert(['user_id' => $user->id, 'journal_date' => '2026-01-01', 'content' => 'Preserved private journal', 'mood' => 3, 'revision' => 1]);
        $tables = ['habits', 'habit_entries', 'habit_tracking_versions', 'journals', 'follows'];
        $activity = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()]);
        $target = $this->row($user, 'selected');
        $this->row($user, 'keep');
        $foreign = User::factory()->create();
        $this->row($foreign, 'foreign');
        $oldId = session()->getId();
        $oldCsrf = session()->token();
        $this->post(route('settings.sessions.revoke'), ['current_password' => 'password', 'session_target' => $target])->assertSessionHasNoErrors()->assertSessionHas('session-status');
        $this->assertDatabaseMissing('sessions', ['id' => 'selected']);
        $this->assertDatabaseHas('sessions', ['id' => 'keep']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', 'selected'), 'user_id' => $user->id]);
        $this->assertSame($before['password'], $user->fresh()->password);
        $this->assertNotSame($before['remember_token'], $user->fresh()->remember_token);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        foreach ($activity as $table => $records) {
            $this->assertSame($records, DB::table($table)->get()->toJson());
        }
        foreach (array_diff_key($before, array_flip(['remember_token', 'updated_at'])) as $field => $value) {
            $this->assertSame($value, $user->fresh()->getRawOriginal($field), $field);
        }
        $this->get('/settings/profile')->assertOk();
    }

    public function test_validation_authorization_replay_current_and_expired_sessions(): void
    {
        $this->postJson(route('settings.sessions.revoke'), [])->assertUnauthorized();
        $user = $this->owner();
        $other = User::factory()->create();
        $foreign = $this->row($other, 'foreign');
        $expired = $this->row($user, 'expired', 121);
        $current = DB::table('sessions')->where('id', session()->getId())->value('management_id');
        foreach ([$foreign, $expired, $current ?? (string) Str::uuid(), 'bad-id'] as $key) {
            $this->post(route('settings.sessions.revoke'), ['current_password' => 'password', 'session_target' => $key])->assertSessionHasErrorsIn('sessionRevoke');
        }
        $this->travel(61)->seconds();
        $target = $this->row($user, 'selected');
        $this->post(route('settings.sessions.revoke'), ['current_password' => 'wrong', 'session_target' => $target])->assertSessionHasErrorsIn('sessionRevoke', 'current_password');
        $this->assertNull(session()->getOldInput('current_password'));
        $this->assertDatabaseHas('sessions', ['id' => 'selected']);
        $this->post(route('settings.sessions.revoke'), ['current_password' => 'password', 'session_target' => $target])->assertSessionHas('session-status');
        $this->post(route('settings.sessions.revoke'), ['current_password' => 'password', 'session_target' => $target])->assertSessionHasErrorsIn('sessionRevoke');
        $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
    }

    public function test_bulk_rehashes_keeps_current_and_rejects_restored_legacy_payload(): void
    {
        $user = $this->owner();
        $hash = $user->password;
        $this->row($user, 'legacy');
        $this->post(route('settings.sessions.revoke'), ['current_password' => 'password', 'session_target' => 'all'])->assertSessionHas('session-status');
        $this->assertNotSame($hash, $user->fresh()->password);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->get('/settings/profile')->assertOk();
        // Even a missing password marker must not re-enroll a revoked origin.
        $this->actingAs($user->fresh())->withSession(['revocation_origin' => hash('sha256', 'legacy')])->getJson('/settings/profile')->assertUnauthorized();
    }

    public function test_late_write_cannot_recreate_revoked_session_even_after_id_regeneration(): void
    {
        $user = $this->owner();
        $this->row($user, 'old-origin');
        session()->put('revocation_origin', hash('sha256', 'old-origin'));
        DB::transaction(fn () => SessionSecurity::revokeRows($user, DB::table('sessions')->where('id', 'old-origin')->get()));
        $request = request();
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(session()->driver());
        $handler = new RevocableDatabaseSessionHandler(DB::connection(), 'sessions', 120, app());
        $this->assertTrue($handler->write('regenerated-stale-id', '{}'));
        $this->assertDatabaseMissing('sessions', ['id' => 'regenerated-stale-id']);
    }

    public function test_current_session_cannot_be_selected_and_atomic_failure_restores_revocation_state(): void
    {
        $user = $this->owner();
        request()->setUserResolver(fn () => $user);
        request()->setLaravelSession(session()->driver());
        $key = $this->row($user, session()->getId());
        try {
            app(ManageSessions::class)->revoke(request(), 'password', $key);
            $this->fail('Current session could be selected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('session_target', $e->errors());
        }
        $target = $this->row($user, 'atomic-target');
        $original = $user->fresh()->getAttributes();
        User::updating(function ($updated) use ($user) {
            if ($updated->id === $user->id) {
                throw new \RuntimeException('Injected credential-save failure');
            }
        });
        try {
            app(ManageSessions::class)->revoke(request(), 'password', $target);
            $this->fail('Injected failure was ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected credential-save failure', $e->getMessage());
            $this->assertSame($original, $user->fresh()->getAttributes());
            $this->assertDatabaseHas('sessions', ['id' => 'atomic-target']);
            $this->assertDatabaseMissing('session_revocations', ['session_hash' => hash('sha256', 'atomic-target')]);
        } finally {
            User::flushEventListeners();
        }
    }

    public function test_rate_limit_survives_session_switch_and_unsupported_driver_has_no_management(): void
    {
        $user = $this->owner();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('settings.sessions.revoke'), ['current_password' => 'wrong', 'session_target' => 'all'])->assertSessionHasErrorsIn('sessionRevoke');
        }
        Auth::logout();
        session()->flush();
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->post(route('settings.sessions.revoke'), ['current_password' => 'password', 'session_target' => 'all'])->assertStatus(429);
        config(['session.driver' => 'array']);
        $this->get('/settings/security')->assertOk()->assertSee('Session management is unavailable')->assertDontSee('Sign out all other sessions');
        $this->assertSame('Unknown browser on unknown device', ManageSessions::device(null));
    }
}
