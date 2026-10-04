<?php

namespace Tests\Feature\Social;

use App\Actions\Follows\FollowUser;
use App\Actions\Follows\TransitionFollow;
use App\Actions\Profiles\SaveProfile;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        return [['duplicate'], ['opposite'], ['privacy'], ['approve_cancel'], ['approve_recreate'], ['reject_recreate'], ['unfollow_remove']];
    }

    #[DataProvider('races')]
    public function test_writers_use_current_state_after_ordered_user_locks(string $race): void
    {
        $this->prepareDatabase();
        [$a, $b] = User::factory()->count(2)->create(['is_private' => false])->all();
        $existing = null;
        $operation = 'follow';
        $workerActor = $a;
        $argument = $b->id;
        if (str_contains($race, 'approve') || str_contains($race, 'reject')) {
            $b->update(['is_private' => true]);
            $existing = app(FollowUser::class)->handle($a, $b->id);
            $workerActor = $b;
            $argument = $existing->id;
            $operation = str_starts_with($race, 'reject') ? 'reject' : 'approve';
        } elseif ($race === 'unfollow_remove') {
            $existing = app(FollowUser::class)->handle($a, $b->id);
            $argument = $existing->id;
            $operation = 'unfollow';
        }
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/social-write-worker.php'), (string) $workerActor->id, (string) $argument, $operation], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'SOCIAL_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR)]);
        $worker->setTimeout(15);
        try {
            DB::beginTransaction();
            User::whereIn('id', [$a->id, $b->id])->orderBy('id')->lockForUpdate()->get();
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
            $this->assertTrue($worker->isRunning());
            if ($race === 'duplicate') {
                app(FollowUser::class)->handle($a, $b->id);
            } elseif ($race === 'opposite') {
                app(FollowUser::class)->handle($b, $a->id);
            } elseif ($race === 'privacy') {
                app(SaveProfile::class)->handle($b, ['is_private' => true]);
            } elseif ($race === 'unfollow_remove') {
                app(TransitionFollow::class)->handle($b, $existing->id, 'remove');
            } else {
                app(TransitionFollow::class)->handle($a, $existing->id, 'cancel');
                if (str_contains($race, 'recreate')) {
                    app(FollowUser::class)->handle($a, $b->id);
                }
            }
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $expected = in_array($race, ['duplicate', 'opposite']) ? 'accepted' : ($race === 'privacy' ? 'pending' : 'conflict');
            $this->assertStringContainsString($expected, $worker->getOutput());
            $this->assertDatabaseCount('follows', match ($race) {
                'opposite' => 2, 'approve_cancel', 'unfollow_remove' => 0, default => 1
            });
            if (str_contains($race, 'recreate')) {
                $this->assertSame('pending', Follow::sole()->status->value);
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

    public function test_absent_pair_creation_waits_for_user_lock(): void
    {
        $this->prepareDatabase();
        [$a, $b] = User::factory()->count(2)->create()->all();
        config(['database.connections.social_lock' => config('database.connections.mysql')]);
        $first = DB::connection('mysql');
        $second = DB::connection('social_lock');
        $this->assertSame('habit_log_test', $second->selectOne('SELECT DATABASE() AS db')->db);
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $first->beginTransaction();
            $first->table('users')->where('id', $b->id)->lockForUpdate()->first();
            DB::setDefaultConnection('social_lock');
            $start = microtime(true);
            try {
                app(FollowUser::class)->handle($a, $b->id);
                $this->fail('Must wait for lock.');
            } catch (QueryException $e) {
                $this->assertSame(1205, $e->errorInfo[1]);
                $this->assertGreaterThanOrEqual(0.8, microtime(true) - $start);
            }
            $this->assertSame(0, $second->table('follows')->count());
        } finally {
            if ($first->transactionLevel()) {
                $first->rollBack();
            }
            DB::setDefaultConnection('mysql');
            DB::purge('social_lock');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
