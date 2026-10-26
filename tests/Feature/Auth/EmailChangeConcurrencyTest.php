<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\EmailChanges;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmailChangeConcurrencyTest extends TestCase
{
    public function test_user_locks_serialize_replacements_confirmation_password_and_recovery(): void
    {
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        try {
            foreach (['request', 'confirm', 'confirm-reset', 'confirm-repeat', 'confirm-cancel', 'reset', 'token'] as $operation) {
                $user = User::factory()->create();
                $this->actingAs($user);
                request()->setLaravelSession(session()->driver());
                [$pending, $url] = app(EmailChanges::class)->request($user, ['current_password' => 'password', 'new_email' => $operation.'@example.test', 'generation' => 'none']);
                parse_str(parse_url($url, PHP_URL_QUERY), $link);
                $token = Password::createToken($user);
                $worker = $this->worker($user, str_starts_with($operation, 'confirm-') ? 'confirm' : $operation, $operation === 'request' ? ['generation' => $pending->id] : ($operation === 'reset' ? ['token' => $token] : $link));
                try {
                    DB::beginTransaction();
                    User::whereKey($user->id)->lockForUpdate()->first();
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                    $this->assertTrue($worker->isRunning());
                    if ($operation === 'request') {
                        PendingEmailChange::whereKey($pending->id)->update(['last_sent_at' => now()->subMinutes(2)]);
                        app(EmailChanges::class)->request($user, ['current_password' => 'password', 'new_email' => 'winner@example.test', 'generation' => $pending->id]);
                    } elseif ($operation === 'confirm') {
                        app(UpdateUserPassword::class)->update($user, ['current_password' => 'password', 'password' => 'Winning-password-456!', 'password_confirmation' => 'Winning-password-456!']);
                    } elseif ($operation === 'confirm-reset') {
                        app(ResetUserPassword::class)->reset($user, ['token' => $token, 'password' => 'Winning-password-456!', 'password_confirmation' => 'Winning-password-456!']);
                    } elseif ($operation === 'confirm-cancel') {
                        app(EmailChanges::class)->cancel($user, $pending->id);
                    } else {
                        app(EmailChanges::class)->confirm($user, $link, ['current_password' => 'password'], 'parent');
                    }
                    DB::commit();
                    $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                    if ($operation === 'request') {
                        $this->assertSame('winner@example.test', PendingEmailChange::where('user_id', $user->id)->sole()->new_email);
                    } elseif (in_array($operation, ['confirm', 'confirm-reset'])) {
                        $this->assertTrue(Hash::check('Winning-password-456!', $user->fresh()->password));
                    } elseif ($operation === 'confirm-cancel') {
                        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);
                        $this->assertSame($pending->source_email, $user->fresh()->email);
                    } else {
                        $this->assertSame($operation.'@example.test', $user->fresh()->email);
                        $this->assertTrue(Hash::check('password', $user->fresh()->password));
                        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $pending->source_email]);
                    }
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

    public function test_two_owners_cannot_claim_the_same_address_even_with_concurrent_confirmation(): void
    {
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        try {
            $links = [];
            $users = User::factory()->count(2)->create();
            foreach ($users as $user) {
                $this->actingAs($user);
                [$pending, $url] = app(EmailChanges::class)->request($user, ['current_password' => 'password', 'new_email' => 'raced@example.test', 'generation' => 'none']);
                parse_str(parse_url($url, PHP_URL_QUERY), $link);
                $links[] = $link;
            }
            $worker = $this->worker($users[1], 'confirm', $links[1]);
            try {
                DB::beginTransaction();
                $this->actingAs($users[0]);
                app(EmailChanges::class)->confirm($users[0], $links[0], ['current_password' => 'password'], 'parent');
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                $this->assertTrue($worker->isRunning());
                DB::commit();
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertSame('raced@example.test', $users[0]->fresh()->email);
                $this->assertSame($users[1]->email, $users[1]->fresh()->email);
                $this->assertDatabaseHas('pending_email_changes', ['user_id' => $users[1]->id]);
            } finally {
                if (DB::transactionLevel()) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    private function worker(User $user, string $operation, array $data): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/email-write-worker.php'), (string) $user->id, $operation, json_encode($data)], base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'BCRYPT_ROUNDS' => '4',
            'EMAIL_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR),
        ]);
        $worker->setTimeout(12);

        return $worker;
    }
}
