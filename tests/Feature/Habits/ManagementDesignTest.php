<?php

namespace Tests\Feature\Habits;

use App\Models\Category;
use App\Models\Habit;
use App\Models\HabitCheckpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagementDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_is_owner_scoped_and_not_used_for_other_empty_views(): void
    {
        $user = User::factory()->create();
        Habit::factory()->create(['name' => 'Another owner']);
        $category = Category::factory()->system()->create();
        Livewire::actingAs($user)->test('pages::habits.index')
            ->assertSee('Your first habit starts here.')->assertDontSee('Another owner')
            ->set('tab', 'archived')->assertSee('No archived habits yet.')->assertDontSee('Create your first habit')
            ->set('tab', 'trash')->assertSee('Your trash is empty.')->assertDontSee('Create your first habit')
            ->set('category', (string) $category->id)->assertSee('No habits found in this category.')
            ->assertSee('Show all categories')->assertDontSee('Create your first habit');

        $habit = Habit::factory()->for($user)->create(['archived_at' => now()]);
        Livewire::actingAs($user)->test('pages::habits.index')
            ->assertSee('No active habits right now.')->assertDontSee('Your first habit starts here.');
        $habit->delete();
        Livewire::actingAs($user)->test('pages::habits.index')
            ->assertSee('No active habits right now.')->assertDontSee('Your first habit starts here.');
    }

    public function test_empty_pagination_can_recover_without_claiming_the_list_is_empty(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->system()->create();
        $habit = Habit::factory()->for($user)->for($category)->create(['name' => 'Existing habit']);
        Livewire::actingAs($user)->test('pages::habits.index')
            ->set('category', (string) $category->id)->call('setPage', 2)
            ->assertSee('No habits on this page.')->assertDontSee('No habits found in this category.')
            ->assertDontSee('Your first habit starts here.')->call('setPage', 1)->assertSee($habit->name);
    }

    public function test_rows_display_saved_setup_including_zero_limits_without_private_content(): void
    {
        $user = User::factory()->create();
        Habit::factory()->for($user)->create(['name' => 'Avoidance', 'type' => 'avoidance', 'goal_value' => 0, 'goal_unit' => 'times']);
        Habit::factory()->for($user)->create(['name' => 'Read', 'type' => 'count', 'goal_value' => 20, 'goal_unit' => 'pages']);
        Habit::factory()->for($user)->create(['name' => 'Move', 'type' => 'duration', 'goal_value' => 30, 'goal_unit' => 'minutes']);
        Habit::factory()->for($user)->create(['name' => 'Optional', 'type' => 'count']);
        $checklist = Habit::factory()->for($user)->create(['type' => 'checkpoint']);
        HabitCheckpoint::factory()->count(2)->for($checklist)->create(['label' => 'Private checkpoint label']);
        Livewire::actingAs($user)->test('pages::habits.index')
            ->assertSee('Saved tracking setup')->assertSee('0 times')->assertSee('20 pages')->assertSee('30 minutes')
            ->assertSee('No numeric goal')->assertSee('2 checkpoints')->assertDontSee('Private checkpoint label');
    }
}
