<?php

namespace App\Services\Analytics;

class StreakCalculator
{
    /** Segments have inclusive integer calendar-day boundaries and aggregate counts. */
    public function calculate(iterable $segments): array
    {
        $current = $longest = 0;
        foreach ($segments as $segment) {
            if ($segment['unavailable'] > 0 || $segment['failures'] > 0) {
                $current = 0;
            } elseif ($segment['count'] > 0 && $segment['pending'] === 0) {
                $current += $segment['end'] - $segment['start'] + 1;
                $longest = max($longest, $current);
            }
        }

        return ['current' => $current, 'longest' => $longest];
    }
}
