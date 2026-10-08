<?php

namespace Tests\Performance;

use App\Livewire\ActivityHeatmap;
use App\Models\Follow;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

// Deliberately outside the default suite: this is a repeatable, larger measurement.
class PerformanceTest extends TrackingTestCase
{
    public function test_representative_history_and_request_measurements(): void
    {
        $this->assertSame('habit_log_test', config('database.connections.mysql.database'));
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
        Testable::macro('measuredResponse', function () {
            return $this->lastState->getResponse()->getContent();
        });
        $results = ['php' => PHP_VERSION, 'mysql' => DB::selectOne('SELECT VERSION() AS version')->version, 'scenarios' => []];
        foreach ([[10, 1], [100, 5]] as [$count, $years]) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            $owner = User::factory()->create(['username' => 'performance_owner']);
            $viewer = User::factory()->create(['username' => 'performance_viewer']);
            Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
            $start = CarbonImmutable::now('UTC')->subYears($years)->startOfDay();
            $last = CarbonImmutable::now('UTC')->subDay()->startOfDay();
            $habits = [];
            for ($i = 0; $i < $count; $i++) {
                $type = ['boolean', 'count', 'duration', 'checkpoint', 'avoidance'][$i % 5];
                $habit = $this->habit($type, ['start_date' => $start->toDateString(), 'visibility' => ['private', 'followers', 'public'][$i % 3], 'goal_value' => in_array($type, ['count', 'duration', 'avoidance']) ? 10 : null], $owner);
                $habits[] = $habit;
                $version = $habit->versions()->sole();
                $later = $version->replicate();
                $later->effective_date = '2026-01-01';
                $later->revision = 2;
                $later->save();
                foreach ($version->checkpoints as $checkpoint) {
                    $copy = $checkpoint->replicate();
                    $copy->habit_tracking_version_id = $later->id;
                    $copy->save();
                }
                $rows = [];
                for ($date = $start; $date <= $last; $date = $date->addDays($count === 100 && $i % 2 ? 30 : 1)) {
                    $rows[] = ['habit_id' => $habit->id, 'user_id' => $owner->id, 'habit_tracking_version_id' => $date->year >= 2026 ? $later->id : $version->id,
                        'entry_date' => $date->toDateString(), 'status' => $date->day % 11 === 0 ? 'not_applicable' : ($date->day % 7 === 0 ? 'missed' : 'completed'),
                        'value' => in_array($type, ['count', 'duration', 'avoidance']) ? 5 : null, 'created_at' => now(), 'updated_at' => now()];
                    if (count($rows) === 500) {
                        DB::table('habit_entries')->insert($rows);
                        $rows = [];
                    }
                }
                if ($rows) {
                    DB::table('habit_entries')->insert($rows);
                }
                if ($type === 'checkpoint') {
                    DB::statement('INSERT INTO habit_checkpoint_entries (habit_entry_id, habit_tracking_checkpoint_id, is_completed, created_at, updated_at) SELECT e.id, c.id, 1, NOW(), NOW() FROM habit_entries e JOIN habit_tracking_checkpoints c ON c.habit_tracking_version_id = e.habit_tracking_version_id WHERE e.habit_id = ?', [$habit->id]);
                }
                if ($i % 10 === 0) {
                    $habit->suspensions()->make()->forceFill(['reason' => 'archive', 'start_date' => '2026-03-01', 'end_date' => '2026-04-01'])->save();
                    // Stored records may predate later lifecycle changes; analytics excludes gaps.
                }
                if ($i === 0) {
                    $habit->forceFill(['archived_at' => now()])->save();
                } elseif ($i === 1) {
                    $habit->delete();
                }
            }
            if ($count === 100) {
                foreach (User::factory()->count(500)->create() as $i => $person) {
                    Follow::factory()->create(['follower_id' => $owner->id, 'followed_id' => $person->id, 'status' => $i % 2 ? 'pending' : 'accepted', 'accepted_at' => $i % 2 ? null : now()]);
                    Follow::factory()->create(['follower_id' => $person->id, 'followed_id' => $owner->id, 'status' => $i % 2 ? 'pending' : 'accepted', 'accepted_at' => $i % 2 ? null : now()]);
                }
            }
            $scenario = ['habits' => $count, 'years' => $years, 'entries' => DB::table('habit_entries')->count(), 'checkpoint_entries' => DB::table('habit_checkpoint_entries')->count(), 'versions' => DB::table('habit_tracking_versions')->count(), 'relationships' => Follow::count(), 'measurements' => []];
            $habit = $habits[2];
            $measures = [
                'owner_report' => fn () => json_encode(app(ActivityAnalytics::class)->report($owner, 2026)),
                'shared_report' => fn () => json_encode(app(ActivityAnalytics::class)->reportForViewer($viewer, $owner, 2026)),
                'dashboard' => fn () => $this->actingAs($owner)->get('/dashboard')->assertOk()->getContent(),
                'habit' => fn () => $this->actingAs($owner)->get('/habits/'.$habit->id)->assertOk()->getContent(),
                'shared_profile' => fn () => $this->actingAs($viewer)->get('/people/performance_owner')->assertOk()->getContent(),
                'shared_habit' => fn () => $this->actingAs($viewer)->get('/people/performance_owner/habits/'.$habit->id)->assertOk()->getContent(),
                'tracking' => fn () => $this->actingAs($owner)->get('/tracking?date=2026-10-04')->assertOk()->getContent(),
                'history' => fn () => $this->actingAs($owner)->get('/habits/'.$habit->id.'/entries')->assertOk()->getContent(),
                'habits' => fn () => $this->actingAs($owner)->get('/habits')->assertOk()->getContent(),
                'categories' => fn () => $this->actingAs($owner)->get('/categories')->assertOk()->getContent(),
            ];
            foreach ($measures as $name => $run) {
                $scenario['measurements'][$name] = $this->measure($run);
                fwrite(STDERR, "Measured {$count} habits: {$name}\n");
            }
            foreach (['following', 'followers', 'incoming', 'sent'] as $tab) {
                $component = Livewire::actingAs($owner)->test('pages::people.index');
                $scenario['measurements']['relationships_'.$tab] = $this->measure(fn () => $component->call('setTab', $tab)->assertOk()->measuredResponse());
            }
            $component = Livewire::actingAs($owner)->test(ActivityHeatmap::class);
            $scenario['measurements']['date_details'] = $this->measure(fn () => $component->call('selectDay', '2026-10-04')->assertOk()->measuredResponse());
            $component->set('yearInput', 2025);
            $scenario['measurements']['year_change'] = $this->measure(fn () => $component->call('goToYear')->assertOk()->measuredResponse());
            $scenario['plans'] = [
                'entries' => DB::select('EXPLAIN SELECT id, status FROM habit_entries WHERE habit_id = ? AND id > 0 ORDER BY id LIMIT 500', [$habit->id]),
                'incoming' => DB::select('EXPLAIN SELECT id FROM follows WHERE followed_id = ? AND status = ? ORDER BY id DESC LIMIT 20', [$owner->id, 'accepted']),
                'outgoing' => DB::select('EXPLAIN SELECT id FROM follows WHERE follower_id = ? AND status = ? ORDER BY id DESC LIMIT 20', [$owner->id, 'pending']),
            ];
            $results['scenarios'][] = $scenario;
            file_put_contents(storage_path('app/phase7-checks/performance.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    private function measure(callable $run): array
    {
        $samples = [];
        for ($i = 0; $i < 11; $i++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $baseMemory = memory_get_usage(true);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $start = hrtime(true);
            try {
                $content = $run();
                $samples[] = ['ms' => round((hrtime(true) - $start) / 1e6, 2), 'queries' => count(DB::getQueryLog()), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2), 'additional_peak_mb' => round((memory_get_peak_usage(true) - $baseMemory) / 1048576, 2), 'bytes' => strlen($content)];
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
        $cold = array_shift($samples);
        $times = array_column($samples, 'ms');
        sort($times);

        return ['cold' => $cold, 'warm_samples' => $samples, 'median_ms' => round(($times[4] + $times[5]) / 2, 2), 'p95_ms' => $times[9]];
    }
}
