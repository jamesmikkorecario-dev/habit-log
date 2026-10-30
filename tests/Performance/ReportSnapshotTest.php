<?php

namespace Tests\Performance;

use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Read-only comparison against the synthetic fixture left by PerformanceTest. */
class ReportSnapshotTest extends TestCase
{
    public function test_benchmark_reports_match_the_pre_optimization_snapshot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
        $owner = User::where('username', 'performance_owner')->sole();
        $viewer = User::where('username', 'performance_viewer')->sole();
        $this->assertSame(100, $owner->habits()->withTrashed()->count());
        $reports = [];
        $service = app(ActivityAnalytics::class);
        foreach ([2024, 2025, 2026] as $year) {
            $reports['owner_'.$year] = $service->report($owner, $year);
            $reports['shared_'.$year] = $service->reportForViewer($viewer, $owner, $year);
        }
        foreach ($owner->habits()->orderBy('id')->limit(6)->get() as $index => $habit) {
            $reports['habit_'.$index] = $service->report($owner, 2026, $habit->id);
        }
        $file = storage_path('app/dashboard-performance/reference-reports.json');
        $encoded = json_encode($reports, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (getenv('HABIT_LOG_CAPTURE_REFERENCE') === '1') {
            $this->assertFileDoesNotExist($file, 'Never overwrite the before-optimization reference.');
            file_put_contents($file, $encoded);
        } else {
            $this->assertFileExists($file, 'Capture the baseline before changing analytics.');
            $this->assertSame(file_get_contents($file), $encoded, 'Every field, number, label, link and streak must match byte-for-byte.');
        }
    }
}
