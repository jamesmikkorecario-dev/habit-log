<?php

namespace App\Models;

use App\Enums\FollowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Follow extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => FollowStatus::class, 'requested_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }

    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function followed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_id');
    }
}
