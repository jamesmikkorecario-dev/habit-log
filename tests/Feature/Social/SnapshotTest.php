<?php

namespace Tests\Feature\Social;

use App\Actions\Habits\SaveHabitEntry;
use App\Models\Follow;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tracking\TrackingTestCase;

class SnapshotTest extends TrackingTestCase
{
    public static function revocations(): array
    {
        return [['follow'], ['visibility'], ['privacy']];
    }

    #[DataProvider('revocations')]
    public function test_authorized_report_keeps_one_snapshot_and_next_report_observes_revocation(string $change): void
    {
        $this->assertSame('habit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('habit_log_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $owner = User::factory()->create(['is_private' => $change === 'follow']);
        $viewer = User::factory()->create();
        $follow = $change === 'follow' ? Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]) : null;
        $habit = $this->habit(overrides: ['visibility' => 'public'], user: $owner);
        $version = $habit->versions()->sole();
        $date = $owner->localToday();
        app(SaveHabitEntry::class)->handle($owner, $habit->id, $date, ['status' => 'completed', 'completed' => []], null, $version->id, 1, 'UTC');
        config(['database.connections.social_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('social_writer');
        $this->assertSame('habit_log_test', $writer->selectOne('SELECT DATABASE() AS db')->db);
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $active = true;
        $changed = false;
        DB::listen(function ($query) use (&$active, &$changed, $writer, $change, $follow, $habit, $owner) {
            if ($active && ! $changed && $query->connectionName === 'mysql' && str_contains($query->sql, 'from `habit_entries`')) {
                $changed = true;
                if ($change === 'follow') {
                    $writer->table('follows')->where('id', $follow->id)->delete();
                } elseif ($change === 'visibility') {
                    $writer->table('habits')->where('id', $habit->id)->update(['visibility' => 'private']);
                } else {
                    $writer->table('users')->where('id', $owner->id)->update(['is_private' => true]);
                }
            }
        });
        try {
            $service = app(ActivityAnalytics::class);
            $report = $service->reportForViewer($viewer, $owner, (int) substr($date, 0, 4));
            $this->assertTrue($changed);
            $this->assertSame(1, $report['habit_count']);
            $this->assertSame(1.0, collect($report['cells'])->firstWhere('date', $date)['score']);
            if ($change === 'visibility') {
                $this->assertSame(0, $service->reportForViewer($viewer, $owner, (int) substr($date, 0, 4))['habit_count']);
            } else {
                try {
                    $service->reportForViewer($viewer, $owner, (int) substr($date, 0, 4));
                    $this->fail('Next report must be denied.');
                } catch (HttpException $e) {
                    $this->assertSame(404, $e->getStatusCode());
                }
            }
        } finally {
            $active = false;
            DB::purge('social_writer');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
