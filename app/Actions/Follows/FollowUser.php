<?php

namespace App\Actions\Follows;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FollowUser
{
    public function handle(User $actor, int $targetId): Follow
    {
        return DB::transaction(function () use ($actor, $targetId) {
            $users = User::query()->whereIn('id', [$actor->id, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $target = $users->get($targetId);
            abort_unless($actor && $target, 404);
            Gate::forUser($actor)->authorize('create', [Follow::class, $target]);
            $existing = Follow::query()->where('follower_id', $actor->id)->where('followed_id', $targetId)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $now = now();
            $follow = new Follow;
            $follow->forceFill(['follower_id' => $actor->id, 'followed_id' => $targetId, 'status' => $target->is_private ? 'pending' : 'accepted', 'requested_at' => $now, 'accepted_at' => $target->is_private ? null : $now])->save();

            return $follow;
        });
    }
}
