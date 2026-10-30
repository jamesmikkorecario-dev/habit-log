<?php

namespace Tests\Feature\Journals;

use App\Actions\Habits\SaveHabitEntry;
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
        $paths = array_map(fn ($path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_10_04'));
        $journalPath = ['database/migrations/2026_10_04_000001_create_journals_table.php'];
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--path' => $paths]));
            $owner = User::factory()->make();
            $owner->id = DB::table('users')->insertGetId($owner->getAttributes());
            $owner = User::findOrFail($owner->id);
            $habit = $this->habit(user: $owner);
            $version = $habit->versions()->sole();
            app(SaveHabitEntry::class)->handle($habit->user, $habit->id, '2026-09-27', ['status' => 'completed', 'completed' => [], 'note' => 'Keep private history'], null, $version->id, 1, 'UTC');
            $tables = ['users', 'habits', 'habit_entries', 'habit_tracking_versions', 'habit_suspensions', 'categories'];
            $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $journalPath]));
            $this->assertDatabaseCount('journals', 0);
            Journal::factory()->for($habit->user)->create();
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1, '--path' => $journalPath]));
            $this->assertFalse(Schema::hasTable('journals'));
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
            }
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $journalPath]));
            $this->assertTrue(Schema::hasTable('journals'));
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
