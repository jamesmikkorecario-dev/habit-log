<?php

namespace App\Policies;

use App\Models\HabitEntry;
use App\Models\User;

class HabitEntryPolicy
{
    public function view(User $user, HabitEntry $entry): bool
    {
        return $entry->user_id === $user->id && $entry->habit !== null && $entry->habit->user_id === $user->id;
    }

    public function update(User $user, HabitEntry $entry): bool
    {
        return $this->view($user, $entry);
    }
}
