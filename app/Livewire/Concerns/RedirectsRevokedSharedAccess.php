<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait RedirectsRevokedSharedAccess
{
    protected bool $sharedAccessRedirected = false;

    public function exception($e, $stopPropagation): void
    {
        // Initial denied URLs remain 404. An already-open shared page must not
        // retain its old content behind Livewire's failed-request overlay.
        if ($this->subjectId === null || ! request()->hasHeader('X-Livewire')
            || ! ($e instanceof ModelNotFoundException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 404))) {
            return;
        }

        $username = User::whereKey($this->subjectId)->value('username');
        $this->sharedAccessRedirected = true;
        $this->skipRender();
        $this->redirect($username ? route('people.show', ['user' => $username]) : route('people.index'), navigate: true);
        $stopPropagation();
    }
}
