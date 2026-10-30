<?php

namespace Tests\Feature\Recovery;

use App\Models\User;
use App\Services\Recovery\BackupManager;
use App\Services\Recovery\DeletionLedger;
use App\Services\Recovery\RecoveryFailure;
use App\Services\Recovery\RecoveryManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Concerns\WithRecoveryStorage;
use Tests\Fixtures\AccountDeletionData;
use Tests\TestCase;

class RecoveryWorkflowTest extends TestCase
{
    use DatabaseMigrations, WithRecoveryStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRecoveryStorage();
        config(['session.driver' => 'database']);
        app(RecoveryManager::class)->initialize();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('mysql');
        DB::purge('recovery_test');
        $this->removeRecoveryStorage();
        parent::tearDown();
    }

    private function target(): void
    {
        config(['database.connections.recovery_test' => [...config('database.connections.mysql'), 'database' => 'habit_log_recovery_test'],
            'recovery.mode' => 'recovery', 'recovery.target_database' => 'habit_log_recovery_test']);
        DB::setDefaultConnection('recovery_test');
        // Guard immediately before destructive setup. Never accept env overrides.
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('habit_log_recovery_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_recovery_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => 'recovery_test', '--force' => true]));
    }

    public function test_pre_deletion_backup_restores_only_surviving_graph_and_requires_admission(): void
    {
        $deleted = User::factory()->create();
        $survivor = User::factory()->create();
        AccountDeletionData::populate($deleted);
        AccountDeletionData::populate($survivor);
        Password::createToken($deleted);
        Password::createToken($survivor);
        DB::table('follows')->insert(['follower_id' => $survivor->id, 'followed_id' => $deleted->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
        $this->actingAs($deleted)->get('/settings/security')->assertOk();
        $id = app(BackupManager::class)->create();
        $this->delete(route('settings.account.destroy'), ['account_id' => $deleted->id,
            'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertRedirect(route('account.deleted'));
        $this->assertSame('committed', app(DeletionLedger::class)->snapshot()['deletions'][$deleted->recovery_uuid]);
        $this->target();
        $this->get('/login')->assertStatus(503);
        app(RecoveryManager::class)->restore($id);
        $this->assertDatabaseMissing('users', ['recovery_uuid' => $deleted->recovery_uuid]);
        $this->assertDatabaseHas('users', ['recovery_uuid' => $survivor->recovery_uuid]);
        foreach (['categories', 'habits', 'habit_entries', 'journals'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $deleted->id]);
            $this->assertGreaterThan(0, DB::table($table)->where('user_id', $survivor->id)->count());
        }
        foreach (['sessions', 'password_reset_tokens', 'pending_email_changes', 'session_revocations', 'follows'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('recovery_state', ['status' => 'reconciled']);
        $this->get('/login')->assertStatus(503);
        config(['recovery.mode' => 'live']);
        $this->get('/login')->assertStatus(503);
        config(['recovery.mode' => 'recovery']);
        app(RecoveryManager::class)->admit();
        $this->get('/login')->assertStatus(503);
        config(['recovery.mode' => 'live']);
        $this->get('/login')->assertOk();
        DB::setDefaultConnection('mysql');
        $this->get('/login')->assertStatus(503); // Superseded source cannot serve.
    }

    public function test_retention_boundary_purges_only_registered_expired_artifacts(): void
    {
        $this->freezeTime();
        $manager = app(BackupManager::class);
        $id = $manager->create();
        $foreign = $this->recoveryRoot.'/backups/operator-owned.sql';
        file_put_contents($foreign, 'synthetic untouched');
        $this->travel(29 * 86400 - 1)->seconds();
        $this->assertSame(0, $manager->purge());
        $this->assertFileExists($manager->path($id));
        $this->travel(1)->seconds();
        $this->assertSame(1, $manager->purge());
        $this->assertFileDoesNotExist($manager->path($id));
        $this->assertSame('synthetic untouched', file_get_contents($foreign));
        $this->assertSame(0, $manager->purge());
    }

    public function test_pending_intent_blocks_backup_and_restore_and_resolves_only_on_original_database(): void
    {
        $user = User::factory()->create();
        $id = app(BackupManager::class)->create();
        app(DeletionLedger::class)->exclusive(fn ($ledger) => $ledger->deletion($user->recovery_uuid, 'pending'));
        try {
            app(BackupManager::class)->create();
            $this->fail('Uncertain deletion was ignored.');
        } catch (RecoveryFailure) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
        $this->target();
        try {
            app(RecoveryManager::class)->restore($id);
            $this->fail('Uncertain deletion was restored.');
        } catch (RecoveryFailure) {
            $this->assertDatabaseCount('users', 0);
        }
        try {
            app(RecoveryManager::class)->resolvePending();
            $this->fail('Old copy resolved a live commit.');
        } catch (RecoveryFailure) {
            DB::setDefaultConnection('mysql');
            config(['recovery.mode' => 'live']);
        }
        app(RecoveryManager::class)->resolvePending();
        $this->assertSame('cancelled', app(DeletionLedger::class)->snapshot()['deletions'][$user->recovery_uuid]);
    }

    public function test_missing_external_state_blocks_http_and_deletion_without_mutating_live_records(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/security')->assertOk();
        unlink($this->recoveryRoot.'/ledger/state.json');
        $this->get('/login')->assertStatus(503);
        $this->delete(route('settings.account.destroy'), ['account_id' => $user->id,
            'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertStatus(503);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_failed_snapshot_is_not_usable_and_outputs_no_private_diagnostics(): void
    {
        DB::statement('CREATE TABLE unsupported_backup_table (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            $this->artisan('recovery:manage', ['operation' => 'backup', '--confirm-writers-stopped' => true])->assertExitCode(1);
            $this->assertSame([], glob($this->recoveryRoot.'/backups/*'));
            $records = app(DeletionLedger::class)->snapshot()['backups'];
            $this->assertCount(1, $records);
            $this->assertSame('writing', array_values($records)[0]['status']);
        } finally {
            DB::statement('DROP TABLE unsupported_backup_table');
        }
    }

    public function test_restore_refuses_populated_target_missing_confirmation_and_unregistered_backup(): void
    {
        $id = app(BackupManager::class)->create();
        $this->target();
        $this->artisan('recovery:manage', ['operation' => 'restore', '--backup' => $id])->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        User::factory()->create();
        try {
            app(RecoveryManager::class)->restore($id);
            $this->fail('Populated target overwritten.');
        } catch (RecoveryFailure) {
            $this->assertDatabaseCount('users', 1);
        }
        $this->expectException(RecoveryFailure::class);
        app(RecoveryManager::class)->restore((string) Str::uuid());
    }

    public function test_failed_intent_capture_rolls_back_live_deletion(): void
    {
        $user = User::factory()->create();
        AccountDeletionData::populate($user);
        $this->actingAs($user)->get('/settings/security')->assertOk();
        $directory = $this->recoveryRoot.'/ledger';
        DB::listen(function ($query) use ($directory) {
            if (str_starts_with($query->sql, 'delete from `users`')) {
                chmod($directory, 0500); // Simulate loss of the required storage contract.
            }
        });
        try {
            $this->delete(route('settings.account.destroy'), ['account_id' => $user->id,
                'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertStatus(503);
        } finally {
            chmod($directory, 0700);
        }
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('journals', ['user_id' => $user->id]);
        $this->assertSame([], app(DeletionLedger::class)->snapshot()['deletions']);
    }

    public function test_failed_completion_keeps_durable_pending_intent_and_live_deletion_committed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/security')->assertOk();
        $directory = $this->recoveryRoot.'/ledger';
        DB::listen(function ($query) use ($directory) {
            if (str_starts_with($query->sql, 'delete from `users`')) {
                DB::afterCommit(fn () => chmod($directory, 0500));
            }
        });
        Log::spy();
        try {
            $this->delete(route('settings.account.destroy'), ['account_id' => $user->id,
                'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertRedirect(route('account.deleted'));
        } finally {
            chmod($directory, 0700);
        }
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame('pending', app(DeletionLedger::class)->snapshot()['deletions'][$user->recovery_uuid]);
        Log::shouldHaveReceived('error')->once()->with('Deletion completion record unavailable; recovery remains blocked.');
        app(RecoveryManager::class)->resolvePending();
        $this->assertSame('committed', app(DeletionLedger::class)->snapshot()['deletions'][$user->recovery_uuid]);
    }

    public function test_reused_numeric_id_does_not_suppress_a_different_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/security')->assertOk();
        $this->delete(route('settings.account.destroy'), ['account_id' => $user->id,
            'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertRedirect();
        $replacement = User::factory()->create(['id' => $user->id]);
        $this->assertNotSame($user->recovery_uuid, $replacement->recovery_uuid);
        $id = app(BackupManager::class)->create();
        $this->target();
        app(RecoveryManager::class)->restore($id);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'recovery_uuid' => $replacement->recovery_uuid]);
        $this->assertDatabaseMissing('users', ['recovery_uuid' => $user->recovery_uuid]);
    }

    public function test_purge_refuses_symlink_without_following_or_deleting_the_target(): void
    {
        $this->freezeTime();
        $manager = app(BackupManager::class);
        $id = $manager->create();
        $path = $manager->path($id);
        unlink($path); // Only this synthetic managed artifact.
        $sentinel = $this->recoveryRoot.'/sentinel';
        file_put_contents($sentinel, 'synthetic must survive');
        symlink($sentinel, $path);
        $this->travel(29)->days();
        try {
            $manager->purge();
            $this->fail('Symlink was accepted.');
        } catch (RecoveryFailure) {
            $this->assertTrue(is_link($path));
            $this->assertSame('synthetic must survive', file_get_contents($sentinel));
        }
    }

    public function test_admission_reconciles_deletions_committed_after_initial_restore(): void
    {
        $user = User::factory()->create();
        AccountDeletionData::populate($user);
        $id = app(BackupManager::class)->create();
        $this->target();
        app(RecoveryManager::class)->restore($id);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        DB::setDefaultConnection('mysql');
        config(['recovery.mode' => 'live']);
        $this->actingAs($user)->get('/settings/security')->assertOk();
        $this->delete(route('settings.account.destroy'), ['account_id' => $user->id,
            'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertRedirect();
        DB::setDefaultConnection('recovery_test');
        config(['recovery.mode' => 'recovery']);
        app(RecoveryManager::class)->admit();
        $this->assertDatabaseMissing('users', ['recovery_uuid' => $user->recovery_uuid]);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_interrupted_import_rolls_back_and_cannot_be_admitted_as_an_empty_success(): void
    {
        $user = User::factory()->create();
        AccountDeletionData::populate($user);
        $id = app(BackupManager::class)->create();
        $this->target();
        DB::listen(function ($query) {
            if ($query->connectionName === 'recovery_test' && str_starts_with($query->sql, 'insert into `journals`')) {
                throw new \RuntimeException('Synthetic import interruption.');
            }
        });
        $this->artisan('recovery:manage', ['operation' => 'restore', '--backup' => $id, '--confirm-isolated' => true])->assertExitCode(1);
        $this->assertDatabaseHas('recovery_state', ['status' => 'importing']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('habit_entries', 0);
        $this->artisan('recovery:manage', ['operation' => 'admit', '--confirm-isolated' => true, '--confirm-source-stopped' => true])->assertExitCode(1);
        config(['recovery.mode' => 'live']);
        $this->get('/login')->assertStatus(503);
    }

    public function test_recovery_identity_migration_preserves_existing_data_and_model_identity(): void
    {
        // Only the guarded synthetic DB; no ledger/backup is used after reverting.
        config(['recovery.mode' => 'off']);
        $owner = User::factory()->create();
        AccountDeletionData::populate($owner);
        $migration = require database_path('migrations/2026_10_30_000001_add_recovery_identity.php');
        $migration->down();
        $before = DB::table('users')->where('id', $owner->id)->first();
        $journal = DB::table('journals')->first();
        $migration->up();
        $after = User::findOrFail($owner->id);
        $this->assertTrue(Str::isUuid($after->recovery_uuid));
        foreach ((array) $before as $column => $value) {
            $this->assertSame($value, $after->getRawOriginal($column));
        }
        $this->assertEquals($journal, DB::table('journals')->first());
        $this->assertArrayNotHasKey('recovery_uuid', $after->toArray());
        $other = User::withoutEvents(fn () => User::factory()->create());
        $this->assertTrue(Str::isUuid($other->recovery_uuid));
        $this->assertNotSame($after->recovery_uuid, $other->recovery_uuid);
        $after->recovery_uuid = (string) Str::uuid();
        $this->expectException(\LogicException::class);
        $after->save();
    }

    public function test_uncertain_commit_reports_no_false_rollback_guarantee_and_blocks_recovery(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/security')->assertOk();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'delete from `users`')) {
                DB::afterCommit(fn () => throw new QueryException(
                    'mysql', 'synthetic private query canary', ['synthetic-binding-canary'],
                    new \RuntimeException('Synthetic lost commit acknowledgement.')
                ));
            }
        });
        Log::spy();
        $this->delete(route('settings.account.destroy'), ['account_id' => $user->id,
            'current_password' => 'password', 'acknowledge_deletion' => '1'])->assertSessionHasErrors('deletion', errorBag: 'accountDeletion');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame('pending', app(DeletionLedger::class)->snapshot()['deletions'][$user->recovery_uuid]);
        $message = session('errors')->getBag('accountDeletion')->first('deletion');
        $this->assertStringContainsString('could not be confirmed', $message);
        $this->assertStringNotContainsString('Nothing was removed', $message);
        $this->assertStringNotContainsString('canary', $message);
        Log::shouldHaveReceived('error')->once()->with('Account deletion database outcome requires review of restricted deletion state.');
        $this->expectException(RecoveryFailure::class);
        app(BackupManager::class)->create();
    }
}
