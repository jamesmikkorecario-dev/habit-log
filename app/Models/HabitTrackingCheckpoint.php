<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitTrackingCheckpoint extends Model
{
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public $timestamps = false;

    public function version(): BelongsTo
    {
        return $this->belongsTo(HabitTrackingVersion::class, 'habit_tracking_version_id');
    }
}
