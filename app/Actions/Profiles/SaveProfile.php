<?php

namespace App\Actions\Profiles;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveProfile
{
    /** The settings boundary supplies its validated, allowlisted fields. */
    public function handle(User $user, array $validated): void
    {
        DB::transaction(function () use ($user, $validated) {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->update(array_intersect_key($validated, array_flip(['username', 'display_name', 'bio', 'is_private', 'timezone', 'theme_key'])));
        });
    }
}
