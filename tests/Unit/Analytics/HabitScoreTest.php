<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\HabitScore;
use App\Services\Analytics\StreakCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HabitScoreTest extends TestCase
{
    #[DataProvider('scores')]
    public function test_historical_scoring_contract(string $type, ?int $goal, string $status, ?int $value, int $checked, int $total, ?float $expected, bool $success): void
    {
        $result = (new HabitScore)->calculate($type, $goal, $status, $value, $checked, $total);
        $this->assertSame($expected, $result['score']);
        $this->assertSame($success, $result['success']);
    }

    public static function scores(): array
    {
        return [
            ['boolean', null, 'completed', null, 0, 0, 1.0, true],
            ['boolean', null, 'missed', null, 0, 0, 0.0, false],
            ['boolean', null, 'partial', null, 0, 0, null, false],
            ['count', 10, 'completed', 5, 0, 0, 0.5, false],
            ['count', 10, 'missed', 12, 0, 0, 1.0, true],
            ['duration', 30, 'partial', 15, 0, 0, 0.5, false],
            ['count', 999999999, 'completed', 999999998, 0, 0, 999999998 / 999999999, false],
            ['checkpoint', null, 'completed', null, 2, 4, 0.5, false],
            ['checkpoint', null, 'completed', null, 4, 4, 1.0, true],
            ['checkpoint', null, 'missed', null, 0, 4, 0.0, false],
            ['checkpoint', null, 'completed', null, 0, 0, null, false],
            ['avoidance', 2, 'missed', 0, 0, 0, 1.0, true],
            ['avoidance', 2, 'missed', 2, 0, 0, 1.0, true],
            ['avoidance', 2, 'completed', 3, 0, 0, 0.0, false],
            ['avoidance', 0, 'missed', 0, 0, 0, 1.0, true],
            ['avoidance', 0, 'completed', 1, 0, 0, 0.0, false],
            ['count', null, 'completed', 0, 0, 0, 1.0, true],
            ['duration', null, 'partial', 15, 0, 0, 0.5, false],
            ['avoidance', null, 'missed', 0, 0, 0, 0.0, false],
            ['avoidance', 0, 'not_applicable', 12, 0, 0, null, false],
            ['count', 0, 'completed', 4, 0, 0, null, false],
            ['duration', 30, 'completed', null, 0, 0, null, false],
            ['count', 10, 'completed', -1, 0, 0, null, false],
            ['count', 10, 'completed', 1000000000, 0, 0, null, false],
        ];
    }

    public function test_scores_are_bounded_and_monotonic(): void
    {
        $scorer = new HabitScore;
        $previous = 0;
        $avoidance = 1;
        for ($value = 0; $value <= 100; $value++) {
            $score = $scorer->calculate('count', 40, 'partial', $value)['score'];
            $other = $scorer->calculate('avoidance', 40, 'partial', $value)['score'];
            $this->assertGreaterThanOrEqual($previous, $score);
            $this->assertLessThanOrEqual($avoidance, $other);
            $this->assertTrue(is_finite($score) && $score >= 0 && $score <= 1);
            $previous = $score;
            $avoidance = $other;
        }
    }

    public function test_streaks_skip_neutral_intervals_but_break_on_failure_and_unavailable(): void
    {
        $base = ['count' => 0, 'failures' => 0, 'pending' => 0, 'unavailable' => 0];
        $segments = [
            ['start' => 1, 'end' => 2, 'count' => 1] + $base,
            ['start' => 3, 'end' => 1000000] + $base,
            ['start' => 1000001, 'end' => 1000001, 'count' => 1] + $base,
            ['start' => 1000002, 'end' => 1000002, 'pending' => 1] + $base,
        ];
        $calculator = new StreakCalculator;
        $this->assertSame(['current' => 3, 'longest' => 3], $calculator->calculate($segments));
        $segments[] = ['start' => 1000003, 'end' => 1000003, 'count' => 1, 'failures' => 1] + $base;
        $this->assertSame(['current' => 0, 'longest' => 3], $calculator->calculate($segments));
        $segments[] = ['start' => 1000004, 'end' => 1000004, 'count' => 1] + $base;
        $segments[] = ['start' => 1000005, 'end' => 1000005, 'unavailable' => 1] + $base;
        $this->assertSame(['current' => 0, 'longest' => 3], $calculator->calculate($segments));
    }
}
