<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\DeleteAccount;
use App\Auth\RevocableDatabaseSessionHandler;
use App\Models\Category;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tracking\TrackingTestCase;
use Tests\Fixtures\AccountDeletionData;

class AccountDeletionTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    private function owner(?User $user = null): User
    {
        $user ??= User::factory()->create();
        config(['session.driver' => 'database']);
        $this->actingAs($user)->get('/settings/security')->assertOk();
        request()->setUserResolver(fn () => $user);
        request()->setLaravelSession(session()->driver());
        config(['session.driver' => 'database']);

        return $user;
    }

    private function input(User $user): array
    {
        return ['account_id' => $user->id, 'current_password' => 'password', 'acknowledge_deletion' => '1'];
    }

    private function snapshot(): array
    {
        return collect(['users', 'categories', 'category_aliases', 'habits', 'habit_entries', 'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_checkpoints', 'habit_checkpoint_entries', 'habit_suspensions', 'journals', 'follows', 'pending_email_changes', 'password_reset_tokens', 'session_revocations'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    }

    public function test_deletion_removes_complete_graph_and_auth_state_preserving_foreign_records_and_scores(): void
    {
        $owner = $this->owner();
        AccountDeletionData::populate($owner);
        $foreign = User::factory()->create();
        AccountDeletionData::populate($foreign);
        $system = Category::factory()->system()->create();
        $neighbor = User::factory()->create();
        $foreignFollow = DB::table('follows')->insertGetId(['follower_id' => $foreign->id, 'followed_id' => $neighbor->id, 'status' => 'pending', 'requested_at' => now()]);
        $foreignPending = (string) Str::uuid();
        DB::table('pending_email_changes')->insert(['id' => $foreignPending, 'user_id' => $foreign->id, 'new_email' => 'retained@example.test', 'source_email' => $foreign->email, 'token_hash' => hash('sha256', 'foreign-pending'), 'expires_at' => now()->addHour(), 'last_sent_at' => now()]);
        $foreignAttributes = $foreign->fresh()->getAttributes();
        $foreignBefore = $this->snapshot();
        $analytics = app(ActivityAnalytics::class)->report($foreign, 2024);
        foreach ([[$owner->id, $foreign->id, 'pending'], [$foreign->id, $owner->id, 'accepted']] as [$from, $to, $status]) {
            DB::table('follows')->insert(['follower_id' => $from, 'followed_id' => $to, 'status' => $status, 'requested_at' => now(), 'accepted_at' => $status === 'accepted' ? now() : null]);
        }
        Password::createToken($owner);
        $foreignToken = Password::createToken($foreign);
        DB::table('pending_email_changes')->insert(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'new_email' => $foreign->email, 'source_email' => $owner->email, 'token_hash' => hash('sha256', 'pending'), 'expires_at' => now()->addHour(), 'last_sent_at' => now()]);
        foreach ([$owner, $foreign] as $u) {
            DB::table('sessions')->insert(['id' => 'session-'.$u->id, 'user_id' => $u->id, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
            DB::table('session_revocations')->insert(['session_hash' => hash('sha256', 'old-'.$u->id), 'user_id' => $u->id, 'revoked_at' => now()]);
        }
        $csrf = session()->token();
        $this->delete(route('settings.account.destroy'), $this->input($owner))->assertRedirect(route('account.deleted'));
        $this->assertGuest();
        $this->assertNotSame($csrf, session()->token());
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        foreach (['habits', 'habit_entries', 'categories', 'journals', 'pending_email_changes', 'sessions', 'session_revocations'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $owner->id]);
        }
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseHas('follows', ['id' => $foreignFollow]);
        $this->assertDatabaseHas('pending_email_changes', ['id' => $foreignPending]);
        $this->assertSame($foreignAttributes, $foreign->fresh()->getAttributes());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertTrue(Password::tokenExists($foreign, $foreignToken));
        $this->assertDatabaseHas('sessions', ['id' => 'session-'.$foreign->id]);
        $this->assertDatabaseHas('session_revocations', ['user_id' => $foreign->id]);
        $this->assertDatabaseHas('categories', ['id' => $system->id]);
        $this->assertSame($analytics, app(ActivityAnalytics::class)->report($foreign, 2024));
        foreach (['habit_entries', 'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_checkpoints', 'habit_checkpoint_entries', 'habit_suspensions'] as $table) {
            $this->assertSame(intdiv(count(json_decode($foreignBefore[$table], true)), 2), DB::table($table)->count(), $table);
            $original = collect(json_decode($foreignBefore[$table], true))->keyBy('id');
            foreach (DB::table($table)->get() as $row) {
                $this->assertSame($original[$row->id], (array) $row, $table);
            }
        }
        foreach (['categories', 'category_aliases', 'journals', 'follows', 'pending_email_changes'] as $table) {
            $original = collect(json_decode($foreignBefore[$table], true))->keyBy('id');
            foreach (DB::table($table)->get() as $row) {
                $this->assertSame($original[$row->id], (array) $row, $table);
            }
        }
        $this->assertFalse(Gate::forUser($foreign)->allows('viewShared', $owner));
        $this->get('/account-deleted')->assertOk()->assertDontSee('DELETE-PRIVATE');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->deleteJson(route('settings.account.destroy'), $this->input($owner))->assertUnauthorized();
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertFalse(Gate::forUser($foreign)->allows('delete', $owner));
    }

    public function test_password_acknowledgment_owner_pinning_and_account_bound_throttle(): void
    {
        $this->deleteJson(route('settings.account.destroy'))->assertUnauthorized();
        $user = $this->owner();
        $other = User::factory()->create();
        $before = $this->snapshot();
        $this->delete(route('settings.account.destroy'), array_replace($this->input($user), ['current_password' => 'wrong']))->assertRedirect(route('settings.security'))->assertSessionHasErrorsIn('accountDeletion', 'current_password');
        $this->delete(route('settings.account.destroy'), array_replace($this->input($user), ['acknowledge_deletion' => '0']))->assertRedirect(route('settings.security'))->assertSessionHasErrorsIn('accountDeletion', 'acknowledge_deletion');
        $this->delete(route('settings.account.destroy'), $this->input($other))->assertForbidden();
        $this->assertNull(session()->getOldInput('current_password'));
        $this->assertSame($before, $this->snapshot());
        for ($i = 0; $i < 2; $i++) {
            $this->delete(route('settings.account.destroy'), [])->assertRedirect(route('settings.security'))->assertSessionHasErrorsIn('accountDeletion');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.90'])->delete(route('settings.account.destroy'), $this->input($user))->assertStatus(429);
    }

    public function test_cross_owner_references_fail_closed_without_deleting_any_records(): void
    {
        $user = $this->owner();
        $ids = AccountDeletionData::populate($user);
        $foreign = User::factory()->create();
        $other = $this->habit(user: $foreign);
        // Valid FK, invalid ownership: another owner's habit references this category.
        DB::table('habits')->where('id', $other->id)->update(['category_id' => DB::table('habits')->where('id', $ids[0])->value('category_id')]);
        $before = $this->snapshot();
        $this->delete(route('settings.account.destroy'), $this->input($user))->assertRedirect(route('settings.security'))->assertSessionHasErrorsIn('accountDeletion', 'deletion');
        $this->assertSame($before, $this->snapshot());
        DB::table('habits')->where('id', $other->id)->update(['category_id' => Category::factory()->system()->create()->id]);
        DB::table('habit_entries')->where('habit_id', $ids[0])->update(['user_id' => $foreign->id]);
        $before = $this->snapshot();
        $this->delete(route('settings.account.destroy'), $this->input($user))->assertRedirect(route('settings.security'))->assertSessionHasErrorsIn('accountDeletion', 'deletion');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_mid_transaction_failure_rolls_back_graph_and_keeps_the_account_signed_in(): void
    {
        $user = $this->owner();
        AccountDeletionData::populate($user);
        Password::createToken($user);
        $before = $this->snapshot();
        User::deleting(function ($model) {
            throw new QueryException('mysql', 'synthetic failure', [], new \PDOException('private-marker'));
        });
        $this->delete(route('settings.account.destroy'), $this->input($user))->assertRedirect(route('settings.security'))->assertSessionHasErrorsIn('accountDeletion', 'deletion');
        $this->assertSame($before, $this->snapshot());
        $this->assertAuthenticatedAs($user);
        $this->get('/settings/security')->assertOk()->assertDontSee('private-marker');
    }

    public function test_cross_owner_checkpoint_references_cannot_cascade_or_be_nullified(): void
    {
        $user = $this->owner();
        $owned = AccountDeletionData::populate($user);
        $foreign = User::factory()->create();
        $others = AccountDeletionData::populate($foreign);
        $ownedEntry = DB::table('habit_entries')->where('habit_id', $owned[0])->first();
        $foreignEntry = DB::table('habit_entries')->where('habit_id', $others[0])->first();
        $foreignSnapshot = DB::table('habit_tracking_checkpoints')->where('habit_tracking_version_id', $foreignEntry->habit_tracking_version_id)->first();
        $completion = DB::table('habit_checkpoint_entries')->where('habit_entry_id', $ownedEntry->id)->first();
        DB::table('habit_checkpoint_entries')->where('id', $completion->id)->update(['habit_tracking_checkpoint_id' => $foreignSnapshot->id]);
        $before = $this->snapshot();
        $this->delete(route('settings.account.destroy'), $this->input($user))->assertSessionHasErrorsIn('accountDeletion', 'deletion');
        $this->assertSame($before, $this->snapshot());
        DB::table('habit_checkpoint_entries')->where('id', $completion->id)->update(['habit_tracking_checkpoint_id' => $completion->habit_tracking_checkpoint_id]);
        DB::table('habit_tracking_checkpoints')->where('id', $foreignSnapshot->id)->update(['habit_checkpoint_id' => DB::table('habit_checkpoints')->where('habit_id', $owned[0])->value('id')]);
        $before = $this->snapshot();
        $this->delete(route('settings.account.destroy'), $this->input($user))->assertSessionHasErrorsIn('accountDeletion', 'deletion');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_large_history_is_deleted_in_bounded_queries_without_loading_records(): void
    {
        $user = $this->owner();
        $habit = $this->habit(overrides: ['start_date' => '2000-01-01'], user: $user);
        $version = $habit->versions()->firstOrFail();
        $start = CarbonImmutable::parse('2000-01-01');
        for ($chunk = 0; $chunk < 10; $chunk++) {
            $rows = [];
            for ($i = 0; $i < 500; $i++) {
                $rows[] = ['user_id' => $user->id, 'habit_id' => $habit->id, 'habit_tracking_version_id' => $version->id,
                    'entry_date' => $start->addDays($chunk * 500 + $i)->toDateString(), 'status' => 'completed', 'revision' => 1, 'note' => str_repeat('Synthetic history ', 100)];
            }
            DB::table('habit_entries')->insert($rows);
        }
        unset($rows);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $memory = memory_get_usage(true);
        $started = microtime(true);
        app(DeleteAccount::class)->handle(request(), $this->input($user));
        $elapsed = microtime(true) - $started;
        $memoryDelta = max(0, memory_get_usage(true) - $memory);
        $this->assertLessThan(40, $queries);
        $this->assertLessThan(16 * 1024 * 1024, $memoryDelta);
        file_put_contents(base_path('storage/app/account-deletion-size.json'), json_encode(['entries' => 5000, 'queries' => $queries, 'seconds' => $elapsed, 'memory_delta_bytes' => $memoryDelta]));
        $this->assertDatabaseCount('habit_entries', 0);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_revoked_session_and_unsupported_storage_fail_closed(): void
    {
        $user = $this->owner();
        DB::table('session_revocations')->insert(['session_hash' => hash('sha256', request()->session()->getId()), 'user_id' => $user->id, 'revoked_at' => now()]);
        try {
            app(DeleteAccount::class)->handle(request(), $this->input($user));
            $this->fail('Revoked request admitted');
        } catch (ValidationException) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
        DB::table('session_revocations')->delete();
        config(['session.driver' => 'array']);
        $this->delete(route('settings.account.destroy'), $this->input($user))->assertStatus(503);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_stale_credentials_revoked_requests_and_late_session_writes_cannot_delete_or_restore_access(): void
    {
        $user = $this->owner();
        $stale = clone $user;
        $user->forceFill(['password' => Hash::make('new-password')])->save();
        request()->setUserResolver(fn () => $stale);
        try {
            app(DeleteAccount::class)->handle(request(), $this->input($stale));
            $this->fail('Stale deletion admitted');
        } catch (ValidationException) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
        $user->forceFill(['password' => Hash::make('password')])->save();
        Auth::setUser($user);
        request()->setUserResolver(fn () => $user);
        app(DeleteAccount::class)->handle(request(), $this->input($user));
        $handler = new RevocableDatabaseSessionHandler(DB::connection(), 'sessions', 120, app());
        $this->assertTrue($handler->write('late-deleted-session', '{}'));
        $this->assertDatabaseMissing('sessions', ['id' => 'late-deleted-session']);
        try {
            app(DeleteAccount::class)->handle(request(), $this->input($user));
            $this->fail('Repeated deletion admitted');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
    }
}
