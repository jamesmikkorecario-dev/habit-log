<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\SaveHabit;
use App\Models\Category;
use App\Models\Habit;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

abstract class TrackingTestCase extends TestCase
{
    protected function habit(string $type = 'boolean', array $overrides = [], ?User $user = null): Habit
    {
        $user ??= User::factory()->create();

        return app(SaveHabit::class)->handle($user, null, $overrides + [
            'name' => 'Daily practice', 'category_id' => Category::factory()->system()->create()->id,
            'type' => $type, 'visibility' => 'private', 'description' => null,
            'goal_value' => null, 'goal_unit' => $type === 'duration' ? 'minutes' : null,
            'start_date' => '2024-01-01', 'end_date' => null,
            'checkpoints' => $type === 'checkpoint' ? [['label' => 'Read'], ['label' => 'Reflect']] : [],
        ]);
    }

    protected function editor(Habit $habit, string $date = '2026-09-27')
    {
        return Livewire::actingAs($habit->user)->test('pages::tracking.edit', ['habit' => $habit->id, 'date' => $date]);
    }

    protected function editConfiguration(Habit $habit, array $changes): Habit
    {
        return app(SaveHabit::class)->handle($habit->user, $habit->id, $changes + $habit->fresh()->attributesToArray() + [
            'checkpoints' => $habit->checkpoints()->get()->map(fn ($c) => $c->only(['id', 'label']))->all(),
        ]);
    }
}
