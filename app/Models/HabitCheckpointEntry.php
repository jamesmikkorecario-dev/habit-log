<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitCheckpointEntry extends Model
{
    protected function casts(): array
    {
        return ['is_completed' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(HabitEntry::class, 'habit_entry_id');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(HabitTrackingCheckpoint::class, 'habit_tracking_checkpoint_id');
    }
}
