<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HabitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory()->system(),
            'name' => fake()->words(3, true),
            'type' => 'boolean',
            'visibility' => 'private',
            'start_date' => today()->toDateString(),
        ];
    }
}
