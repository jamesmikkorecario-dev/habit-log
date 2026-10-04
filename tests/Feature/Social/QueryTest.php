<?php

namespace Tests\Feature\Social;

use App\Actions\Habits\SaveHabitEntry;
use App\Models\Follow;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tracking\TrackingTestCase;

class QueryTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_mixed_visibility_queries_remain_bounded_across_list_and_history_batches(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(12, 0));
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
        $before = $this->measure($viewer, $owner);
        for ($i = 0; $i < 84; $i++) {
            $habit = $this->habit(overrides: ['visibility' => ['private', 'followers', 'public'][$i % 3], 'start_date' => '2026-10-01'], user: $owner);
            $version = $habit->versions()->sole();
            foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
                app(SaveHabitEntry::class)->handle($owner, $habit->id, $date, ['status' => 'completed', 'completed' => []], null, $version->id, 1, 'UTC');
            }
        }
        foreach (User::factory()->count(25)->create() as $person) {
            Follow::factory()->accepted()->create(['follower_id' => $person->id, 'followed_id' => $owner->id]);
            Follow::factory()->create(['follower_id' => $owner->id, 'followed_id' => $person->id]);
        }
        $after = $this->measure($viewer, $owner);
        $this->assertSame(56, $after['visible_habits']);
        $this->assertSame(100.0, $after['recent_percent']);
        // A larger report adds batch queries, never one follow check per habit/cell.
        $this->assertLessThanOrEqual($before['query_count'] + 12, $after['query_count']);
        $this->assertLessThanOrEqual(3, $after['follow_queries']);
        $plans = [
            'incoming' => DB::select('EXPLAIN SELECT id, follower_id FROM follows WHERE followed_id = ? AND status = ? ORDER BY id DESC LIMIT 20', [$owner->id, 'accepted']),
            'outgoing' => DB::select('EXPLAIN SELECT id, followed_id FROM follows WHERE follower_id = ? AND status = ? ORDER BY id DESC LIMIT 20', [$owner->id, 'pending']),
            'habits' => DB::select('EXPLAIN SELECT id FROM habits WHERE user_id = ? AND deleted_at IS NULL AND visibility IN (?, ?) ORDER BY id DESC LIMIT 20', [$owner->id, 'public', 'followers']),
        ];
        $lists = ['incoming' => $this->measureList($owner, 'followed_id', 'follower', 'accepted'), 'outgoing' => $this->measureList($owner, 'follower_id', 'followed', 'pending')];
        $this->assertSame(26, $lists['incoming']['total']);
        $this->assertSame(25, $lists['outgoing']['total']);
        foreach ($lists as $list) {
            $this->assertSame(20, $list['page_rows']);
            $this->assertLessThanOrEqual(3, $list['query_count']);
        }
        $directory = storage_path('app/phase6-checks');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/queries.json', json_encode(['habits' => 84, 'entries' => 252, 'relationships' => 51, 'empty_report' => $before, 'populated_report' => $after, 'lists' => $lists, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function measureList(User $owner, string $key, string $relation, string $status): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = microtime(true);
        try {
            $rows = Follow::where($key, $owner->id)->where('status', $status)->with($relation.':id,username,display_name')->orderByDesc('id')->paginate(20);

            return ['query_count' => count(DB::getQueryLog()), 'elapsed_ms' => round((microtime(true) - $start) * 1000, 2), 'page_rows' => $rows->count(), 'total' => $rows->total()];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function measure(User $viewer, User $subject): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = microtime(true);
        try {
            $report = app(ActivityAnalytics::class)->reportForViewer($viewer, $subject, 2026);
            $queries = DB::getQueryLog();

            return ['query_count' => count($queries), 'follow_queries' => count(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `follows`'))), 'elapsed_ms' => round((microtime(true) - $start) * 1000, 2), 'visible_habits' => $report['habit_count'], 'recent_percent' => $report['recent']['percent']];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
