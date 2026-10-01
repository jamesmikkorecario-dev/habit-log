<?php

namespace Tests\Feature\Analytics;

use App\Actions\Habits\CancelTrackingChange;
use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Models\Habit;
use App\Services\Analytics\ActivityAnalytics;
use App\Services\TrackingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tracking\TrackingTestCase;

class AnalyticsTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));
    }

    private function log(Habit $habit, string $date, string $status = 'completed', ?int $value = null, array $completed = []): void
    {
        $version = app(TrackingDate::class)->version($habit, $date);
        app(SaveHabitEntry::class)->handle($habit->user, $habit->id, $date, ['status' => $status, 'value' => $value, 'note' => 'PRIVATE NOTE CANARY', 'not_applicable_reason' => $status === 'not_applicable' ? 'PRIVATE REASON CANARY' : null, 'completed' => $completed], null, $version->id, $version->revision, $habit->user->timezone);
    }

    private function report(Habit $habit, int $year = 2026, bool $profile = false): array
    {
        return app(ActivityAnalytics::class)->report($habit->user, $year, $profile ? null : $habit->id);
    }

    private function cell(array $report, string $date): array
    {
        return collect($report['cells'])->firstWhere('date', $date);
    }

    public function test_scores_missing_days_consistency_and_pending_without_writes_or_private_fields(): void
    {
        $habit = $this->habit('count', ['start_date' => '2026-09-28', 'goal_value' => 10]);
        $this->log($habit, '2026-09-28', 'missed', 10);
        $this->log($habit, '2026-09-29', 'completed', 5);
        $before = DB::table('habit_entries')->get()->toJson();
        DB::enableQueryLog();
        $report = $this->report($habit);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(50.0, $report['yearly']['percent']);
        $this->assertSame(3, $report['yearly']['days']);
        $this->assertSame(['current' => 0, 'longest' => 1], $report['streaks']);
        $this->assertSame('unlogged', $this->cell($report, '2026-09-30')['state']);
        $this->assertSame('pending', $this->cell($report, '2026-10-01')['state']);
        $this->assertSame('future', $this->cell($report, '2026-10-02')['state']);
        $this->assertSame('inactive', $this->cell($report, '2026-09-27')['state']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($report));
        $this->assertStringNotContainsString('note', json_encode($queries));
        $this->assertSame($before, DB::table('habit_entries')->get()->toJson());
        $this->assertCount(365, $report['cells']);
    }

    public function test_profile_averages_habits_then_dates_and_keeps_pending_from_extending_streaks(): void
    {
        $one = $this->habit('boolean', ['start_date' => '2026-09-30']);
        $two = $this->habit('count', ['start_date' => '2026-10-01', 'goal_value' => 10], $one->user);
        $this->log($one, '2026-09-30');
        $this->log($one, '2026-10-01');
        $pending = $this->report($one, profile: true);
        $this->assertSame(['current' => 1, 'longest' => 1], $pending['streaks']);
        $this->assertSame(1, $this->cell($pending, '2026-10-01')['pending']);
        $this->log($two, '2026-10-01', 'partial', 0);
        $report = $this->report($one, profile: true);
        $this->assertSame(0.5, $this->cell($report, '2026-10-01')['score']);
        $this->assertSame(75.0, $report['yearly']['percent']);
        $this->assertSame(0, $report['streaks']['current']);
    }

    public function test_na_and_archive_gaps_preserve_cross_year_history_and_trash_restores(): void
    {
        $habit = $this->habit('boolean', ['start_date' => '2025-12-30']);
        $this->log($habit, '2025-12-30');
        $this->log($habit, '2025-12-31', 'not_applicable');
        $this->log($habit, '2026-01-01');
        $this->travelTo(now()->setDate(2026, 1, 1));
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'archive');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $report = $this->report($habit);
        $this->assertSame(['current' => 2, 'longest' => 2], $report['streaks']);
        $this->assertSame(100.0, $report['yearly']['percent']);
        $this->assertNull($report['recent']['percent']);
        $this->assertSame(1, $this->report($habit, 2025)['yearly']['na']);
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'delete');
        $this->assertSame(0, $this->report($habit, profile: true)['habit_count']);
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'restore');
        $this->assertSame(2, $this->report($habit)['streaks']['current']);
    }

    public function test_history_uses_old_goals_and_checkpoint_snapshots(): void
    {
        $habit = $this->habit('count', ['goal_value' => 10, 'start_date' => '2026-09-30']);
        $this->travelTo(now()->setDate(2026, 9, 30));
        $this->log($habit, '2026-09-30', 'completed', 5);
        $this->editConfiguration($habit, ['goal_value' => 20]);
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->log($habit, '2026-10-01', 'completed', 5);
        $report = $this->report($habit);
        $this->assertSame(0.5, $this->cell($report, '2026-09-30')['score']);
        $this->assertSame(0.25, $this->cell($report, '2026-10-01')['score']);
        $this->assertSame('Completed', $this->cell($report, '2026-09-30')['assessment']);
        $check = $this->habit('checkpoint', ['start_date' => '2026-09-30']);
        $version = $check->versions()->first();
        $this->log($check, '2026-09-30', 'completed', null, [$version->checkpoints()->first()->id]);
        $this->editConfiguration($check, ['type' => 'boolean', 'checkpoints' => []]);
        $this->assertSame(0.5, $this->cell($this->report($check), '2026-09-30')['score']);
    }

    public function test_ancient_unlogged_spans_are_bounded_and_profile_includes_more_than_one_page(): void
    {
        $first = $this->habit('boolean', ['start_date' => '1000-01-01']);
        for ($i = 0; $i < 21; $i++) {
            $this->habit('boolean', ['start_date' => '1000-01-01'], $first->user);
        }
        $this->log($first, '2026-09-30');
        DB::enableQueryLog();
        $report = $this->report($first, profile: true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(22, $report['habit_count']);
        $this->assertEqualsWithDelta(1 / 22, $this->cell($report, '2026-09-30')['score'], 0.00000001);
        $this->assertLessThan(15, count($queries));
        $this->assertSame(0, $report['streaks']['longest']);
        $this->assertCount(365, $this->report($first, 1000)['cells']);
        $this->assertCount(366, $this->report($first, 2024)['cells']);
    }

    public function test_local_midnight_travel_dst_and_leap_year_do_not_move_entries(): void
    {
        $habit = $this->habit('boolean', ['start_date' => '2024-02-28']);
        $this->log($habit, '2024-02-29');
        $user = $habit->user;
        $user->forceFill(['timezone' => 'Asia/Manila'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 16:01:00', 'UTC'));
        $this->log($habit, '2026-10-01');
        $this->assertSame('2026-10-01', $this->report($habit)['today']);
        $user->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $report = $this->report($habit);
        $this->assertSame('2026-09-30', $report['today']);
        $this->assertSame('future', $this->cell($report, '2026-10-01')['state']);
        $this->assertSame(1.0, $this->cell($this->report($habit, 2024), '2024-02-29')['score']);
        foreach (['2026-03-08 10:01:00', '2026-11-01 09:01:00'] as $instant) {
            $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));
            $report = $this->report($habit);
            $this->assertSame(30, $report['recent']['days'] + $report['recent']['pending']);
            $this->assertSame(substr($instant, 0, 10), $report['today']);
        }
        $this->assertDatabaseHas('habit_entries', ['habit_id' => $habit->id, 'entry_date' => '2026-10-01']);
    }

    public function test_pending_becomes_past_failure_and_empty_na_windows_remain_neutral(): void
    {
        $habit = $this->habit('boolean', ['start_date' => '2026-09-30']);
        $this->log($habit, '2026-09-30');
        $this->assertSame(1, $this->report($habit)['streaks']['current']);
        $this->travelTo(now()->addDay());
        $this->assertSame(0, $this->report($habit)['streaks']['current']);
        $this->assertSame(0.0, $this->cell($this->report($habit), '2026-10-01')['score']);
        $na = $this->habit('boolean', ['start_date' => '2026-10-02']);
        $this->log($na, '2026-10-02', 'not_applicable');
        $report = $this->report($na);
        $this->assertNull($report['yearly']['percent']);
        $this->assertSame(1, $report['yearly']['na']);
        $this->assertSame(0, $report['yearly']['days']);
    }

    public function test_corrupt_checkpoint_rows_are_unavailable_without_hiding_missing_data(): void
    {
        $habit = $this->habit('checkpoint', ['start_date' => '2026-10-01']);
        $version = $habit->versions()->first();
        $this->log($habit, '2026-10-01', 'completed', null, $version->checkpoints()->pluck('id')->all());
        DB::table('habit_checkpoint_entries')->where('habit_entry_id', $habit->entries()->sole()->id)->limit(1)->delete();
        $report = $this->report($habit);
        $this->assertSame('unavailable', $this->cell($report, '2026-10-01')['state']);
        $this->assertNull($report['yearly']['percent']);
        $this->assertSame(1, $report['yearly']['unavailable']);
        $this->assertSame(0, $report['streaks']['current']);
    }

    public function test_date_edits_and_overlapping_empty_lifecycle_intervals_match_recording_eligibility(): void
    {
        $habit = $this->habit('boolean', ['start_date' => '2026-09-25']);
        $this->log($habit, '2026-09-25');
        $this->travelTo(now()->setDate(2026, 9, 26));
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'archive');
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'delete');
        $this->travelTo(now()->setDate(2026, 9, 28));
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'restore');
        $this->travelTo(now()->setDate(2026, 9, 29));
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'unarchive');
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'archive');
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'unarchive');
        $this->editConfiguration($habit, ['end_date' => '2026-09-30']);
        $this->travelTo(now()->setDate(2026, 10, 1));
        $report = $this->report($habit);
        foreach (['2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01'] as $date) {
            $eligible = app(TrackingDate::class)->reason($habit->user, $habit->fresh(), $date) === null;
            $this->assertSame($eligible, $this->cell($report, $date)['score'] !== null, $date);
        }
        $this->assertSame(1.0, $this->cell($report, '2026-09-25')['score']);
        $this->assertSame('inactive', $this->cell($report, '2026-10-01')['state']);
        $this->assertSame(4, $report['yearly']['days']);
    }

    public function test_pending_and_cancelled_versions_cannot_change_recorded_performance(): void
    {
        $habit = $this->habit('count', ['goal_value' => 10, 'start_date' => '2026-10-01']);
        $this->log($habit, '2026-10-01', 'completed', 5);
        $this->editConfiguration($habit, ['goal_value' => 20]);
        $this->assertSame(0.5, $this->cell($this->report($habit), '2026-10-01')['score']);
        app(CancelTrackingChange::class)->handle($habit->user, $habit->id);
        $this->travelTo(now()->addDay());
        $this->log($habit, '2026-10-02', 'completed', 5);
        $report = $this->report($habit);
        $this->assertSame(0.5, $this->cell($report, '2026-10-01')['score']);
        $this->assertSame(0.5, $this->cell($report, '2026-10-02')['score']);
        $this->assertSame(1, $habit->versions()->count());
    }

    public function test_supported_calendar_extremes_and_missing_baseline_are_safe(): void
    {
        $habit = $this->habit('boolean', ['start_date' => '1000-01-01']);
        $this->travelTo(CarbonImmutable::parse('9999-12-31 12:00:00', 'UTC'));
        $report = $this->report($habit, 9999);
        $this->assertCount(365, $report['cells']);
        $this->assertSame('9999-12-31', $report['cells'][364]['date']);
        $this->assertSame('pending', $report['cells'][364]['state']);
        $this->assertSame(29, $report['recent']['days']);
        $this->assertSame('1000-01-01', $this->report($habit, 1000)['cells'][0]['date']);
        $this->travelBack();
        $habit->versions()->delete();
        $broken = $this->report($habit);
        $this->assertSame('unavailable', $this->cell($broken, '2026-01-01')['state']);
        $this->assertNull($broken['yearly']['percent']);
        $this->assertSame(0, $broken['streaks']['longest']);
    }
}
