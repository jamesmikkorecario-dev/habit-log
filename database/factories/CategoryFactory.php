<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\NormalizedName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'normalized_name' => fn (array $attributes) => NormalizedName::from($attributes['name']),
            'slug' => 'custom-'.Str::uuid(),
            'description' => null,
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => ['user_id' => null, 'is_system' => true]);
    }
}
