<?php

namespace App\Policies;

use App\Models\User;
use App\Services\SocialAccess;

class UserPolicy
{
    public function delete(User $actor, User $subject): bool
    {
        return $actor->id === $subject->id;
    }

    public function viewShared(User $viewer, User $subject): bool
    {
        $fresh = $subject->fresh();

        return $fresh !== null && app(SocialAccess::class)->profile($viewer, $fresh);
    }
}
