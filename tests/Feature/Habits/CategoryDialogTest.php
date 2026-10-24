<?php

namespace Tests\Feature\Habits;

use App\Models\Category;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_creation_reuses_validation_review_and_duplicate_protection_without_redirecting(): void
    {
        $user = User::factory()->create();
        $dialog = Livewire::actingAs($user)->test('pages::categories.index', ['inline' => true]);
        $dialog->set('name', '---')->call('review')->assertHasErrors('name');
        $dialog->set('name', ' My routine ')->set('description', ' 0 ')->call('save')->assertHasNoErrors()->assertNoRedirect();
        $this->assertSame(0, $user->categories()->count());
        $dialog->call('save')->assertHasNoErrors()->assertNoRedirect();
        $category = $user->categories()->sole();
        $dialog->assertDispatched('habit-category-selected', categoryId: $category->id);
        $this->assertSame('My routine', $category->name);
        $this->assertSame('0', $category->description);
        $this->assertFalse($category->is_system);
        $dialog->call('save')->assertHasErrors('name');
        $this->assertSame(1, $user->categories()->count());
    }

    public function test_inline_suggestions_and_selection_exclude_other_owners(): void
    {
        $user = User::factory()->create();
        $own = Category::factory()->for($user)->create(['name' => 'Reading routine']);
        $foreign = Category::factory()->create(['name' => 'Reading secret']);
        $before = $foreign->refresh()->getAttributes();
        Livewire::actingAs($user)->test('pages::categories.index', ['inline' => true])
            ->set('name', 'Reading')->call('review')->assertSee($own->name)->assertDontSee($foreign->name)
            ->call('useCategory', $own->id)->assertNoRedirect()->assertDispatched('habit-category-selected', categoryId: $own->id);
        Livewire::actingAs($user)->test('pages::categories.index', ['inline' => true])
            ->call('useCategory', $foreign->id)->assertNotFound()->assertNotDispatched('habit-category-selected');
        $this->assertSame($before, $foreign->refresh()->getAttributes());
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('habits', 0);
    }

    public function test_category_selection_preserves_create_and_edit_drafts_and_does_not_save_the_habit(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $habit = Habit::factory()->for($user)->create(['name' => 'Saved name']);
        foreach ([[], ['habit' => $habit->id]] as $parameters) {
            Livewire::actingAs($user)->test('pages::habits.edit', $parameters)
                ->set('form.name', 'Unfinished name')->set('form.description', 'Unfinished description')
                ->set('form.type', 'count')->set('form.goal_value', 8)->set('form.goal_unit', 'glasses')
                ->set('form.start_date', '2026-01-01')->set('form.end_date', '2026-12-31')->set('form.visibility', 'followers')
                ->set('showCategoryModal', true)->dispatch('habit-category-selected', categoryId: $category->id)
                ->assertSet('showCategoryModal', false)->assertSet('form.category_id', $category->id)
                ->assertSet('form.name', 'Unfinished name')->assertSet('form.description', 'Unfinished description')
                ->assertSet('form.type', 'count')->assertSet('form.goal_value', 8)->assertSet('form.goal_unit', 'glasses')
                ->assertSet('form.start_date', '2026-01-01')->assertSet('form.end_date', '2026-12-31')->assertSet('form.visibility', 'followers')
                ->assertDispatched('habit-category-applied')->assertNoRedirect();
        }
        $this->assertSame('Saved name', $habit->fresh()->name);
        $this->assertSame(1, $user->habits()->count());
    }

    public function test_parent_reauthorizes_category_events_instead_of_trusting_browser_identifiers(): void
    {
        $user = User::factory()->create();
        $foreign = Category::factory()->create();
        $before = $foreign->refresh()->getAttributes();
        Livewire::actingAs($user)->test('pages::habits.edit')
            ->dispatch('habit-category-selected', categoryId: $foreign->id)->assertNotFound()->assertNotDispatched('habit-category-applied');
        $this->assertSame($before, $foreign->refresh()->getAttributes());
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('habits', 0);
    }
}
