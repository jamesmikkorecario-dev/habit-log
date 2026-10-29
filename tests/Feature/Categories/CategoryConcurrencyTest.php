<?php

namespace Tests\Feature\Categories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AccountDeletionData;
use Tests\TestCase;

class CategoryConcurrencyTest extends TestCase
{
    public function test_concurrent_duplicate_creation_returns_a_field_error(): void
    {
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $user = User::factory()->create();
        AccountDeletionData::populate($user);
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/account-deletion-worker.php'), (string) $user->id, 'category-duplicate', (string) $user->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'BCRYPT_ROUNDS' => '4', 'DELETION_TEST_DB' => json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR),
        ]);
        $worker->setTimeout(12);
        try {
            // The competing connection commits after the UI's duplicate precheck,
            // while this request holds its shared user lock, before its INSERT.
            Category::creating(function () use ($worker) {
                $this->assertSame(0, $worker->run(), $worker->getErrorOutput());
            });
            Livewire::actingAs($user)->test('pages::categories.index')->set('name', 'Same choice')->call('review')->call('save')->assertHasErrors('name');
            $this->assertSame(1, $user->categories()->where('normalized_name', 'same choice')->count());
        } finally {
            Category::flushEventListeners();
            if ($worker->isRunning()) {
                $worker->stop();
            }
            RefreshDatabaseState::$migrated = false;
        }
    }
}
