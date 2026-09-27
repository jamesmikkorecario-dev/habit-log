<?php

namespace Tests\Feature\Habits;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Models\Habit;
use App\Models\HabitCheckpoint;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_defaults_habits_to_private(): void
    {
        $habit = Habit::factory()->make();
        $id = DB::table('habits')->insertGetId($habit->only(['user_id', 'category_id', 'name', 'start_date']) + ['type' => 'boolean']);
        $this->assertDatabaseHas('habits', ['id' => $id, 'visibility' => 'private']);
    }

    #[DataProvider('invalidOwnerStates')]
    public function test_category_owner_constraints(bool $system, bool $owner): void
    {
        $userId = $owner ? User::factory()->create()->id : null;
        $this->expectException(QueryException::class);
        Category::factory()->create(['is_system' => $system, 'user_id' => $userId]);
    }

    public static function invalidOwnerStates(): array
    {
        return [[true, true], [false, false]];
    }

    public function test_custom_normalized_names_are_unique_per_owner(): void
    {
        $category = Category::factory()->create();
        $this->expectException(QueryException::class);
        Category::factory()->for($category->user)->create(['normalized_name' => $category->normalized_name]);
    }

    public function test_categories_with_trashed_habits_cannot_be_deleted(): void
    {
        $habit = Habit::factory()->create();
        $habit->delete();
        $this->expectException(QueryException::class);
        $habit->category->delete();
    }

    public function test_owned_habits_prevent_user_deletion(): void
    {
        $habit = Habit::factory()->create();
        $this->expectException(QueryException::class);
        $habit->user->delete();
    }

    public function test_foreign_keys_reject_orphan_references(): void
    {
        $this->expectException(QueryException::class);
        HabitCheckpoint::factory()->create(['habit_id' => 9999999]);
    }

    public function test_child_rows_cascade_on_physical_parent_deletion(): void
    {
        $alias = CategoryAlias::factory()->create();
        $alias->category->delete();
        $this->assertDatabaseMissing('category_aliases', ['id' => $alias->id]);
        $checkpoint = HabitCheckpoint::factory()->create();
        // Direct schema check only: policies disallow this operation in the application.
        DB::table('habits')->where('id', $checkpoint->habit_id)->delete();
        $this->assertDatabaseMissing('habit_checkpoints', ['id' => $checkpoint->id]);
    }
}
