<?php

namespace Database\Factories;

use App\Models\Habit;
use Illuminate\Database\Eloquent\Factories\Factory;

class HabitCheckpointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'habit_id' => Habit::factory()->state(['type' => 'checkpoint']),
            'label' => fake()->words(2, true),
            'sort_order' => 0,
        ];
    }
}
