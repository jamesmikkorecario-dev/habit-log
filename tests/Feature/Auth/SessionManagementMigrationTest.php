<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Tracking\TrackingTestCase;

class SessionManagementMigrationTest extends TrackingTestCase
{
    public function test_populated_upgrade_and_rollback_preserve_payloads_credentials_and_activity(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $path = 'database/migrations/2026_10_27_000001_add_session_revocation_state.php';
        $previous = array_values(array_filter(array_map(fn ($p) => str_replace(base_path().'/', '', $p), glob(database_path('migrations/*.php'))), fn ($p) => $p !== $path));
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--path' => $previous]));
            $habit = $this->habit();
            $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
            DB::table('sessions')->insert(['id' => 'legacy-preserved', 'user_id' => $habit->user_id, 'payload' => 'synthetic-payload-never-decoded', 'last_activity' => time()]);
            $before = [];
            foreach (['users', 'habits', 'habit_entries', 'habit_tracking_versions', 'categories', 'sessions'] as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => [$path]]));
            $row = DB::table('sessions')->where('id', 'legacy-preserved')->first();
            $this->assertTrue(Str::isUuid($row->management_id));
            $this->assertSame(hash('sha256', 'legacy-preserved'), $row->origin_hash);
            $this->assertSame('synthetic-payload-never-decoded', $row->payload);
            $this->assertTrue(Schema::hasIndex('sessions', ['management_id'], 'unique'));
            $this->assertTrue(Schema::hasTable('session_revocations'));
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1, '--path' => [$path]]));
            foreach ($before as $table => $data) {
                $this->assertSame($data, DB::table($table)->get()->toJson(), $table);
            }
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => [$path]]));
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
