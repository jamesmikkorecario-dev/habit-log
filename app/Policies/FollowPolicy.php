<?php

namespace App\Policies;

use App\Models\Follow;
use App\Models\User;

class FollowPolicy
{
    public function create(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $actor->username !== null && $target->username !== null;
    }

    public function approve(User $actor, Follow $follow): bool
    {
        return $actor->id === $follow->followed_id;
    }

    public function reject(User $actor, Follow $follow): bool
    {
        return $this->approve($actor, $follow);
    }

    public function remove(User $actor, Follow $follow): bool
    {
        return $this->approve($actor, $follow);
    }

    public function cancel(User $actor, Follow $follow): bool
    {
        return $actor->id === $follow->follower_id;
    }

    public function unfollow(User $actor, Follow $follow): bool
    {
        return $this->cancel($actor, $follow);
    }
}
