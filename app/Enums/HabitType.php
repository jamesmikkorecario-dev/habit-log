<?php

namespace App\Enums;

enum HabitType: string
{
    case Boolean = 'boolean';
    case Count = 'count';
    case Duration = 'duration';
    case Checkpoint = 'checkpoint';
    case Avoidance = 'avoidance';

    public function label(): string
    {
        return match ($this) {
            self::Boolean => 'Yes / No',
            self::Count => 'Count',
            self::Duration => 'Duration',
            self::Checkpoint => 'Checkpoints',
            self::Avoidance => 'Avoidance',
        };
    }
}
