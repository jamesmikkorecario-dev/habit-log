<?php

namespace App\Enums;

enum HabitEntryStatus: string
{
    case Completed = 'completed';
    case Partial = 'partial';
    case Missed = 'missed';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed', self::Partial => 'Partial',
            self::Missed => 'Missed', self::NotApplicable => 'Not applicable',
        };
    }
}
