<?php

namespace App\Models;

use App\Enums\HabitType;
use App\Enums\HabitVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'name', 'description', 'type', 'visibility', 'goal_value', 'goal_unit', 'start_date', 'end_date'])]
class Habit extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['visibility' => 'private'];

    protected function casts(): array
    {
        return [
            'type' => HabitType::class,
            'visibility' => HabitVisibility::class,
            'goal_value' => 'integer',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HabitTrackingVersion::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HabitEntry::class);
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(HabitSuspension::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(HabitCheckpoint::class)->orderBy('sort_order')->orderBy('id');
    }
}
