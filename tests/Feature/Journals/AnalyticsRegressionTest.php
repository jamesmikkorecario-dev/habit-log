<?php

namespace Tests\Feature\Journals;

use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Journals\DeleteJournal;
use App\Actions\Journals\SaveJournal;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tracking\TrackingTestCase;

class AnalyticsRegressionTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_journal_writes_never_change_activity_or_analytics(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(12, 0));
        $habit = $this->habit();
        $version = $habit->versions()->sole();
        $entry = app(SaveHabitEntry::class)->handle($habit->user, $habit->id, '2026-10-04', ['status' => 'completed', 'completed' => [], 'note' => 'Keep habit note'], null, $version->id, 1, 'UTC');
        $attributes = $entry->fresh()->getAttributes();
        $report = app(ActivityAnalytics::class)->report($habit->user, 2026);
        $journal = app(SaveJournal::class)->handle($habit->user, '2026-10-04', ['content' => 'Separate private journal', 'mood' => 1], null, null, 'UTC');
        $this->assertSame($report, app(ActivityAnalytics::class)->report($habit->user, 2026));
        app(DeleteJournal::class)->handle($habit->user, '2026-10-04', $journal->id, 1, 'UTC');
        $this->assertSame($report, app(ActivityAnalytics::class)->report($habit->user, 2026));
        $this->assertSame($attributes, $entry->fresh()->getAttributes());
    }
}
