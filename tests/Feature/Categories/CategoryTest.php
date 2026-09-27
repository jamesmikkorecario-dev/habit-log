<?php

namespace Tests\Feature\Categories;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Models\User;
use App\Services\CategoryRecommender;
use Database\Seeders\CategorySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeds_repeatably_without_creating_users_or_changing_custom_categories(): void
    {
        $category = Category::factory()->create(['name' => 'My category']);
        $before = $category->refresh()->getAttributes();
        $this->seed(CategorySeeder::class);
        $ids = Category::where('is_system', true)->pluck('id')->all();
        $aliases = CategoryAlias::count();
        $this->seed(CategorySeeder::class);
        $this->assertCount(10, $ids);
        $this->assertSame($ids, Category::where('is_system', true)->pluck('id')->all());
        $this->assertSame($aliases, CategoryAlias::count());
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $category->refresh()->getAttributes());
    }

    public function test_guest_is_redirected_and_users_only_see_available_categories(): void
    {
        $this->get(route('categories.index'))->assertRedirect(route('login'));
        $owner = User::factory()->create();
        $own = Category::factory()->for($owner)->create(['name' => 'My choice']);
        $system = Category::factory()->system()->create(['name' => 'Shared choice']);
        $other = Category::factory()->create(['name' => 'Secret choice']);
        $this->actingAs($owner)->get(route('categories.index'))->assertOk()->assertSee($own->name)->assertSee($system->name)->assertDontSee($other->name);
        $this->assertFalse(Gate::forUser($owner)->allows('view', $other));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $system));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $own));
    }

    public function test_recommendations_precede_creation_and_system_match_does_not_force_adoption(): void
    {
        $this->seed(CategorySeeder::class);
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test('pages::categories.index')->set('name', '  Nutrition  ')->set('description', ' 0 ')
            ->call('save')->assertSet('reviewedName', 'Nutrition')->assertSee('Suggestions for');
        $this->assertDatabaseCount('categories', 10);
        $component->call('save')->assertHasNoErrors();
        $category = $user->categories()->sole();
        $this->assertSame('Nutrition', $category->name);
        $this->assertSame('0', $category->description);
        $this->assertFalse($category->is_system);
        $component->assertRedirect(route('habits.create', ['category' => $category->id]));
    }

    public function test_changed_name_requires_a_new_review(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test('pages::categories.index')->set('name', 'First')->call('review')
            ->set('name', 'Second')->call('save')->assertSet('reviewedName', 'Second');
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_duplicate_names_are_scoped_to_owner_and_rejected_without_writes(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'My choice']);
        $component = Livewire::actingAs($user)->test('pages::categories.index')->set('name', 'My choice')->call('review')->call('save')->assertHasNoErrors();
        $this->assertSame(1, $user->categories()->count());
        Livewire::actingAs($user)->test('pages::categories.index')->set('name', 'MY--CHOICE')->call('review')->call('save')->assertHasErrors('name');
        $this->assertDatabaseCount('categories', 2);
    }

    public function test_an_existing_category_can_be_selected_but_foreign_identifiers_are_denied(): void
    {
        $user = User::factory()->create();
        $system = Category::factory()->system()->create();
        $other = Category::factory()->create();
        Livewire::actingAs($user)->test('pages::categories.index')->call('useCategory', $system->id)->assertRedirect(route('habits.create', ['category' => $system->id]));
        $this->actingAs($user)->get(route('habits.create', ['category' => $other->id]))->assertNotFound();
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($user)->test('pages::categories.index')->call('useCategory', $other->id);
    }

    public function test_recommender_excludes_other_users_names_and_aliases(): void
    {
        $user = User::factory()->create();
        $own = Category::factory()->for($user)->create(['name' => 'Private reading']);
        $other = Category::factory()->create(['name' => 'Reading']);
        CategoryAlias::factory()->for($other)->create(['alias' => 'books']);
        $service = app(CategoryRecommender::class);
        $this->assertSame([$own->id], $service->recommend($user, 'reading')->pluck('id')->all());
        $this->assertCount(0, $service->recommend($user, 'books'));
    }

    #[DataProvider('invalidCategories')]
    public function test_category_validation(mixed $name, mixed $description, string $field): void
    {
        Livewire::actingAs(User::factory()->create())->test('pages::categories.index')->set('name', $name)->set('description', $description)
            ->call('review')->assertHasErrors($field);
        $this->assertDatabaseCount('categories', 0);
    }

    public static function invalidCategories(): array
    {
        return [['', '', 'name'], ['   ', '', 'name'], ['---', '', 'name'], [str_repeat('a', 61), '', 'name'], ['Valid', str_repeat('a', 501), 'description']];
    }

    public function test_unicode_zero_text_and_escaped_output(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test('pages::categories.index')->set('name', '  読書  ')->set('description', '  ')->call('review')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('categories', ['user_id' => $user->id, 'normalized_name' => '読書', 'description' => null]);
        Livewire::actingAs($user)->test('pages::categories.index')->set('name', '0')->call('review')->call('save')->assertHasNoErrors();
        Category::factory()->for($user)->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get(route('categories.index'))->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_concurrent_duplicate_creation_returns_a_field_error(): void
    {
        $user = User::factory()->create();
        // Another submission wins after the application precheck but before INSERT.
        Category::creating(function (Category $category) {
            DB::table('categories')->insert($category->getAttributes() + ['created_at' => now(), 'updated_at' => now()]);
        });
        try {
            Livewire::actingAs($user)->test('pages::categories.index')->set('name', 'Same choice')->call('review')->call('save')->assertHasErrors('name');
        } finally {
            Category::flushEventListeners();
        }
        $this->assertSame(1, $user->categories()->count());
    }
}
