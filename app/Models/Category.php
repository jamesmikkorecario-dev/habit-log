<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'normalized_name', 'slug', 'description'])]
class Category extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function scopeAvailableTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('is_system', true)->orWhere('user_id', $user->id));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CategoryAlias::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }
}
