<?php

namespace App\Livewire\Concerns;

use App\Actions\Follows\FollowUser;
use App\Actions\Follows\TransitionFollow;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait InteractsWithFollows
{
    #[Locked]
    public int $viewerId;

    #[Locked]
    public ?int $confirmedFollowId = null;

    #[Locked]
    public string $confirmedOperation = '';

    #[Locked]
    public string $confirmationName = '';

    public bool $showFollowModal = false;

    public string $feedback = '';

    private function viewer(): User
    {
        abort_unless(auth()->check(), 401);
        abort_unless(auth()->id() === $this->viewerId, 403);

        return auth()->user()->fresh();
    }

    public function hydrate(): void
    {
        $this->viewer();
    }

    public function follow(int $subjectId): void
    {
        $follow = app(FollowUser::class)->handle($this->viewer(), $subjectId);
        $this->feedback = $follow->status->value === 'accepted' ? 'Following.' : 'Follow request pending.';
        $this->refreshRelationships();
    }

    public function changeFollow(int $id, string $operation): void
    {
        abort_unless(in_array($operation, ['approve', 'reject', 'cancel'], true), 403);
        $this->applyFollowTransition($id, $operation);
    }

    public function confirmFollowRemoval(int $id, string $operation): void
    {
        $this->viewer();
        abort_unless(in_array($operation, ['unfollow', 'remove'], true), 403);
        $follow = Follow::findOrFail($id);
        $this->authorize($operation, $follow);
        if ($follow->status->value !== 'accepted') {
            throw ValidationException::withMessages(['relationship' => 'This relationship changed. Refresh the list before trying again.']);
        }
        $person = $operation === 'unfollow' ? $follow->followed : $follow->follower;
        $this->confirmedFollowId = $id;
        $this->confirmedOperation = $operation;
        $this->confirmationName = $person->sharedName();
        $this->showFollowModal = true;
    }

    public function cancelFollowRemoval(): void
    {
        $this->viewer();
        $this->confirmedFollowId = null;
        $this->confirmedOperation = '';
        $this->confirmationName = '';
        $this->showFollowModal = false;
    }

    public function removeFollow(): void
    {
        $this->viewer();
        abort_unless($this->confirmedFollowId !== null && in_array($this->confirmedOperation, ['unfollow', 'remove'], true), 403);
        $id = $this->confirmedFollowId;
        $operation = $this->confirmedOperation;
        $this->cancelFollowRemoval();
        $this->applyFollowTransition($id, $operation);
    }

    public function refreshRelationships(): void
    {
        $this->viewer();
        unset($this->profile, $this->relationships);
    }

    private function applyFollowTransition(int $id, string $operation): void
    {
        $this->feedback = '';
        $this->resetValidation('relationship');
        try {
            app(TransitionFollow::class)->handle($this->viewer(), $id, $operation);
            $this->feedback = match ($operation) {
                'approve' => 'Follow request approved.', 'reject' => 'Follow request rejected.',
                'cancel' => 'Follow request cancelled.', 'unfollow' => 'Unfollowed.', 'remove' => 'Follower removed.',
            };
        } finally {
            $this->refreshRelationships();
            $this->dispatch('relationship-changed');
        }
    }
}
