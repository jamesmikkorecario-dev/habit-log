<?php

namespace Tests\Feature\Journals;

use App\Actions\Journals\DeleteJournal;
use App\Actions\Journals\SaveJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    private function prepareDatabase(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    public static function races(): array
    {
        return [['create', 'save'], ['edit', 'save'], ['edit', 'delete'], ['delete', 'save'], ['recreate', 'save'], ['timezone', 'save']];
    }

    #[DataProvider('races')]
    public function test_competing_writer_reads_committed_state_and_rejects_stale_editor(string $change, string $operation): void
    {
        $this->prepareDatabase();
        $user = User::factory()->create();
        $save = fn ($id, $revision) => app(SaveJournal::class)->handle($user, '2026-10-01', ['content' => 'Committed journal'], $id, $revision, 'UTC');
        $entry = $change === 'create' ? null : $save(null, null);
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/journal-write-worker.php'), (string) $user->id, (string) ($entry?->id ?? '-'), $operation], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'JOURNAL_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR)]);
        $worker->setTimeout(15);
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
            $this->assertTrue($worker->isRunning());
            if (in_array($change, ['delete', 'recreate'])) {
                app(DeleteJournal::class)->handle($user, '2026-10-01', $entry->id, 1, 'UTC');
                if ($change === 'recreate') {
                    $save(null, null);
                }
            } elseif ($change === 'timezone') {
                $user->update(['timezone' => 'Asia/Manila']);
            } else {
                $save($entry?->id, $entry?->revision);
            }
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertStringContainsString('conflict', $worker->getOutput());
            $this->assertSame($change === 'delete' ? 0 : 1, $user->journals()->count());
            if ($change !== 'delete') {
                $this->assertSame('Committed journal', $user->journals()->sole()->content);
            }
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

    public function test_owner_lock_serializes_writes_even_before_first_journal_exists(): void
    {
        $this->prepareDatabase();
        config(['database.connections.journal_race' => config('database.connections.mysql')]);
        $first = DB::connection('mysql');
        $second = DB::connection('journal_race');
        $this->assertSame('habit_log_test', $second->selectOne('SELECT DATABASE() AS db')->db);
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $user = User::factory()->create();
            $otherUser = User::on('journal_race')->findOrFail($user->id);
            $first->beginTransaction();
            $first->table('users')->where('id', $user->id)->lockForUpdate()->first();
            DB::setDefaultConnection('journal_race');
            $started = microtime(true);
            try {
                app(SaveJournal::class)->handle($otherUser, '2026-10-01', ['content' => 'Waiting'], null, null, 'UTC');
                $this->fail('Writer must wait behind owner lock.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('form.content', $e->errors());
                $this->assertGreaterThanOrEqual(0.8, microtime(true) - $started);
            }
            $this->assertSame(0, $second->table('journals')->count());
        } finally {
            if ($first->transactionLevel()) {
                $first->rollBack();
            }
            DB::setDefaultConnection('mysql');
            DB::purge('journal_race');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
