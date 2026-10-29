<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\DeleteAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AccountDeletionData;
use Tests\TestCase;

class AccountDeletionConcurrencyTest extends TestCase
{
    public function test_deletion_serializes_writers_recovery_login_session_persistence_and_duplicate_deletion(): void
    {
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        try {
            foreach (['habit', 'entry', 'journal', 'category', 'follow', 'profile', 'reset', 'recovery', 'session', 'login', 'delete'] as $operation) {
                config(['session.driver' => 'array']);
                $user = User::factory()->create();
                $foreign = User::factory()->create();
                AccountDeletionData::populate($user);
                $token = Password::createToken($user);
                $this->actingAs($user);
                request()->setLaravelSession(session()->driver());
                request()->setUserResolver(fn () => $user);
                config(['session.driver' => 'database']);
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/account-deletion-worker.php'), (string) $user->id, $operation, (string) $foreign->id], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'BCRYPT_ROUNDS' => '4', 'DELETION_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR), 'DELETION_TEST_TOKEN' => $token,
                ]);
                $worker->setTimeout(12);
                try {
                    DB::beginTransaction();
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $operation);
                    $this->assertTrue($worker->isRunning(), $operation);
                    app(DeleteAccount::class)->handle(request(), ['account_id' => $user->id, 'current_password' => 'password', 'acknowledge_deletion' => '1']);
                    DB::commit();
                    $this->assertSame(0, $worker->wait(), $operation.' '.$worker->getErrorOutput());
                    foreach (['users' => 'id', 'habits' => 'user_id', 'journals' => 'user_id', 'categories' => 'user_id', 'sessions' => 'user_id'] as $table => $column) {
                        $this->assertDatabaseMissing($table, [$column => $user->id]);
                    }
                    $this->assertDatabaseMissing('sessions', ['id' => 'late-deleted-session']);
                    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
                    $this->assertDatabaseHas('users', ['id' => $foreign->id]);
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
