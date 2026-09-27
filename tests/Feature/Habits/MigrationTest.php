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
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
            $user = User::factory()->create(['bio' => 'Keep this profile']);
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]));
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
