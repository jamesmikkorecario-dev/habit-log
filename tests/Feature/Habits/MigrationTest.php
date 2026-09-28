<?php

namespace Tests\Feature\Habits;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_phase_two_migrations_rollback_and_reapply_without_removing_profiles(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $paths = array_map(fn ($path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path), array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_09_27_000005'));
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--path' => $paths]));
            $user = User::factory()->make(['bio' => 'Keep this profile']);
            unset($user->timezone);
            $user->save();
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true, '--path' => $paths]));
            foreach (['categories', 'category_aliases', 'habits', 'habit_checkpoints'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertSame('Keep this profile', $user->fresh()->bio);
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            foreach (['categories', 'category_aliases', 'habits', 'habit_checkpoints'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
            $this->assertSame('Keep this profile', $user->fresh()->bio);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
