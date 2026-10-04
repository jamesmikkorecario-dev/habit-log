<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'journal_date' => '2026-10-01', 'content' => 'A private reflection.', 'mood' => null, 'revision' => 1];
    }
}
