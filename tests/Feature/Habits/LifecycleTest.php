<?php

namespace Tests\Feature\Habits;

use App\Models\Category;
use App\Models\Habit;
use App\Models\HabitCheckpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('archiveStates')]
    public function test_trash_and_restore_preserve_configuration_and_prior_archive_state(bool $archived): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create(['type' => 'checkpoint', 'archived_at' => $archived ? now()->subDays(2) : null, 'end_date' => today()->toDateString()]);
        $checkpoint = HabitCheckpoint::factory()->for($habit)->create();
        $before = $habit->fresh()->only(['name', 'user_id', 'category_id', 'start_date', 'end_date', 'type', 'visibility', 'archived_at']);
        $component = Livewire::actingAs($user)->test('pages::habits.index')->call('confirmTrash', $habit->id)->assertSet('showTrashModal', true);
        $this->assertNotSoftDeleted($habit);
        $component->set('showTrashModal', false);
        $this->assertNotSoftDeleted($habit);
        $component->call('confirmTrash', $habit->id)->call('trash')->assertSet('showTrashModal', false)->assertSee('Habit moved to trash');
        $this->assertSoftDeleted($habit);
        $this->assertDatabaseHas('habit_checkpoints', ['id' => $checkpoint->id]);
        $this->actingAs($user)->get(route('habits.show', $habit))->assertNotFound();
        $this->actingAs($user)->get(route('habits.edit', $habit))->assertNotFound();
        Livewire::actingAs($user)->test('pages::habits.index')->assertDontSee($habit->name)->set('tab', 'trash')->assertSee($habit->name);
        $this->travel(40)->days();
        Livewire::actingAs($user)->test('pages::habits.index')->set('tab', 'trash')->call('restore', $habit->id)->assertSee('Habit restored');
        $this->assertNotSoftDeleted($habit);
        $this->assertEquals($before, $habit->fresh()->only(array_keys($before)));
        $this->assertSame($checkpoint->id, $habit->checkpoints()->sole()->id);
    }

    public static function archiveStates(): array
    {
        return ['active' => [false], 'archived' => [true]];
    }

    public function test_archive_unarchive_and_repeated_actions_preserve_dates_and_timestamp(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create(['start_date' => '2020-01-01', 'end_date' => '2020-01-02']);
        Livewire::actingAs($user)->test('pages::habits.index')->call('archive', $habit->id)->assertSee('Habit archived');
        $archivedAt = $habit->fresh()->archived_at;
        $this->travel(1)->days();
        Livewire::actingAs($user)->test('pages::habits.index')->call('archive', $habit->id)->assertForbidden();
        $this->assertEquals($archivedAt, $habit->fresh()->archived_at);
        Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id])->set('form.name', 'Edited while archived')->call('save')->assertHasNoErrors();
        Livewire::actingAs($user)->test('pages::habits.index')->set('tab', 'archived')->assertSee('Edited while archived')->call('unarchive', $habit->id);
        $this->assertNull($habit->fresh()->archived_at);
        $this->assertSame('2020-01-02', $habit->fresh()->end_date->toDateString());
        Livewire::actingAs($user)->test('pages::habits.index')->call('unarchive', $habit->id)->assertForbidden();
    }

    #[DataProvider('foreignActions')]
    public function test_each_lifecycle_action_rejects_foreign_identifiers(string $action, bool $trashed): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->create(['archived_at' => $action === 'unarchive' ? now() : null]);
        if ($trashed) {
            $habit->delete();
        }
        $before = $habit->refresh()->getAttributes();
        try {
            Livewire::actingAs($user)->test('pages::habits.index')->call($action, $habit->id);
            $this->fail('Foreign habit must not be found.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $habit->refresh()->getAttributes());
        }
        $this->assertFalse(Gate::forUser($user)->allows('forceDelete', $habit));
        $this->assertFalse(Gate::forUser($habit->user)->allows('forceDelete', $habit));
    }

    public static function foreignActions(): array
    {
        return [['archive', false], ['unarchive', false], ['confirmTrash', false], ['restore', true]];
    }

    public function test_stale_save_after_trash_does_not_edit_or_restore(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create();
        $component = Livewire::actingAs($user)->test('pages::habits.edit', ['habit' => $habit->id]);
        $habit->delete();
        $before = $habit->refresh()->getAttributes();
        try {
            $component->set('form.name', 'Stale edit')->call('save');
            $this->fail('Trashed record must not be editable.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $habit->refresh()->getAttributes());
        }
    }

    public function test_stale_trash_confirmation_cannot_rewrite_deletion_time(): void
    {
        $user = User::factory()->create();
        $habit = Habit::factory()->for($user)->create();
        $component = Livewire::actingAs($user)->test('pages::habits.index')->call('confirmTrash', $habit->id);
        $habit->delete();
        $deletedAt = $habit->deleted_at;
        $this->travel(1)->days();
        try {
            $component->call('trash');
            $this->fail('Repeated trash must not write again.');
        } catch (ModelNotFoundException) {
            $this->assertEquals($deletedAt, $habit->fresh()->deleted_at);
        }
    }

    public function test_filters_and_pagination_never_expand_ownership(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->system()->create();
        Habit::factory()->count(21)->for($user)->for($category)->create();
        $foreign = Habit::factory()->for($category)->create(['name' => 'Foreign hidden habit']);
        $component = Livewire::actingAs($user)->test('pages::habits.index')->set('category', (string) $category->id)->assertDontSee($foreign->name);
        $this->assertSame(21, $component->instance()->habits->total());
        $this->assertSame(20, $component->instance()->habits->count());
        $component->call('setPage', 2)->assertDontSee($foreign->name);
        $this->assertSame(1, $component->instance()->habits->count());
        $component->set('category', (string) Category::factory()->create()->id)->assertSee('No habits in this list');
        $component->set('tab', 'trash')->assertDontSee($foreign->name);
    }
}
