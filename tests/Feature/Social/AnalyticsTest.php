<?php

namespace Tests\Feature\Social;

use App\Actions\Habits\SaveHabitEntry;
use App\Models\Follow;
use App\Models\Journal;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class AnalyticsTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_hidden_habits_never_contribute_to_shared_scores_counters_or_streaks(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(12, 0));
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
        $public = $this->habit(overrides: ['visibility' => 'public', 'start_date' => '2026-10-03'], user: $owner);
        $follower = $this->habit(overrides: ['visibility' => 'followers', 'start_date' => '2026-10-03'], user: $owner);
        $private = $this->habit(overrides: ['visibility' => 'private', 'start_date' => '2026-10-03'], user: $owner);
        foreach ([$public, $follower, $private] as $habit) {
            $version = $habit->versions()->sole();
            foreach (['2026-10-03', '2026-10-04'] as $date) {
                app(SaveHabitEntry::class)->handle($owner, $habit->id, $date, ['status' => $habit->id === $public->id ? 'completed' : 'missed', 'completed' => [], 'note' => 'PRIVATE-NOTE-CANARY', 'not_applicable_reason' => null], null, $version->id, 1, 'UTC');
            }
        }
        $service = app(ActivityAnalytics::class);
        $own = $service->report($owner, 2026);
        $shared = $service->reportForViewer($viewer, $owner, 2026);
        $this->assertSame(1, $shared['habit_count']);
        $this->assertSame(100.0, $shared['recent']['percent']);
        $this->assertSame(2, $shared['streaks']['current']);
        $this->assertSame('UTC', $shared['timezone']);
        foreach ($shared['cells'] as $cell) {
            $this->assertNull($cell['link']);
        }
        $this->assertNotEquals($own['recent']['percent'], $shared['recent']['percent']);
        Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
        $shared = $service->reportForViewer($viewer, $owner, 2026);
        $this->assertSame(2, $shared['habit_count']);
        $this->assertSame(50.0, $shared['recent']['percent']);
        $this->assertSame(0, $shared['streaks']['current']);
        $this->assertSame($own, $service->report($owner, 2026));
        $this->assertStringNotContainsString('PRIVATE-NOTE-CANARY', json_encode($shared));
        $public->delete();
        $follower->delete();
        $empty = $service->reportForViewer($viewer, $owner, 2026);
        $this->assertSame(0, $empty['habit_count']);
        $this->assertNull($empty['recent']['percent']);
    }

    public function test_shared_details_exclude_private_fields_and_summary_includes_all_visible_pages(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(12, 0));
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create();
        $habit = $this->habit('count', ['visibility' => 'public', 'goal_value' => 10], $owner);
        $version = $habit->versions()->sole();
        app(SaveHabitEntry::class)->handle($owner, $habit->id, '2026-10-04', ['status' => 'partial', 'value' => 5, 'completed' => [], 'note' => 'NOTE-SECRET', 'not_applicable_reason' => null], null, $version->id, 1, 'UTC');
        Journal::factory()->for($owner)->create(['content' => 'JOURNAL-SECRET']);
        $habit->category->update(['description' => 'CATEGORY-SECRET']);
        $report = app(ActivityAnalytics::class)->reportForViewer($viewer, $owner, 2026, $habit->id);
        $cell = collect($report['cells'])->firstWhere('date', '2026-10-04');
        $this->assertSame(0.5, $cell['score']);
        $this->assertSame(5, $cell['actual']);
        $this->assertSame(10, $cell['goal']);
        $this->assertNull($cell['link']);
        $this->actingAs($viewer)->get(route('people.habit', ['user' => $owner->username, 'habit' => $habit->id]))->assertOk()->assertDontSee('NOTE-SECRET')->assertDontSee('JOURNAL-SECRET')->assertDontSee('CATEGORY-SECRET');
        for ($i = 0; $i < 21; $i++) {
            $this->habit(overrides: ['visibility' => 'public'], user: $owner);
        }
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner])->assertSee('22 visible habits');
        $page->call('nextPage')->assertSee('22 visible habits');
        $this->assertSame(22, app(ActivityAnalytics::class)->reportForViewer($viewer, $owner, 2026)['habit_count']);
    }

    public function test_pending_configuration_is_not_disclosed_and_future_effective_setup_appears_on_its_day(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(12, 0));
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create();
        $habit = $this->habit('count', ['visibility' => 'public', 'goal_value' => 10, 'goal_unit' => 'CURRENT-UNIT'], $owner);
        $version = $habit->versions()->sole();
        app(SaveHabitEntry::class)->handle($owner, $habit->id, '2026-10-04', ['status' => 'partial', 'value' => 5, 'completed' => []], null, $version->id, 1, 'UTC');
        $this->editConfiguration($habit, ['goal_value' => 20, 'goal_unit' => 'PENDING-UNIT-CANARY']);
        $url = route('people.habit', ['user' => $owner->username, 'habit' => $habit->id]);
        $this->actingAs($viewer)->get($url)->assertOk()->assertSee('CURRENT-UNIT')->assertDontSee('PENDING-UNIT-CANARY');
        $this->travel(1)->days();
        $this->get($url)->assertOk()->assertSee('PENDING-UNIT-CANARY');
    }
}
