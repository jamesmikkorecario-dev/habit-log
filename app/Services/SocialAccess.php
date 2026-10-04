<?php

namespace App\Services;

use App\Models\Follow;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SocialAccess
{
    public function accepted(User $viewer, User $subject): bool
    {
        return Follow::query()->where('follower_id', $viewer->id)->where('followed_id', $subject->id)->where('status', 'accepted')->exists();
    }

    public function profile(User $viewer, User $subject): bool
    {
        return $subject->username !== null && ($viewer->id === $subject->id || ! $subject->is_private || $this->accepted($viewer, $subject));
    }

    public function habits(User $viewer, User $subject): Builder
    {
        $query = Habit::query()->where('user_id', $subject->id);
        if ($subject->username === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($viewer->id === $subject->id) {
            return $query;
        }
        $accepted = $this->accepted($viewer, $subject);
        if ($subject->is_private && ! $accepted) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('visibility', $accepted ? ['public', 'followers'] : ['public']);
    }
}
