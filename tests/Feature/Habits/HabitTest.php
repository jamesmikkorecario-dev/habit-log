<?php

namespace Tests\Feature\Habits;

use App\Enums\HabitVisibility;
use App\Models\Category;
use App\Models\Habit;
use App\Models\HabitCheckpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HabitTest extends TestCase
{
    use RefreshDatabase;

    private function form(User $user, array $values = [])
    {
        $category = Category::factory()->system()->create();
        $component = Livewire::actingAs($user)->test('pages::habits.edit')
            ->set('form.name', 'Read daily')->set('form.category_id', $category->id)->set('form.start_date', '2026-09-27');
        foreach ($values as $key => $value) {
            $component->set('form.'.$key, $value);
        }

        return $component;
    }

    #[DataProvider('types')]
    public function test_all_types_can_be_created_loaded_and_edited(string $type, mixed $goal, mixed $unit, array $checkpoints): void
    {
        $user = User::factory()->public()->create();
        $this->form($user, ['type' => $type, 'goal_value' => $goal, 'goal_unit' => $unit, 'checkpoints' => $checkpoints])
            ->assertSet('form.visibility', 'private')->call('save')->assertHasNoErrors();
        $habit = $user->habits()->sole();
        $this->assertSame($type, $habit->type->value);
        $this->assertSame(HabitVisibility::Private, $habit->visibility);
        $this->assertSame($goal, $habit->goal_value);
        $this->assertSame($type === 'duration' ? 'minutes' : $unit, $habit->goal_unit);
        $this->assertSame(count($checkpoints), $habit->checkpoints()->count());
        $this->actingAs($user)->get(route('habits.show', $habit))->assertOk()->assertSee('Read daily');
        Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->assertSet('form.type', $type)
            ->set('form.name', ' Updated habit ')->set('form.description', ' 0 ')->set('form.visibility', 'followers')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('habits.show', $habit));
        $habit->refresh();
        $this->assertSame('Updated habit', $habit->name);
        $this->assertSame('0', $habit->description);
        $this->assertSame(HabitVisibility::Followers, $habit->visibility);
    }

    public static function types(): array
    {
        return [
            'boolean' => ['boolean', null, null, []],
            'count goal' => ['count', 10, 'pages', []],
            'count optional' => ['count', null, null, []],
            'duration goal' => ['duration', 30, null, []],
            'duration optional' => ['duration', null, null, []],
            'checkpoints' => ['checkpoint', null, null, [['label' => 'Morning'], ['label' => 'Evening']]],
            'avoidance zero' => ['avoidance', 0, 'times', []],
            'avoidance optional' => ['avoidance', null, null, []],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_configuration_does_not_create_partial_records(array $values, string $error): void
    {
        $this->form(User::factory()->create(), $values)->call('save')->assertHasErrors('form.'.$error);
        $this->assertDatabaseCount('habits', 0);
        $this->assertDatabaseCount('habit_checkpoints', 0);
    }

    public static function invalidFields(): array
    {
        return [
            [['name' => '  '], 'name'], [['name' => str_repeat('x', 101)], 'name'],
            [['description' => str_repeat('x', 2001)], 'description'],
            [['type' => 'invalid'], 'type'], [['visibility' => 'invalid'], 'visibility'],
            [['category_id' => ''], 'category_id'], [['category_id' => 99999999], 'category_id'],
            [['start_date' => ''], 'start_date'], [['start_date' => '2025-02-29'], 'start_date'],
            [['start_date' => '0999-12-31'], 'start_date'], [['end_date' => '2026-09-26'], 'end_date'],
            [['end_date' => '10000-01-01'], 'end_date'],
            [['type' => 'count', 'goal_value' => 0], 'goal_value'],
            [['type' => 'duration', 'goal_value' => -1], 'goal_value'],
            [['type' => 'avoidance', 'goal_value' => -1], 'goal_value'],
            [['type' => 'count', 'goal_value' => 1.5], 'goal_value'],
            [['type' => 'count', 'goal_value' => 'many'], 'goal_value'],
            [['type' => 'count', 'goal_value' => 1000000000], 'goal_value'],
            [['type' => 'count', 'goal_unit' => str_repeat('x', 31)], 'goal_unit'],
            [['type' => 'checkpoint', 'checkpoints' => []], 'checkpoints'],
            [['type' => 'checkpoint', 'checkpoints' => [['label' => ' ']]], 'checkpoints.0.label'],
            [['type' => 'checkpoint', 'checkpoints' => [['label' => '---']]], 'checkpoints.0.label'],
            [['type' => 'checkpoint', 'checkpoints' => [['label' => str_repeat('a', 81)]]], 'checkpoints.0.label'],
            [['type' => 'checkpoint', 'checkpoints' => [['label' => 'Morning'], ['label' => ' MORNING ']]], 'checkpoints.1.label'],
            [['type' => 'checkpoint', 'checkpoints' => array_fill(0, 21, ['label' => 'Label'])], 'checkpoints'],
        ];
    }

    public function test_boundary_values_optional_fields_and_zero_text_are_preserved(): void
    {
        $user = User::factory()->create();
        $this->form($user, ['name' => ' 0 ', 'description' => ' ', 'type' => 'count', 'goal_value' => 999999999, 'goal_unit' => ' 0 ', 'start_date' => '2024-02-29', 'end_date' => '2024-02-29'])
            ->call('save')->assertHasNoErrors();
        $habit = $user->habits()->sole();
        $this->assertSame('0', $habit->name);
        $this->assertNull($habit->description);
        $this->assertSame('0', $habit->goal_unit);
        $this->assertSame('2024-02-29', $habit->end_date->toDateString());
        $this->assertSame(999999999, $habit->goal_value);
    }

    public function test_twenty_checkpoints_and_reorder_preserve_ids_and_edits_remove_only_selected_rows(): void
    {
        $user = User::factory()->create();
        $this->form($user, ['type' => 'checkpoint', 'checkpoints' => array_map(fn ($i) => ['label' => 'Step '.$i], range(1, 20))])->call('save')->assertHasNoErrors();
        $habit = $user->habits()->sole();
        $ids = $habit->checkpoints()->pluck('id')->all();
        $component = Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])
            ->call('moveCheckpoint', 1, -1)->set('form.checkpoints.0.label', 'Renamed')->call('removeCheckpoint', 19)->call('save')->assertHasNoErrors();
        $this->assertSame([$ids[1], $ids[0], ...array_slice($ids, 2, 17)], $habit->checkpoints()->pluck('id')->all());
        $this->assertSame('Renamed', $habit->checkpoints()->first()->label);
        $this->assertDatabaseMissing('habit_checkpoints', ['id' => $ids[19]]);
        Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->set('form.type', 'duration')->set('form.goal_value', ' ')->set('form.goal_unit', 'hours')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('habit_checkpoints', 0);
        $this->assertNull($habit->refresh()->goal_value);
        $this->assertSame('minutes', $habit->goal_unit);
        Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->set('form.type', 'boolean')->set('form.goal_value', 50)->call('save')->assertHasNoErrors();
        $this->assertNull($habit->refresh()->goal_value);
        $this->assertNull($habit->goal_unit);
    }

    public function test_foreign_categories_and_checkpoints_are_rejected_atomically(): void
    {
        $user = User::factory()->create();
        $foreignCategory = Category::factory()->create();
        $foreignCheckpoint = HabitCheckpoint::factory()->create();
        $habit = Habit::factory()->for($user)->create();
        $before = $habit->refresh()->getAttributes();
        Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->set('form.name', 'Unauthorized')->set('form.category_id', $foreignCategory->id)->call('save')->assertHasErrors('form.category_id');
        Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->set('form.name', 'Unauthorized')->set('form.type', 'checkpoint')->set('form.checkpoints', [['id' => $foreignCheckpoint->id, 'label' => 'Stolen']])->call('save')->assertHasErrors('form.checkpoints.0.id');
        $this->assertSame($before, $habit->refresh()->getAttributes());
        $this->assertNotSame('Stolen', $foreignCheckpoint->refresh()->label);
    }

    public function test_failure_after_habit_write_rolls_back_every_change(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create(['type' => 'checkpoint']);
        $checkpoint = HabitCheckpoint::factory()->for($habit)->create(['label' => 'Original']);
        $before = $habit->refresh()->getAttributes();
        $listener = fn () => throw new \RuntimeException('Simulated checkpoint write failure');
        HabitCheckpoint::updating($listener);
        try {
            Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->set('form.name', 'New name')->set('form.checkpoints.0.label', 'Changed')->call('save');
            $this->fail('Expected checkpoint failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated checkpoint write failure', $exception->getMessage());
        } finally {
            HabitCheckpoint::flushEventListeners();
        }
        $this->assertSame($before, $habit->refresh()->getAttributes());
        $this->assertSame('Original', $checkpoint->refresh()->label);
    }

    public function test_routes_reject_guests_and_other_owners_for_every_visibility(): void
    {
        $user = User::factory()->create();
        foreach (['private', 'followers', 'public'] as $visibility) {
            $habit = Habit::factory()->create(['visibility' => $visibility]);
            Auth::logout();
            foreach (['habits.index', 'habits.create', 'habits.show', 'habits.edit'] as $route) {
                $this->get(route($route, ['habit' => $habit->id]))->assertRedirect(route('login'));
            }
            $this->actingAs($user)->get(route('habits.show', $habit))->assertNotFound();
            $this->actingAs($user)->get(route('habits.edit', $habit))->assertNotFound();
            $this->actingAs($user)->get(route('habits.index'))->assertOk()->assertDontSee($habit->name);
        }
    }

    public function test_logged_out_livewire_save_is_rejected_and_habit_unchanged(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create();
        $before = $habit->refresh()->getAttributes();
        $page = $this->actingAs($user)->get(route('habits.edit', $habit))->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        Auth::logout();
        $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5),
            'updates' => ['form.name' => 'Unauthorized'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]])->assertUnauthorized();
        $this->assertSame($before, $habit->refresh()->getAttributes());
    }

    public function test_rendered_habit_content_is_escaped(): void
    {
        $user = User::factory()->create();
        $script = '<script>alert(1)</script>';
        $habit = Habit::factory()->for($user)->create(['name' => $script, 'description' => $script, 'type' => 'checkpoint']);
        HabitCheckpoint::factory()->for($habit)->create(['label' => $script]);
        $this->actingAs($user)->get(route('habits.show', $habit))->assertOk()->assertSee(e($script), false)->assertDontSee($script, false);
    }

    public function test_habit_identity_cannot_be_changed_in_a_livewire_update(): void
    {
        $user = User::factory()->create();
        $own = Habit::factory()->for($user)->create();
        $other = Habit::factory()->create();
        $before = $other->refresh()->getAttributes();
        try {
            Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $own->id])->set('habitId', $other->id)->call('save');
            $this->fail('The server-owned identity must be locked.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame($before, $other->refresh()->getAttributes());
        }
    }

    public function test_mass_assignment_cannot_change_ownership_or_lifecycle(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $habit = Habit::factory()->for($owner)->create();
        $habit->fill(['user_id' => $other->id, 'archived_at' => now(), 'deleted_at' => now()])->save();
        $this->assertSame($owner->id, $habit->refresh()->user_id);
        $this->assertNull($habit->archived_at);
        $this->assertNull($habit->deleted_at);
        $category = Category::factory()->for($owner)->create();
        $category->fill(['user_id' => $other->id, 'is_system' => true])->save();
        $this->assertSame($owner->id, $category->refresh()->user_id);
        $this->assertFalse($category->is_system);
    }
}
