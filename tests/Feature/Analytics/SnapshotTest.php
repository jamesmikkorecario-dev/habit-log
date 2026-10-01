<?php

namespace Tests\Feature\Analytics;

use App\Actions\Habits\SaveHabitEntry;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tracking\TrackingTestCase;

class SnapshotTest extends TrackingTestCase
{
    public function test_related_reads_keep_one_snapshot_during_concurrent_committed_edit(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $habit = $this->habit('checkpoint');
        $version = $habit->versions()->with('checkpoints')->first();
        $date = $habit->user->localToday();
        $entry = app(SaveHabitEntry::class)->handle($habit->user, $habit->id, $date, [
            'status' => 'partial', 'value' => null, 'note' => null, 'not_applicable_reason' => null, 'completed' => [$version->checkpoints->first()->id],
        ], null, $version->id, 1, 'UTC');
        config(['database.connections.analytics_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('analytics_writer');
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame('habit_log_test', $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        $changed = false;
        $active = true;
        DB::listen(function ($query) use ($writer, $entry, &$changed, &$active) {
            if ($active && ! $changed && $query->connectionName === 'mysql' && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `habit_entries`')) {
                $changed = true;
                $writer->transaction(function () use ($writer, $entry) {
                    $writer->table('habit_entries')->where('id', $entry->id)->update(['status' => 'completed', 'revision' => 2]);
                    $writer->table('habit_checkpoint_entries')->where('habit_entry_id', $entry->id)->update(['is_completed' => true]);
                });
            }
        });
        try {
            $report = app(ActivityAnalytics::class)->report($habit->user, (int) substr($date, 0, 4), $habit->id);
            $this->assertTrue($changed);
            $cell = collect($report['cells'])->firstWhere('date', $date);
            $this->assertSame(0.5, $cell['score']);
            $this->assertSame('Partial', $cell['assessment']);
            $fresh = app(ActivityAnalytics::class)->report($habit->user, (int) substr($date, 0, 4), $habit->id);
            $this->assertSame(1.0, collect($fresh['cells'])->firstWhere('date', $date)['score']);
        } finally {
            $active = false;
            DB::purge('analytics_writer');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
