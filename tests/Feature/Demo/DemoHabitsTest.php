<?php

namespace Tests\Feature\Demo;

use App\Models\Category;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Tracking\TrackingTestCase;

class DemoHabitsTest extends TrackingTestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/habit-demo-test-'.bin2hex(random_bytes(8));
        app()->useStoragePath($this->directory);
        app()->instance('env', 'local');
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC'));
        $this->seed(CategorySeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_population_is_private_varied_idempotent_and_cleanup_preserves_existing_records_and_credentials(): void
    {
        $owner = User::factory()->create(['timezone' => 'Asia/Manila']);
        $foreign = $this->habit();
        $existing = $this->habit(user: $owner);
        $userBefore = DB::table('users')->orderBy('id')->get()->toJson();
        $categoriesBefore = DB::table('categories')->orderBy('id')->get()->toJson();
        $foreignBefore = $foreign->fresh()->getRawOriginal();
        $options = ['--email' => $owner->email];
        $this->assertSame(0, Artisan::call('demo:habits', $options));
        $demo = $owner->habits()->where('name', 'like', '[Demo] %')->get();
        $this->assertCount(10, $demo);
        $this->assertCount(5, $demo->pluck('type')->unique());
        $this->assertTrue($demo->every(fn ($habit) => $habit->visibility->value === 'private'));
        $count = DB::table('habit_entries')->count();
        $this->assertGreaterThan(2000, $count);
        $entriesBefore = DB::table('habit_entries')->orderBy('id')->get()->toJson();
        $this->assertSame(0, Artisan::call('demo:habits', $options));
        $this->assertSame($entriesBefore, DB::table('habit_entries')->orderBy('id')->get()->toJson());
        $this->assertSame(12, DB::table('habits')->count());
        $report = app(ActivityAnalytics::class)->report($owner, 2026);
        $this->assertGreaterThan(3, count(array_unique(array_column($report['cells'], 'band'))));
        $this->assertGreaterThan(0, $report['yearly']['percent']);
        $this->assertLessThan(100, $report['yearly']['percent']);
        $this->assertGreaterThan(0, $report['recent']['pending']);
        $walk = app(ActivityAnalytics::class)->report($owner, 2026, $demo->first()->id);
        $this->assertGreaterThanOrEqual(8, $walk['streaks']['current']);
        $this->assertGreaterThanOrEqual(14, $walk['streaks']['longest']);
        $this->assertSame(0, $walk['yearly']['unavailable']);
        foreach ($demo as $habit) {
            $this->actingAs($owner)->get('/habits/'.$habit->id.'/entries')->assertOk();
            $this->actingAs($owner)->get('/habits/'.$habit->id)->assertOk();
        }
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('[Demo] Morning walk');
        $this->get('/tracking?date=2026-10-31')->assertOk();
        // Cleanup must not discard an edit or a newly recorded day.
        $entry = DB::table('habit_entries')->first();
        DB::table('habit_entries')->where('id', $entry->id)->update(['note' => 'Keep my edit']);
        try {
            Artisan::call('demo:habits', $options + ['--cleanup' => true, '--yes' => true]);
            $this->fail('Cleanup accepted changed data.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Demo records changed', $e->getMessage());
        }
        $this->assertSame($count, DB::table('habit_entries')->count());
        DB::table('habit_entries')->where('id', $entry->id)->update(['note' => null]);
        $this->assertSame(0, Artisan::call('demo:habits', $options + ['--cleanup' => true, '--yes' => true]));
        $this->assertSame(0, DB::table('habit_entries')->count());
        $this->assertSame([$foreign->id, $existing->id], DB::table('habits')->orderBy('id')->pluck('id')->all());
        $this->assertSame($foreignBefore, $foreign->fresh()->getRawOriginal());
        $this->assertSame($userBefore, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($categoriesBefore, DB::table('categories')->orderBy('id')->get()->toJson());
    }

    public function test_production_missing_identity_and_partial_population_are_safe(): void
    {
        $owner = User::factory()->create();
        app()->instance('env', 'production');
        $this->assertSame(1, Artisan::call('demo:habits', ['--email' => $owner->email]));
        $this->assertSame(0, $owner->habits()->count());
        app()->instance('env', 'local');
        $this->assertSame(1, Artisan::call('demo:habits'));
        Category::where('name', 'Learning')->delete();
        try {
            Artisan::call('demo:habits', ['--email' => $owner->email]);
            $this->fail('Missing category should roll back the whole dataset.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, $owner->habits()->count());
            $this->assertSame(0, DB::table('habit_entries')->count());
            $this->assertSame([], glob($this->directory.'/app/private/demo-habits/*.json'));
        }
    }
}
