<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\ManageSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionManagementConcurrencyTest extends TestCase
{
    public function test_revocation_serializes_late_original_regenerated_recalled_and_login_admissions(): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        try {
            foreach (['same-id', 'regenerated', 'remember', 'bulk', 'login'] as $operation) {
                DB::table('session_revocations')->delete();
                config(['session.driver' => 'array']);
                $user = User::factory()->create(['remember_token' => Str::random(60)]);
                $this->actingAs($user);
                request()->setLaravelSession(session()->driver());
                request()->setUserResolver(fn () => $user);
                session()->forget('revocation_origin');
                config(['session.driver' => 'database']);
                $key = (string) Str::uuid();
                DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $user->id, 'management_id' => $key,
                    'origin_hash' => hash('sha256', 'target-session'), 'payload' => base64_encode('{}'), 'last_activity' => time()]);
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/session-write-worker.php'), (string) $user->id, $operation], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'BCRYPT_ROUNDS' => '4', 'SESSION_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR),
                ]);
                $worker->setTimeout(12);
                try {
                    DB::beginTransaction();
                    User::whereKey($user->id)->lockForUpdate()->first();
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                    $this->assertTrue($worker->isRunning());
                    // Remember admission has a distinct origin; rotating the
                    // token alone must stop its first delayed session write.
                    app(ManageSessions::class)->revoke(request(), 'password', in_array($operation, ['bulk', 'login']) ? null : $key);
                    DB::commit();
                    $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                    $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
                    $this->assertDatabaseMissing('sessions', ['id' => 'new-stale-session']);
                } finally {
                    if (DB::transactionLevel()) {
                        DB::rollBack();
                    }
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
            }
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
