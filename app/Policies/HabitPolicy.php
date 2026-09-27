<?php

namespace App\Policies;

use App\Models\Habit;
use App\Models\User;

class HabitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id && ! $habit->trashed();
    }

    public function update(User $user, Habit $habit): bool
    {
        return $this->view($user, $habit);
    }

    public function archive(User $user, Habit $habit): bool
    {
        return $this->view($user, $habit) && $habit->archived_at === null;
    }

    public function unarchive(User $user, Habit $habit): bool
    {
        return $this->view($user, $habit) && $habit->archived_at !== null;
    }

    public function delete(User $user, Habit $habit): bool
    {
        return $this->view($user, $habit);
    }

    public function restore(User $user, Habit $habit): bool
    {
        return $habit->user_id === $user->id && $habit->trashed();
    }

    public function forceDelete(User $user, Habit $habit): bool
    {
        return false;
    }
}
