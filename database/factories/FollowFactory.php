<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FollowFactory extends Factory
{
    public function definition(): array
    {
        return ['follower_id' => User::factory(), 'followed_id' => User::factory(), 'status' => 'pending', 'requested_at' => now(), 'accepted_at' => null];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => 'accepted', 'accepted_at' => now()]);
    }
}
