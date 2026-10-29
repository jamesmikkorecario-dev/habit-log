<?php

namespace App\Actions\Categories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateCategory
{
    public function handle(User $user, array $validated): Category
    {
        return DB::transaction(function () use ($user, $validated) {
            $fresh = User::whereKey($user->id)->sharedLock()->firstOrFail();
            Gate::forUser($fresh)->authorize('create', Category::class);
            $category = $fresh->categories()->make($validated + ['slug' => 'custom-'.Str::uuid()]);
            $category->forceFill(['is_system' => false])->save();

            return $category;
        });
    }
}
