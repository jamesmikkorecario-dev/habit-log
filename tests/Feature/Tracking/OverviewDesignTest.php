<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\TransitionHabit;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class OverviewDesignTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(12, 0));
    }

    public function test_onboarding_trash_inactive_and_empty_pagination_have_distinct_guidance(): void
    {
        $user = User::factory()->create();
        $page = Livewire::actingAs($user)->test('pages::tracking.index')
            ->assertSee('Nothing to track just yet.')->assertSee('Create your first habit');
        $habit = $this->habit(overrides: ['start_date' => '2026-09-28'], user: $user);
        $page->call('$refresh')->assertDontSee('Create your first habit')->assertSee('No habits on this page can be recorded')
            ->assertSee('Outside this habit’s active dates.')->assertDontSee('Record activity for');
        $page->call('setPage', 2)->assertSee('No habits on this page.')->assertSee('Back to first page');
        $page->call('setPage', 1)->assertSee('Your habits');
        app(TransitionHabit::class)->handle($user, $habit->id, 'delete');
        $page->call('$refresh')->assertSee('Your habits are in Trash.')->assertSee('Review Trash')->assertDontSee('Create your first habit');
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_all_types_show_recorded_assessment_historical_values_and_checkpoint_counts_without_private_text(): void
    {
        $user = User::factory()->create();
        $boolean = $this->habit('boolean', ['name' => 'Boolean habit'], $user);
        $count = $this->habit('count', ['name' => 'Count habit', 'goal_value' => 10, 'goal_unit' => 'pages'], $user);
        $duration = $this->habit('duration', ['name' => 'Duration habit', 'goal_value' => 30], $user);
        $checkpoint = $this->habit('checkpoint', ['name' => 'Checkpoint habit'], $user);
        $avoidance = $this->habit('avoidance', ['name' => 'Avoidance habit', 'goal_value' => 0], $user);
        $na = $this->habit('count', ['name' => 'Rest habit'], $user);
        $this->editor($boolean)->set('form.status', 'missed')->set('form.note', 'PRIVATE-NOTE-CANARY')->call('save')->assertHasNoErrors();
        $this->editor($count)->set('form.status', 'completed')->set('form.value', 5)->call('save')->assertHasNoErrors();
        $this->editConfiguration($count, ['goal_value' => 20]);
        $this->editor($duration)->set('form.status', 'partial')->set('form.value', 15)->call('save')->assertHasNoErrors();
        $this->editor($checkpoint)->set('form.completed', [$checkpoint->versions()->first()->checkpoints()->first()->id])->call('save')->assertHasNoErrors();
        $this->editor($avoidance)->set('form.status', 'completed')->set('form.value', 0)->call('save')->assertHasNoErrors();
        $this->editor($na)->set('form.status', 'completed')->set('form.value', 876543)->call('save')->assertHasNoErrors();
        $this->editor($na)->set('form.status', 'not_applicable')->set('form.not_applicable_reason', 'PRIVATE-REASON-CANARY')->call('save')->assertHasNoErrors();
        $this->habit(overrides: ['name' => 'Awaiting a check-in'], user: $user);
        $before = app(ActivityAnalytics::class)->report($user, 2026);
        DB::enableQueryLog();
        $page = Livewire::actingAs($user)->test('pages::tracking.index')
            ->assertSee('Yes / No')->assertSee('Count')->assertSee('Duration')->assertSee('Checkpoints')->assertSee('Avoidance')
            ->assertSee('Recorded outcome: Completed')->assertSee('Recorded outcome: Partial')->assertSee('Recorded outcome: Missed')
            ->assertSee('Recorded outcome: Not applicable')->assertSee('Not logged · Pending today')
            ->assertSee('5 pages')->assertSee('Goal 10')->assertDontSee('Goal 20')->assertSee('15 minutes')->assertSee('1 of 2 checkpoints')->assertSee('Limit 0')
            ->assertDontSee('876543')->assertDontSee('PRIVATE-NOTE-CANARY')->assertDontSee('PRIVATE-REASON-CANARY');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertStringNotContainsString('`note`', json_encode($queries));
        $this->assertSame(7, $page->instance()->overview['eligible']);
        $this->assertSame(6, $page->instance()->overview['logged']);
        $this->assertSame(0, $page->instance()->overview['unavailable']);
        $this->assertSame($before, app(ActivityAnalytics::class)->report($user, 2026));
        $this->assertDatabaseCount('habit_entries', 6);
    }

    public function test_counts_are_page_scoped_owner_only_and_archive_keeps_historical_access(): void
    {
        $user = User::factory()->create();
        $first = null;
        for ($i = 0; $i < 21; $i++) {
            $habit = $this->habit(overrides: ['name' => sprintf('Habit %02d', $i)], user: $user);
            $first ??= $habit;
        }
        $this->habit(overrides: ['name' => 'PRIVATE-OTHER-HABIT']);
        $this->editor($first)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        app(TransitionHabit::class)->handle($user, $first->id, 'archive');
        $page = Livewire::actingAs($user)->test('pages::tracking.index')->assertSee('On this page')->assertSee('Archived')->assertDontSee('PRIVATE-OTHER-HABIT');
        $this->assertSame(20, $page->instance()->overview['eligible']);
        $this->assertSame(1, $page->instance()->overview['logged']);
        $page->call('setPage', 2);
        $this->assertSame(1, $page->instance()->overview['eligible']);
        $this->assertSame(0, $page->instance()->overview['logged']);
        $this->travel(1)->days();
        $page->call('today')->assertSee('This date falls in an archive or trash period.');
        $this->assertSame(1, $page->instance()->overview['unavailable']);
        $page->set('selectedDate', '2026-09-27')->call('goToDate')->assertSee('Edit entry for Habit 00');
        $this->assertSame(1, $page->instance()->overview['logged']);
    }

    public function test_date_confirmation_boundaries_and_fresh_timezone_preserve_navigation_semantics(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(0, 30));
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $habit = $this->habit(user: $user);
        $page = Livewire::actingAs($user)->test('pages::tracking.index')->assertSet('date', '2026-09-26')->assertSee('America/Los_Angeles');
        $page->set('selectedDate', '2024-02-29')->assertSet('date', '2026-09-26');
        $page->call('goToDate')->assertSet('date', '2024-02-29')->assertSee('Thursday, February 29, 2024');
        $page->call('shiftDate', 1)->assertSet('date', '2024-03-01');
        $page->call('shiftDate', -1)->assertSet('date', '2024-02-29');
        $page->set('selectedDate', 'invalid')->call('goToDate')->assertHasErrors('date')->assertSet('date', '2024-02-29');
        $page->call('today')->assertSet('date', '2026-09-26');
        $page->call('shiftDate', 1)->assertSet('date', '2026-09-26');
        $user->forceFill(['timezone' => 'Asia/Manila'])->save();
        $page->call('today')->assertSet('date', '2026-09-27')->assertSee('Asia/Manila');
        $this->assertSame('2026-09-27', $page->instance()->overview['today']);
        $page->set('selectedDate', '1000-01-01')->call('goToDate')->assertSet('date', '1000-01-01');
        // Existing future URLs remain readable, but no recording action is offered.
        $this->actingAs($user)->get('/tracking?date=2026-09-28')->assertOk()
            ->assertSee('Future dates cannot be recorded.')->assertDontSee('Record activity for');
        $this->get('/tracking?date=invalid')->assertNotFound();
        $this->assertDatabaseCount('habit_entries', 0);
    }
}
