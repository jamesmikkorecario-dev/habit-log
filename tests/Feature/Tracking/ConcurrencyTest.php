<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ConcurrencyTest extends TrackingTestCase
{
    public function test_independent_connection_waits_for_parent_lock_and_rejects_stale_first_save(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        config(['database.connections.tracking_race' => config('database.connections.mysql')]);
        $first = DB::connection('mysql');
        $second = DB::connection('tracking_race');
        $this->assertNotSame($first->selectOne('SELECT CONNECTION_ID() AS id')->id, $second->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $this->assertSame('habit_log_test', $second->selectOne('SELECT DATABASE() AS db')->db);
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $habit = $this->habit();
            $version = $habit->versions()->first();
            $otherConnectionUser = User::on('tracking_race')->findOrFail($habit->user_id);
            $data = ['status' => 'completed', 'value' => null, 'note' => null, 'not_applicable_reason' => null, 'completed' => []];
            $save = fn ($user, $revision) => app(SaveHabitEntry::class)->handle($user, $habit->id, '2026-09-27', $data, $revision, $version->id, $version->revision, 'UTC');
            $first->beginTransaction();
            $first->table('habits')->where('id', $habit->id)->lockForUpdate()->first();
            DB::setDefaultConnection('tracking_race');
            foreach ([fn () => $save($otherConnectionUser, null), fn () => app(TransitionHabit::class)->handle($otherConnectionUser, $habit->id, 'archive')] as $blocked) {
                try {
                    $blocked();
                    $this->fail('Concurrent writer must wait for the parent lock.');
                } catch (QueryException $e) {
                    $this->assertSame(1205, $e->errorInfo[1]);
                }
            }
            DB::setDefaultConnection('mysql');
            $save($habit->user, null);
            $first->commit();
            DB::setDefaultConnection('tracking_race');
            try {
                $save($otherConnectionUser, null);
                $this->fail('Stale create must conflict.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('conflict', $e->errors());
            }
            $this->assertSame(2, $save($otherConnectionUser, 1)->revision);
            $this->assertSame(1, $second->table('habit_entries')->count());
        } finally {
            if ($first->transactionLevel()) {
                $first->rollBack();
            }
            DB::setDefaultConnection('mysql');
            DB::purge('tracking_race');
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_waiting_writer_sees_committed_revision_after_lock_is_released(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $habit = $this->habit();
        $version = $habit->versions()->first();
        $data = ['status' => 'completed', 'value' => null, 'note' => null, 'not_applicable_reason' => null, 'completed' => []];
        $save = fn ($revision) => app(SaveHabitEntry::class)->handle($habit->user, $habit->id, '2026-09-27', $data, $revision, $version->id, 1, 'UTC');
        $save(null);
        $worker = new Process([
            PHP_BINARY, base_path('tests/Fixtures/tracking-save-worker.php'), (string) $habit->user_id, (string) $habit->id, (string) $version->id, '1',
        ], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'TRACKING_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR)]);
        $worker->setTimeout(10);
        try {
            DB::beginTransaction();
            DB::table('habits')->where('id', $habit->id)->lockForUpdate()->first();
            $worker->start();
            $ready = $worker->waitUntil(fn ($type, $output) => str_contains($output, 'waiting'));
            $this->assertTrue($ready);
            $this->assertTrue($worker->isRunning());
            $save(1);
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertStringContainsString('conflict', $worker->getOutput());
            $this->assertSame(2, $habit->entries()->sole()->revision);
            $this->assertSame('completed', $habit->entries()->sole()->status->value);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            RefreshDatabaseState::$migrated = false;
        }
    }
}
