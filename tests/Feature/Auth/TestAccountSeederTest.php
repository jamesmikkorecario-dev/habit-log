<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_seeder_creates_a_private_test_account_that_can_log_in(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'test@example.com')->sole();

        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertTrue($user->is_private);
        $this->assertSame('terminal-green', $user->theme_key);
        $this->assertDatabaseCount('categories', 10);

        $this->post(route('login.store'), [
            'email' => 'test@example.com',
            'password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_reseeding_does_not_duplicate_or_overwrite_an_existing_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'test@example.com')->sole();
        $user->update(['name' => 'Changed name', 'bio' => 'My profile', 'password' => 'changed-test-password']);
        $before = $user->refresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('categories', 10);
        $this->assertSame($before, $user->refresh()->getAttributes());
    }

    public function test_test_account_is_not_seeded_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->artisan('db:seed', ['--class' => TestAccountSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
