<?php

namespace Tests\Feature\Social;

use App\Actions\Habits\SaveHabitEntry;
use App\Models\Follow;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Tracking\TrackingTestCase;

class MigrationTest extends TrackingTestCase
{
    public function test_populated_upgrade_and_targeted_rollback_preserve_existing_data(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $paths = array_map(fn ($path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_10_04_000002'));
        $followPath = ['database/migrations/2026_10_04_000002_create_follows_table.php'];
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--path' => $paths]));
            $habit = $this->habit();
            $version = $habit->versions()->sole();
            app(SaveHabitEntry::class)->handle($habit->user, $habit->id, '2026-09-27', ['status' => 'completed', 'completed' => [], 'note' => 'Keep private history'], null, $version->id, 1, 'UTC');
            Journal::factory()->for($habit->user)->create();
            $tables = ['users', 'habits', 'habit_entries', 'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_checkpoint_entries', 'habit_checkpoints', 'habit_suspensions', 'categories', 'journals'];
            $other = User::factory()->create();
            $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $followPath]));
            $this->assertDatabaseCount('follows', 0);
            Follow::factory()->create(['follower_id' => $other->id, 'followed_id' => $habit->user_id]);
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1, '--path' => $followPath]));
            $this->assertFalse(Schema::hasTable('follows'));
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
            }
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $followPath]));
            $this->assertTrue(Schema::hasTable('follows'));
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
