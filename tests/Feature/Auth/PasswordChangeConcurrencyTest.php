<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PasswordChangeConcurrencyTest extends TestCase
{
    public function test_waiting_password_changes_and_resets_cannot_overwrite_the_winner(): void
    {
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        try {
            foreach (['change', 'reset'] as $operation) {
                $user = User::factory()->create();
                $token = $operation === 'reset' ? Password::createToken($user) : '';
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/password-write-worker.php'), (string) $user->id, $operation, $token], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'BCRYPT_ROUNDS' => '4',
                    'PASSWORD_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR),
                ]);
                $worker->setTimeout(10);
                try {
                    DB::beginTransaction();
                    DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                    $this->assertTrue($worker->isRunning());
                    $this->actingAs($user);
                    request()->setLaravelSession(session()->driver());
                    app(UpdateUserPassword::class)->update($user, ['current_password' => 'password', 'password' => 'Winning-password-456!', 'password_confirmation' => 'Winning-password-456!']);
                    DB::commit();
                    $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                    $this->assertStringContainsString('stale write rejected', $worker->getOutput());
                    $this->assertTrue(Hash::check('Winning-password-456!', $user->fresh()->password));
                    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
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
