<?php

namespace Tests\Feature\Auth;

use App\Actions\Follows\FollowUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Actions\Journals\SaveJournal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tracking\TrackingTestCase;

class PasswordChangeTest extends TrackingTestCase
{
    use RefreshDatabase;

    private function input(array $overrides = []): array
    {
        return $overrides + ['current_password' => 'password', 'password' => 'Changed-password-456!', 'password_confirmation' => 'Changed-password-456!'];
    }

    public function test_guests_cannot_view_or_change_passwords(): void
    {
        $this->get('/settings/security')->assertRedirect('/login');
        $this->putJson('/user/password', $this->input())->assertUnauthorized();
    }

    public function test_security_form_uses_native_password_fields_and_only_current_phase_features(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/profile')->assertSee(route('settings.security'));
        $this->get('/settings/security')->assertOk()->assertSee('Security Settings')
            ->assertSee('autocomplete="current-password"', false)->assertSee('autocomplete="new-password"', false)
            ->assertSee('name="_token"', false)->assertSee('value="PUT"', false)
            ->assertDontSee('wire:model')->assertDontSee('Delete my account')->assertDontSee('Change email');
    }

    public function test_success_rotates_credentials_and_current_session_without_changing_profile(): void
    {
        $user = User::factory()->create(['bio' => 'Private unchanged', 'timezone' => 'Asia/Manila']);
        $before = $user->fresh()->getAttributes();
        $token = Password::createToken($user);
        $this->actingAs($user)->get('/settings/security')->assertOk();
        $oldSession = session()->getId();
        $oldCsrf = session()->token();
        $this->withSession(['auth.password_confirmed_at' => time()])->put('/user/password', $this->input())
            ->assertRedirect('/settings/security')->assertSessionHasNoErrors()->assertSessionHas('password-status');
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('Changed-password-456!', $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
        $this->assertNotSame($before['remember_token'], $fresh->remember_token);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $this->assertNull(session('auth.password_confirmed_at'));
        $this->assertSame(Auth::guard('web')->hashPasswordForCookie($fresh->password), session('password_hash_web'));
        $this->assertFalse(Password::tokenExists($fresh, $token));
        foreach (array_diff_key($before, array_flip(['password', 'remember_token', 'updated_at'])) as $key => $value) {
            $this->assertSame($value, $fresh->getRawOriginal($key), $key);
        }
        $this->get('/settings/security')->assertOk()->assertSee('Password changed.');
        Livewire::test('pages::settings.profile')->set('username', 'password_owner')->set('bio', 'Saved after change')->call('save')->assertHasNoErrors();
        $this->assertSame('Saved after change', $user->fresh()->bio);
    }

    public function test_validation_rejects_invalid_inputs_without_mutation_or_secret_flashing(): void
    {
        foreach ([
            ['current_password' => 'wrong'], ['current_password' => null], ['current_password' => ['bad']],
            ['password' => 'short', 'password_confirmation' => 'short'],
            ['password_confirmation' => 'different'], ['password' => null], ['password' => ['bad']],
            ['password' => "Invalid\0password!", 'password_confirmation' => "Invalid\0password!"],
            ['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)],
            ['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)],
        ] as $invalid) {
            $user = User::factory()->create();
            Auth::logout();
            session()->flush();
            $before = $user->fresh()->getAttributes();
            $token = Password::createToken($user);
            $this->actingAs($user)->from('/settings/security')->put('/user/password', $this->input($invalid))
                ->assertRedirect('/settings/security')->assertSessionHasErrorsIn('updatePassword', array_key_exists('current_password', $invalid) ? 'current_password' : 'password');
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertTrue(Password::tokenExists($user, $token));
            foreach (['current_password', 'password', 'password_confirmation'] as $field) {
                $this->assertNull(session()->getOldInput($field));
            }
        }
    }

    public function test_configured_password_strength_policy_is_applied(): void
    {
        PasswordRule::defaults(fn () => PasswordRule::min(12)->mixedCase()->numbers()->symbols());
        try {
            $user = User::factory()->create();
            foreach (['LongbutnoDigits!', 'longbutnoupper123!', 'NoSymbolPassword123'] as $weak) {
                $this->actingAs($user)->putJson('/user/password', $this->input(['password' => $weak, 'password_confirmation' => $weak]))
                    ->assertUnprocessable()->assertJsonValidationErrors('password');
            }
            $this->assertTrue(Hash::check('password', $user->fresh()->password));
        } finally {
            PasswordRule::defaults(fn () => null);
        }
    }

    public function test_new_password_that_looks_like_a_hash_is_still_hashed_as_literal_input(): void
    {
        $user = User::factory()->create();
        $literal = Hash::make('not-the-new-password');
        $this->actingAs($user)->putJson('/user/password', $this->input(['password' => $literal, 'password_confirmation' => $literal]))->assertOk();
        $this->assertTrue(Hash::check($literal, $user->fresh()->password));
        $this->assertFalse(Hash::check('not-the-new-password', $user->fresh()->password));
    }

    public function test_submitted_owner_and_profile_fields_cannot_modify_another_account(): void
    {
        [$owner, $other] = User::factory()->count(2)->create()->all();
        $before = $other->fresh()->getAttributes();
        $this->actingAs($owner)->putJson('/user/password', $this->input(['user_id' => $other->id, 'id' => $other->id, 'email' => 'injected@example.test', 'is_private' => false]))->assertOk();
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertTrue($owner->fresh()->is_private);
    }

    public function test_action_rejects_foreign_owner_even_with_correct_password(): void
    {
        [$owner, $other] = User::factory()->count(2)->create()->all();
        $before = $other->fresh()->getAttributes();
        $this->actingAs($owner);
        try {
            app(UpdateUserPassword::class)->update($other, $this->input());
            $this->fail('Foreign owner accepted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($before, $other->fresh()->getAttributes());
        }
    }

    public function test_limiter_is_bound_to_user_across_ip_and_session_changes_and_expires(): void
    {
        [$user, $other] = User::factory()->count(2)->create()->all();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/user/password', $this->input(['current_password' => 'wrong']))->assertUnprocessable();
        }
        Auth::logout();
        session()->flush();
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->putJson('/user/password', $this->input())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        Auth::logout();
        session()->flush();
        $this->actingAs($other)->putJson('/user/password', $this->input())->assertOk();
        Auth::logout();
        session()->flush();
        $this->travel(61)->seconds();
        $this->actingAs($user)->putJson('/user/password', $this->input())->assertOk();
    }

    public function test_database_session_cleanup_includes_legacy_rows_but_preserves_other_owners(): void
    {
        config(['session.driver' => 'database']);
        [$user, $other] = User::factory()->count(2)->create()->all();
        foreach (['stale' => $user->id, 'legacy-no-marker' => $user->id, 'other-owner' => $other->id, 'guest' => null] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
        }
        $this->actingAs($user)->putJson('/user/password', $this->input())->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'stale']);
        $this->assertDatabaseMissing('sessions', ['id' => 'legacy-no-marker']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-owner']);
        $this->assertDatabaseHas('sessions', ['id' => 'guest']);
        $this->get('/settings/security')->assertOk();
    }

    public function test_stale_admitted_change_and_reset_cannot_overwrite_committed_credentials(): void
    {
        $user = User::factory()->create();
        $stale = $user->fresh();
        $token = Password::createToken($user);
        $this->actingAs($user)->putJson('/user/password', $this->input())->assertOk();
        $before = $user->fresh()->getAttributes();
        foreach ([
            fn () => app(UpdateUserPassword::class)->update($stale, $this->input()),
            fn () => app(ResetUserPassword::class)->reset($stale, $this->input(['token' => $token])),
            fn () => app(ResetUserPassword::class)->reset($user->fresh(), $this->input(['token' => $token])),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Stale credential write accepted.');
            } catch (ValidationException) {
                $this->assertSame($before, $user->fresh()->getAttributes());
            }
        }
    }

    public function test_old_password_hash_cannot_read_or_write_livewire_or_change_password_again(): void
    {
        $user = User::factory()->create(['bio' => 'Private original']);
        $response = $this->actingAs($user)->get('/settings/profile')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $oldHash = session('password_hash_web');
        $this->putJson('/user/password', $this->input())->assertOk();
        foreach (['$refresh', 'save'] as $method) {
            $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->withHeader('X-Livewire', 'true')
                ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => ['bio' => 'Rejected'], 'calls' => [['path' => '', 'method' => $method, 'params' => []]]]]])
                ->assertUnauthorized()->assertDontSee('Private original');
            $this->assertSame('Private original', $user->fresh()->bio);
        }
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->putJson('/user/password', $this->input())->assertUnauthorized();
    }

    public function test_failed_session_cleanup_rolls_back_password_remember_token_and_reset_token(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'missing_password_change_sessions']);
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $token = Password::createToken($user);
        $this->actingAs($user);
        try {
            // Direct action avoids the intentionally missing session table in middleware.
            request()->setLaravelSession(session()->driver('array'));
            app(UpdateUserPassword::class)->update($user, $this->input());
            $this->fail('Synthetic cleanup failure was ignored.');
        } catch (QueryException) {
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertTrue(Password::tokenExists($user, $token));
        }
    }

    public function test_password_change_preserves_complete_activity_and_social_history(): void
    {
        [$user, $other] = User::factory()->count(2)->create()->all();
        $habit = $this->habit('checkpoint', user: $user);
        $version = $habit->versions()->sole();
        app(SaveHabitEntry::class)->handle($user, $habit->id, '2026-09-27', ['status' => 'completed', 'completed' => $version->checkpoints()->pluck('id')->all(), 'note' => 'Private note'], null, $version->id, $version->revision, 'UTC');
        app(SaveJournal::class)->handle($user, '2026-09-27', ['content' => 'Private journal', 'mood' => 4], null, null, 'UTC');
        app(FollowUser::class)->handle($user, $other->id);
        app(FollowUser::class)->handle($other, $user->id);
        app(TransitionHabit::class)->handle($user, $habit->id, 'archive');
        $tables = ['categories', 'category_aliases', 'habits', 'habit_checkpoints', 'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_entries', 'habit_checkpoint_entries', 'habit_suspensions', 'journals', 'follows'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->actingAs($user)->putJson('/user/password', $this->input())->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }
}
