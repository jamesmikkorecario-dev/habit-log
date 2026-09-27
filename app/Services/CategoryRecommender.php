<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use App\Support\NormalizedName;
use Illuminate\Support\Collection;

class CategoryRecommender
{
    public function recommend(User $user, string $name): Collection
    {
        return $this->rank($name, Category::availableTo($user)->with('aliases')->get());
    }

    public function rank(string $name, Collection $categories): Collection
    {
        $normalized = NormalizedName::from($name);
        if ($normalized === '') {
            return collect();
        }

        $tokens = array_unique(explode(' ', $normalized));

        return $categories->map(function (Category $category) use ($normalized, $tokens) {
            $aliases = $category->aliases->pluck('normalized_alias')->all();
            $keywords = array_unique(explode(' ', implode(' ', [$category->normalized_name, ...$aliases])));
            $overlap = count(array_intersect($tokens, $keywords));
            $tier = $normalized === $category->normalized_name ? 3 : (in_array($normalized, $aliases, true) ? 2 : ($overlap > 0 ? 1 : 0));

            return ['category' => $category, 'tier' => $tier, 'overlap' => $tier === 1 ? $overlap : 0];
        })->filter(fn (array $match) => $match['tier'] > 0)
            ->sort(fn (array $a, array $b) => ($b['tier'] <=> $a['tier'])
                ?: ($b['overlap'] <=> $a['overlap'])
                ?: strcmp($a['category']->normalized_name, $b['category']->normalized_name)
                ?: ($a['category']->id <=> $b['category']->id))
            ->pluck('category')->unique('id')->take(3)->values();
    }
}
