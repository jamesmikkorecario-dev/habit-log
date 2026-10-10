<?php

namespace Tests\Feature;

use App\Actions\Habits\TransitionHabit;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class DashboardTodayTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 27)->setTime(12, 0));
    }

    public function test_new_account_is_distinct_from_trash_and_inactive_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Your journey starts here.')
            ->assertSee('Create your first habit')->assertSee('Progress overview')->assertSee('Activity history')
            ->assertDontSee('Explore your progress and activity history');
        $habit = $this->habit(user: $user);
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        app(TransitionHabit::class)->handle($user, $habit->id, 'archive');
        // Archive starts tomorrow, not immediately.
        $this->get('/dashboard')->assertSee('Archived; today is still eligible')->assertSee('logged today');
        $this->travel(1)->days();
        $this->get('/dashboard')->assertSee('A little breathing room.')->assertSee('Activity history')->assertDontSee('Your journey starts here.');
        app(TransitionHabit::class)->handle($user, $habit->id, 'delete');
        $this->get('/dashboard')->assertSee('Your habits are in Trash.')->assertSee('Review Trash')->assertDontSee('Your journey starts here.');
    }

    public function test_today_uses_historical_setup_all_types_and_never_discloses_private_notes(): void
    {
        $user = User::factory()->create();
        $boolean = $this->habit('boolean', ['name' => 'Read'], $user);
        $count = $this->habit('count', ['name' => 'Pages', 'goal_value' => 10, 'goal_unit' => 'pages'], $user);
        $duration = $this->habit('duration', ['name' => 'Walk', 'goal_value' => 30], $user);
        $checkpoint = $this->habit('checkpoint', ['name' => 'Routine'], $user);
        $avoidance = $this->habit('avoidance', ['name' => 'Limit caffeine', 'goal_value' => 0], $user);
        $na = $this->habit('count', ['name' => 'Rest day'], $user);
        $this->editor($boolean)->set('form.status', 'completed')->set('form.note', 'PRIVATE-NOTE-CANARY')->call('save')->assertHasNoErrors();
        $this->editor($count)->set('form.status', 'completed')->set('form.value', 5)->call('save')->assertHasNoErrors();
        $this->editConfiguration($count, ['goal_value' => 20]);
        $this->editor($checkpoint)->set('form.completed', [$checkpoint->versions()->first()->checkpoints()->first()->id])->call('save')->assertHasNoErrors();
        $this->editor($avoidance)->set('form.status', 'completed')->set('form.value', 0)->call('save')->assertHasNoErrors();
        $this->editor($na)->set('form.status', 'not_applicable')->set('form.not_applicable_reason', 'PRIVATE-REASON-CANARY')->call('save')->assertHasNoErrors();
        $before = app(ActivityAnalytics::class)->report($user, 2026);
        $page = Livewire::actingAs($user)->test('pages::dashboard')->assertSee("Today's habits", false)
            ->assertSee('Yes / No')->assertSee('Count')->assertSee('Duration')->assertSee('Checkpoints')->assertSee('Avoidance')
            ->assertSee('Not logged')->assertSee('Not applicable')->assertSee('1 of 2 checkpoints')
            ->assertSee('Goal 10')->assertDontSee('Goal 20')->assertSee('Limit 0')
            ->assertDontSee('PRIVATE-NOTE-CANARY')->assertDontSee('PRIVATE-REASON-CANARY');
        $summary = $page->instance()->today;
        $this->assertSame(6, $summary['eligible']);
        $this->assertSame(5, $summary['logged']);
        $this->assertSame($before, app(ActivityAnalytics::class)->report($user, 2026));
        $this->assertDatabaseCount('habit_entries', 5);
    }

    public function test_summary_is_owner_scoped_bounded_and_uses_fresh_timezone_and_eligibility(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(0, 30));
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $this->habit('boolean', ['name' => 'PRIVATE-OTHER-HABIT']);
        foreach (range(1, 8) as $i) {
            $this->habit('boolean', ['name' => 'Eligible '.$i], $user);
        }
        $this->habit('boolean', ['name' => 'Future', 'start_date' => '2026-09-28'], $user);
        $this->habit('boolean', ['name' => 'Ended', 'end_date' => '2026-09-25'], $user);
        $page = Livewire::actingAs($user)->test('pages::dashboard')->assertDontSee('PRIVATE-OTHER-HABIT')->assertSee('Showing 6 of 8 eligible habits');
        $summary = $page->instance()->today;
        $this->assertSame('2026-09-26', $summary['date']);
        $this->assertCount(6, $summary['rows']);
        $this->assertSame(0, $summary['logged']);
        $this->assertSame(10, $summary['included']);
        $user->forceFill(['timezone' => 'Asia/Manila'])->save();
        $page->call('$refresh')->assertSee('Asia/Manila');
        $this->assertSame('2026-09-27', $page->instance()->today['date']);
    }
}
