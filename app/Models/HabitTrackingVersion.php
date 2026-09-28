<?php

namespace App\Models;

use App\Enums\HabitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HabitTrackingVersion extends Model
{
    protected function casts(): array
    {
        return ['effective_date' => 'immutable_date', 'start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'type' => HabitType::class, 'goal_value' => 'integer', 'revision' => 'integer'];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(HabitTrackingCheckpoint::class)->orderBy('sort_order')->orderBy('id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HabitEntry::class);
    }
}
