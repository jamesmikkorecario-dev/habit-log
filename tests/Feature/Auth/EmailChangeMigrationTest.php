<?php

namespace Tests\Feature\Auth;

use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Tracking\TrackingTestCase;

class EmailChangeMigrationTest extends TrackingTestCase
{
    public function test_additive_migration_constraints_and_rollback_reapply_preserve_activity(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $path = 'database/migrations/2026_10_26_000001_create_pending_email_changes_table.php';
        $previous = array_values(array_filter(array_map(fn ($p) => str_replace(base_path().'/', '', $p), glob(database_path('migrations/*.php'))), fn ($p) => $p !== $path));
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--path' => $previous]));
            $habit = $this->habit();
            $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
            $before = [];
            foreach (['users', 'habits', 'habit_entries', 'habit_tracking_versions', 'categories'] as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => [$path]]));
            $owner = User::factory()->create();
            $row = ['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'new_email' => 'test@example.test', 'source_email' => $owner->email, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addHour(), 'last_sent_at' => now()];
            PendingEmailChange::create($row);
            foreach ([['user_id' => 999999], ['id' => (string) Str::uuid()]] as $invalid) {
                try {
                    PendingEmailChange::create(array_replace($row, $invalid));
                    $this->fail('Invalid owner or duplicate pending request accepted');
                } catch (QueryException $e) {
                    $this->assertSame('23000', $e->getCode());
                }
            }
            $this->assertTrue(Schema::hasIndex('pending_email_changes', ['expires_at']));
            $owner->delete();
            $this->assertDatabaseCount('pending_email_changes', 0);
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1, '--path' => [$path]]));
            $this->assertFalse(Schema::hasTable('pending_email_changes'));
            foreach ($before as $table => $data) {
                $this->assertSame($data, DB::table($table)->get()->toJson());
            }
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => [$path]]));
            $this->assertTrue(Schema::hasTable('pending_email_changes'));
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
