<?php

namespace Tests\Feature\Tracking;

use App\Models\Habit;
use App\Models\HabitCheckpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_populated_upgrade_repeat_backfill_rollback_and_reapply_preserve_phase_two(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $paths = array_map(fn ($path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_09_27_000005'));
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--path' => $paths]));
            // Query builder avoids new application defaults when creating the pre-upgrade fixture.
            $userId = DB::table('users')->insertGetId(['name' => 'Before upgrade', 'email' => 'upgrade@example.com', 'password' => 'unused', 'bio' => 'Keep profile']);
            $habit = Habit::factory()->create(['user_id' => $userId, 'type' => 'checkpoint', 'start_date' => '2024-01-01', 'archived_at' => '2026-09-20 23:00:00', 'deleted_at' => '2026-09-22 10:00:00']);
            $checkpoint = HabitCheckpoint::factory()->create(['habit_id' => $habit->id, 'label' => 'Original label']);
            $before = $habit->fresh()->getAttributes();
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertSame('UTC', User::findOrFail($userId)->timezone);
            $this->assertSame($before, Habit::withTrashed()->findOrFail($habit->id)->getAttributes());
            $this->assertSame('Original label', DB::table('habit_tracking_checkpoints')->sole()->label);
            $this->assertSame('2026-09-21', DB::table('habit_suspensions')->where('reason', 'archive')->sole()->start_date);
            $this->assertSame('2026-09-23', DB::table('habit_suspensions')->where('reason', 'trash')->sole()->start_date);
            $backfill = require database_path('migrations/2026_09_27_000006_backfill_tracking_history.php');
            $backfill->up();
            $this->assertDatabaseCount('habit_tracking_versions', 1);
            $this->assertDatabaseCount('habit_tracking_checkpoints', 1);
            $this->assertDatabaseCount('habit_suspensions', 2);
            $this->assertDatabaseCount('habit_entries', 0);
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]));
            $this->assertFalse(Schema::hasTable('habit_entries'));
            $this->assertFalse(Schema::hasColumn('users', 'timezone'));
            $this->assertSame('Keep profile', DB::table('users')->where('id', $userId)->value('bio'));
            $this->assertSame('Original label', $checkpoint->fresh()->label);
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertDatabaseCount('habit_tracking_versions', 1);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_interrupted_backfill_rolls_back_current_habit_and_resumes_without_duplicates(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        try {
            $user = User::factory()->create();
            $first = Habit::factory()->for($user)->create(['type' => 'checkpoint']);
            $second = Habit::factory()->for($user)->create(['type' => 'checkpoint']);
            HabitCheckpoint::factory()->for($first)->create(['label' => 'First checkpoint']);
            HabitCheckpoint::factory()->for($second)->create(['label' => 'Interrupt here']);
            $backfill = require database_path('migrations/2026_09_27_000006_backfill_tracking_history.php');
            $failOnce = true;
            DB::listen(function ($query) use (&$failOnce) {
                if ($failOnce && str_starts_with($query->sql, 'insert into `habit_tracking_checkpoints`') && in_array('Interrupt here', $query->bindings, true)) {
                    $failOnce = false;
                    throw new \RuntimeException('Interrupted backfill');
                }
            });
            try {
                $backfill->up();
                $this->fail('Expected interrupted backfill.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Interrupted backfill', $exception->getMessage());
            }
            $this->assertDatabaseCount('habit_tracking_versions', 1);
            $this->assertDatabaseCount('habit_tracking_checkpoints', 1);
            $firstVersionId = $first->versions()->sole()->id;
            $backfill->up();
            $this->assertDatabaseCount('habit_tracking_versions', 2);
            $this->assertDatabaseCount('habit_tracking_checkpoints', 2);
            $this->assertSame($firstVersionId, $first->versions()->sole()->id);
            $this->assertSame('Interrupt here', $second->versions()->sole()->checkpoints()->sole()->label);
            $this->assertDatabaseCount('habit_entries', 0);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
