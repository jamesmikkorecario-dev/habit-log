<?php

namespace App\Services\Analytics;

class HabitScore
{
    public function calculate(string $type, ?int $goal, string $status, ?int $value, int $checked = 0, int $total = 0, bool $validCheckpoints = true): array
    {
        if ($status === 'not_applicable') {
            return $this->state('na');
        }
        if (! in_array($status, ['completed', 'partial', 'missed'], true)) {
            return $this->state('unavailable');
        }
        if ($type === 'boolean') {
            return $status === 'partial' ? $this->state('unavailable') : $this->scored($status === 'completed' ? 1.0 : 0.0);
        }
        if ($type === 'checkpoint') {
            return ! $validCheckpoints || $total < 1 || $total > 20 || $checked < 0 || $checked > $total
                ? $this->state('unavailable') : $this->scored($checked / $total, $checked === $total);
        }
        if (! in_array($type, ['count', 'duration', 'avoidance'], true) || $value === null || $value < 0 || $value > 999999999 || ($goal !== null && ($goal < 0 || $goal > 999999999))) {
            return $this->state('unavailable');
        }
        if ($goal === null) {
            return $this->scored(match ($status) {
                'completed' => 1.0, 'partial' => 0.5, default => 0.0
            });
        }
        if ($type === 'avoidance') {
            return $this->scored($value <= $goal ? 1.0 : 0.0);
        }

        return $goal === 0 ? $this->state('unavailable') : $this->scored(min($value / $goal, 1.0), $value >= $goal);
    }

    public function state(string $state): array
    {
        return ['state' => $state, 'score' => $state === 'unlogged' ? 0.0 : null, 'success' => false];
    }

    private function scored(float $score, ?bool $success = null): array
    {
        return ['state' => 'recorded', 'score' => $score, 'success' => $success ?? ($score === 1.0)];
    }
}
