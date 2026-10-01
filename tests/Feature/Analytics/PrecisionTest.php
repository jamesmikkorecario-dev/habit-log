<?php

namespace Tests\Feature\Analytics;

use App\Actions\Habits\SaveHabitEntry;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tracking\TrackingTestCase;

class PrecisionTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_small_positive_profile_score_is_not_displayed_as_zero(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));
        $habit = $this->habit('count', ['start_date' => '2026-09-29', 'goal_value' => 999999999]);
        for ($i = 0; $i < 10; $i++) {
            $this->habit('boolean', ['start_date' => '2026-09-29'], $habit->user);
        }
        $version = $habit->versions()->first();
        app(SaveHabitEntry::class)->handle($habit->user, $habit->id, '2026-09-29', [
            'status' => 'partial', 'value' => 1, 'note' => null, 'not_applicable_reason' => null, 'completed' => [],
        ], null, $version->id, 1, 'UTC');
        $report = app(ActivityAnalytics::class)->report($habit->user, 2026);
        $positive = collect($report['cells'])->firstWhere('date', '2026-09-29');
        $zero = collect($report['cells'])->firstWhere('date', '2026-09-30');
        $this->assertGreaterThan(0, $positive['score']);
        $this->assertLessThan(0.0000000001, $positive['score']);
        $this->assertSame('1', $positive['band']);
        $this->assertFalse($positive['success']);
        $this->assertSame(0.0, $zero['score']);
        $this->assertSame('0', $zero['band']);
    }
}
