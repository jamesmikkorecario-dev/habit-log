<?php

namespace Database\Factories;

use App\Models\Category;
use App\Support\NormalizedName;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory()->system(),
            'alias' => fake()->unique()->word(),
            'normalized_alias' => fn (array $attributes) => NormalizedName::from($attributes['alias']),
        ];
    }
}
