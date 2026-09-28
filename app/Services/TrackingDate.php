<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitTrackingVersion;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class TrackingDate
{
    public function validate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31']])->validate();
    }

    public function version(Habit $habit, string $date): ?HabitTrackingVersion
    {
        if ($habit->relationLoaded('versions')) {
            return $habit->versions->filter(fn ($version) => $version->effective_date->toDateString() <= $date)->sortByDesc('effective_date')->first();
        }

        return $habit->versions()->where('effective_date', '<=', $date)->latest('effective_date')->first();
    }

    public function reason(User $user, Habit $habit, string $date): ?string
    {
        if ($habit->trashed()) {
            return 'Restore this habit before recording activity.';
        }
        if ($date > $user->localToday()) {
            return 'Future dates cannot be recorded.';
        }
        $version = $this->version($habit, $date);
        if (! $version || $date < $version->start_date->toDateString() || ($version->end_date && $date > $version->end_date->toDateString())) {
            return 'Outside this habit’s active dates.';
        }
        $suspended = $habit->relationLoaded('suspensions')
            ? $habit->suspensions->contains(fn ($period) => $period->start_date->toDateString() <= $date && (! $period->end_date || $period->end_date->toDateString() > $date))
            : $habit->suspensions()->where('start_date', '<=', $date)->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>', $date))->exists();

        return $suspended ? 'This date falls in an archive or trash period.' : null;
    }
}
