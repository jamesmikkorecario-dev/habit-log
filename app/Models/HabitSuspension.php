<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitSuspension extends Model
{
    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date'];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
