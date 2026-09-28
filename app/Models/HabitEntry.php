<?php

namespace App\Models;

use App\Enums\HabitEntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HabitEntry extends Model
{
    protected function casts(): array
    {
        return ['entry_date' => 'immutable_date', 'status' => HabitEntryStatus::class, 'value' => 'integer', 'revision' => 'integer'];
    }

    protected $hidden = ['note', 'not_applicable_reason'];

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(HabitTrackingVersion::class, 'habit_tracking_version_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(HabitCheckpointEntry::class);
    }
}
