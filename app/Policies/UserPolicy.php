<?php

namespace App\Policies;

use App\Models\User;
use App\Services\SocialAccess;

class UserPolicy
{
    public function viewShared(User $viewer, User $subject): bool
    {
        return app(SocialAccess::class)->profile($viewer, $subject->fresh());
    }
}
