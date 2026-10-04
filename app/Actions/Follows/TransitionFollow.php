<?php

namespace App\Actions\Follows;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionFollow
{
    public function handle(User $actor, int $id, string $operation): void
    {
        abort_unless(in_array($operation, ['approve', 'reject', 'cancel', 'unfollow', 'remove'], true), 422);
        $before = Follow::find($id);
        if (! $before) {
            $this->conflict();
        }
        Gate::forUser($actor)->authorize($operation, $before);
        DB::transaction(function () use ($actor, $id, $operation, $before) {
            $users = User::query()->whereIn('id', [$before->follower_id, $before->followed_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            abort_unless($actor, 403);
            $follow = Follow::query()->whereKey($id)->lockForUpdate()->first();
            $expected = in_array($operation, ['remove', 'unfollow'], true) ? 'accepted' : 'pending';
            if (! $follow || $follow->status->value !== $expected) {
                $this->conflict();
            }
            Gate::forUser($actor)->authorize($operation, $follow);
            if ($operation === 'approve') {
                $follow->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
            } else {
                $follow->delete();
            }
        });
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['relationship' => 'This relationship changed. Refresh the list before trying again.']);
    }
}
