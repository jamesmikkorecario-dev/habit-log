<?php

namespace App\Enums;

enum HabitVisibility: string
{
    case Private = 'private';
    case Followers = 'followers';
    case Public = 'public';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
