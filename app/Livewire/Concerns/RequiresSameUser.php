<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

trait RequiresSameUser
{
    #[Locked]
    public int $sessionUserId;

    public function mountRequiresSameUser(): void
    {
        abort_unless(auth()->check(), 401);
        $this->sessionUserId = auth()->id();
    }

    public function hydrateRequiresSameUser(): void
    {
        abort_unless(auth()->check(), 401);
        abort_unless(auth()->id() === $this->sessionUserId, 403);
    }
}
