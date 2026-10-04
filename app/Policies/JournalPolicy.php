<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;

class JournalPolicy
{
    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, Journal $journal): bool
    {
        return $journal->user_id === $user->id;
    }

    public function update(User $user, Journal $journal): bool
    {
        return $this->view($user, $journal);
    }

    public function delete(User $user, Journal $journal): bool
    {
        return $this->view($user, $journal);
    }
}
