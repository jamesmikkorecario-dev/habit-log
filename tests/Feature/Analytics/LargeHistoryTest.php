<?php

namespace Tests\Feature\Analytics;

use App\Livewire\ActivityHeatmap;
use App\Models\HabitEntry;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class LargeHistoryTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_multiple_history_batches_preserve_exact_results_without_per_entry_models_or_checkpoint_queries_for_numeric_habits(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
        $start = '2021-10-05';
        $owner = User::factory()->create();
        foreach (['count', 'duration'] as $type) {
            $habit = $this->habit($type, ['start_date' => $start, 'goal_value' => 10], $owner);
            $version = $habit->versions()->sole();
            $rows = [];
            for ($day = CarbonImmutable::parse($start); $day->toDateString() < '2026-10-05'; $day = $day->addDay()) {
                $rows[] = ['habit_id' => $habit->id, 'user_id' => $owner->id, 'habit_tracking_version_id' => $version->id,
                    'entry_date' => $day->toDateString(), 'status' => 'completed', 'value' => $type === 'count' ? 10 : 5];
            }
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('habit_entries')->insert($batch);
            }
        }
        $before = DB::table('habit_entries')->count();
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.HabitEntry::class, function () use (&$hydrated) {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = app(ActivityAnalytics::class)->report($owner, 2024);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(0, $hydrated);
        $this->assertLessThanOrEqual(10, count($queries));
        $this->assertCount(0, array_filter($queries, fn ($query) => str_contains($query['query'], 'from `habit_checkpoint_entries`')));
        $this->assertStringNotContainsString('note', json_encode($queries));
        $this->assertSame(366, $report['yearly']['days']);
        $this->assertSame(75.0, $report['yearly']['percent']);
        $this->assertSame(75.0, $report['recent']['percent']);
        $this->assertSame(29, $report['recent']['days']);
        $this->assertSame(2, $report['recent']['pending']);
        $this->assertSame(['current' => 0, 'longest' => 0], $report['streaks']);
        foreach ($report['cells'] as $cell) {
            $this->assertSame(0.75, $cell['score']);
            $this->assertSame(2, $cell['count']);
            $this->assertSame('3', $cell['band']);
        }
        $this->assertSame($before, DB::table('habit_entries')->count());
    }

    public function test_year_arrows_build_only_one_fresh_report_and_keep_auth_and_local_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));
        $owner = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
        $this->habit(user: $owner);
        $page = Livewire::actingAs($owner)->test(ActivityHeatmap::class);
        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (str_starts_with($query->sql, 'select') && preg_match('/from `([^`]+)`/', $query->sql, $table) && $table[1] === 'habit_entries') {
                $reads++;
            }
        });
        $page->call('changeYear', -1)->assertSet('year', 2025)->assertSet('selectedDate', '');
        $this->assertSame(1, $reads);
        $owner->update(['timezone' => 'Asia/Manila']);
        $reads = 0;
        $page->call('changeYear', 0)->assertSet('year', 2027)->assertSet('yearInput', 2027);
        $this->assertSame(1, $reads);
        auth()->login(User::factory()->create());
        $page->call('changeYear', -1)->assertForbidden();
    }

    public function test_checkpoint_membership_and_missing_versions_stay_unavailable_across_batch_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
        $habit = $this->habit('checkpoint', ['start_date' => '2020-01-01']);
        $version = $habit->versions()->sole();
        $foreign = $this->habit('checkpoint', ['start_date' => '2020-01-01']);
        $foreignVersion = $foreign->versions()->sole();
        $rows = [];
        for ($i = 0; $i < 2100; $i++) {
            $rows[] = ['habit_id' => $habit->id, 'user_id' => $habit->user_id, 'habit_tracking_version_id' => $version->id,
                'entry_date' => CarbonImmutable::parse('2020-01-01')->addDays($i)->toDateString(), 'status' => 'completed'];
        }
        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('habit_entries')->insert($batch);
        }
        DB::statement('INSERT INTO habit_checkpoint_entries (habit_entry_id, habit_tracking_checkpoint_id, is_completed) SELECT e.id, c.id, 1 FROM habit_entries e JOIN habit_tracking_checkpoints c ON c.habit_tracking_version_id = e.habit_tracking_version_id WHERE e.habit_id = ?', [$habit->id]);
        $entries = DB::table('habit_entries')->where('habit_id', $habit->id)->orderBy('id')->get();
        $badMembership = $entries[1999];
        $badVersion = $entries[2000];
        DB::table('habit_checkpoint_entries')->where('habit_entry_id', $badMembership->id)->orderBy('id')->limit(1)
            ->update(['habit_tracking_checkpoint_id' => $foreignVersion->checkpoints()->first()->id]);
        DB::table('habit_entries')->where('id', $badVersion->id)->update(['habit_tracking_version_id' => $foreignVersion->id]);
        $report = app(ActivityAnalytics::class)->report($habit->user, 2025, $habit->id);
        foreach ([$badMembership, $badVersion] as $entry) {
            $cell = collect($report['cells'])->firstWhere('date', $entry->entry_date);
            $this->assertSame('unavailable', $cell['state']);
            $this->assertNull($cell['score']);
        }
        $this->assertNull(collect($report['cells'])->firstWhere('date', $badVersion->entry_date)['type']);
        $this->assertSame(1.0, collect($report['cells'])->firstWhere('date', $entries[2001]->entry_date)['score']);
        $this->assertSame(1999, $report['streaks']['longest']);
        $this->assertSame(2, $report['yearly']['unavailable']);
    }
}
