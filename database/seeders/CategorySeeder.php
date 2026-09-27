<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Support\NormalizedName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Health & Fitness' => ['exercise', 'workout', 'movement'],
            'Nutrition' => ['food', 'meals', 'hydration', 'water'],
            'Sleep' => ['bedtime', 'rest'],
            'Learning' => ['study', 'reading', 'practice'],
            'Work & Productivity' => ['focus', 'planning', 'productivity'],
            'Mindfulness' => ['meditation', 'breathing'],
            'Home & Personal Care' => ['cleaning', 'chores', 'hygiene'],
            'Relationships' => ['family', 'friends', 'connection'],
            'Finance' => ['saving', 'budget', 'spending'],
            'Other' => ['miscellaneous'],
        ];

        DB::transaction(function () use ($catalog) {
            foreach ($catalog as $name => $aliases) {
                $category = Category::firstOrNew(['slug' => 'system-'.Str::slug($name)]);
                $category->forceFill([
                    'name' => $name, 'normalized_name' => NormalizedName::from($name),
                    'user_id' => null, 'is_system' => true,
                ])->save();

                foreach ($aliases as $alias) {
                    $category->aliases()->updateOrCreate(
                        ['normalized_alias' => NormalizedName::from($alias)],
                        ['alias' => $alias],
                    );
                }
            }
        });
    }
}
