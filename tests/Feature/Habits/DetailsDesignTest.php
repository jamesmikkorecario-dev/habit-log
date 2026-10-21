<?php

namespace Tests\Feature\Habits;

use App\Actions\Habits\TransitionHabit;
use App\Livewire\ActivityHeatmap;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tracking\TrackingTestCase;

class DetailsDesignTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 11)->setTime(12, 0));
    }

    public static function configurations(): array
    {
        return [
            'yes/no' => ['boolean', [], 'Yes / No'],
            'count' => ['count', ['goal_value' => 20, 'goal_unit' => 'pages'], '20 pages'],
            'duration' => ['duration', ['goal_value' => 30], '30 minutes'],
            'checkpoints' => ['checkpoint', [], 'Reflect'],
            'avoidance zero' => ['avoidance', ['goal_value' => 0, 'goal_unit' => 'times'], '0 times'],
            'goal-free unit' => ['count', ['goal_unit' => 'pages'], 'Recorded in'],
        ];
    }

    #[DataProvider('configurations')]
    public function test_current_setup_and_actions_render_without_duplicate_requested_settings(string $type, array $values, string $expected): void
    {
        $habit = $this->habit($type, $values + ['description' => 'A useful description']);
        $before = $habit->versions()->with('checkpoints')->get()->toArray();
        $response = $this->actingAs($habit->user)->get(route('habits.show', $habit));
        $response->assertOk()->assertSee('~/habits/details')->assertSee('A useful description')
            ->assertSee('Current tracking setup')->assertSee('Habit information')->assertSeeText($expected)
            ->assertDontSee('Latest requested configuration')->assertDontSee('Upcoming tracking change')
            ->assertSee(route('entries.edit', ['habit' => $habit->id, 'date' => '2026-10-11']), false)
            ->assertSee(route('habits.edit', $habit), false)->assertSee(route('entries.index', $habit), false)
            ->assertSeeLivewire(ActivityHeatmap::class);
        $this->assertSame(1, substr_count($response->getContent(), 'Record today'));
        $this->assertSame($before, $habit->versions()->with('checkpoints')->get()->toArray());
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_no_entry_edit_updates_the_baseline_without_implying_a_pending_change(): void
    {
        $habit = $this->habit('count', ['goal_value' => 10, 'goal_unit' => 'pages']);
        $this->editConfiguration($habit, ['goal_value' => 20]);
        $this->actingAs($habit->user)->get(route('habits.show', $habit))
            ->assertOk()->assertSeeText('20 pages')->assertDontSeeText('10 pages')
            ->assertSee('Current tracking setup')->assertDontSee('Upcoming tracking change');
        $this->assertSame(1, $habit->versions()->count());
    }

    public function test_pending_change_shows_both_snapshots_and_local_effective_dates_then_becomes_current(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 11)->setTime(23, 30));
        $user = User::factory()->create(['timezone' => 'Asia/Manila']);
        $habit = $this->habit('checkpoint', [], $user);
        $version = $habit->versions()->sole();
        $this->editor($habit, '2026-10-12')->set('form.completed', [$version->checkpoints->first()->id])->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole()->toArray();
        $this->editConfiguration($habit, ['type' => 'avoidance', 'goal_value' => 0, 'goal_unit' => 'times', 'checkpoints' => []]);
        $this->actingAs($user)->get(route('habits.show', $habit))->assertOk()
            ->assertSeeTextInOrder(['Current tracking setup', 'Read', 'Reflect', 'Upcoming tracking change', '2026-10-13', '0 times'])
            ->assertSee('Asia/Manila')->assertSee('Earlier entries keep their original setup.')
            ->assertSee(route('entries.edit', ['habit' => $habit->id, 'date' => '2026-10-12']), false);
        $this->assertSame($entry, $habit->entries()->sole()->toArray());
        $this->travel(1)->days();
        $this->get(route('habits.show', $habit))->assertOk()->assertSee('Current tracking setup')
            ->assertSeeText('0 times')->assertDontSee('Upcoming tracking change')->assertDontSee('Reflect');
        $this->assertSame(['Read', 'Reflect'], $version->fresh()->checkpoints->pluck('label')->all());
        $this->assertSame($entry, $habit->entries()->sole()->toArray());
    }

    public function test_saved_zero_maximum_is_not_hidden_when_the_existing_version_differs(): void
    {
        // Model an inconsistent saved/version pair without making the current
        // synchronization bug a required behavior of this presentation test.
        $habit = $this->habit('avoidance', ['goal_unit' => 'times']);
        $habit->forceFill(['goal_value' => 0])->save();
        $this->assertNull($habit->versions()->sole()->goal_value);
        $this->assertSame(0, $habit->fresh()->goal_value);
        $this->actingAs($habit->user)->get(route('habits.show', $habit))->assertOk()
            ->assertSeeTextInOrder(['Current tracking setup', 'No numeric goal', 'Saved settings differ', '0 times'])
            ->assertSee('Daily entries continue to use their dated setup.')
            ->assertDontSee('Upcoming tracking change');
        $this->assertNull($habit->versions()->sole()->goal_value);
        $this->assertSame(0, $habit->fresh()->goal_value);
    }

    public function test_scheduled_initial_setup_is_not_presented_as_a_change_to_history(): void
    {
        $habit = $this->habit('checkpoint', ['start_date' => '2026-10-20']);
        $this->actingAs($habit->user)->get(route('habits.show', $habit))->assertOk()
            ->assertSee('Scheduled tracking setup')->assertSee('2026-10-20')->assertSee('Read')->assertSee('Reflect')
            ->assertSee('Tracking has not started yet.')->assertSee('Outside this habit’s active dates.')
            ->assertDontSee('Current tracking setup')->assertDontSee('Upcoming tracking change')->assertDontSee('Record today');
    }

    public function test_missing_effective_setup_preserves_saved_information_without_inventing_a_current_version(): void
    {
        $habit = Habit::factory()->create(['type' => 'count', 'goal_value' => 12, 'goal_unit' => 'pages']);
        $this->actingAs($habit->user)->get(route('habits.show', $habit))->assertOk()
            ->assertSee('Saved tracking settings')->assertSeeText('12 pages')
            ->assertSee('No effective tracking setup is available for today.')
            ->assertDontSee('Current tracking setup')->assertDontSee('Record today');
        $this->assertSame(0, $habit->versions()->count());
    }

    public function test_record_action_obeys_actual_archive_intervals_and_restore_preserves_archived_state(): void
    {
        $habit = $this->habit();
        $user = $habit->user;
        $transition = app(TransitionHabit::class);
        $transition->handle($user, $habit->id, 'archive');
        $this->actingAs($user)->get(route('habits.show', $habit))->assertOk()->assertSee('Archived')->assertSee('Record today');
        $this->travel(1)->days();
        $this->get(route('habits.show', $habit))->assertOk()->assertSee('Recording is unavailable today.')
            ->assertSee('This date falls in an archive or trash period.')->assertDontSee('Record today')
            ->assertSee(route('habits.edit', $habit), false)->assertSee(route('entries.index', $habit), false);
        $transition->handle($user, $habit->id, 'delete');
        $this->get(route('habits.show', $habit))->assertNotFound();
        $transition->handle($user, $habit->id, 'restore');
        $this->get(route('habits.show', $habit))->assertOk()->assertSee('Archived')->assertDontSee('Record today');
        $transition->handle($user, $habit->id, 'unarchive');
        $this->get(route('habits.show', $habit))->assertOk()->assertSee('Record today');
    }

    public function test_ended_habit_keeps_its_current_setup_without_a_record_today_action(): void
    {
        $habit = $this->habit('duration', ['end_date' => '2026-10-10']);
        $this->actingAs($habit->user)->get(route('habits.show', $habit))->assertOk()
            ->assertSee('Current tracking setup')->assertSee('2026-10-10')->assertSee('No numeric goal')
            ->assertSee('minutes')->assertSee('Outside this habit’s active dates.')->assertDontSee('Record today');
    }

    public function test_private_fields_are_not_rendered_and_foreign_and_guest_access_remain_denied(): void
    {
        $habit = $this->habit('boolean', ['name' => '<script>alert(1)</script>', 'description' => '<b>Plain description</b>', 'visibility' => 'public']);
        $this->editor($habit, '2026-10-11')->set('form.status', 'completed')->set('form.note', 'PRIVATE NOTE SENTINEL')->call('save')->assertHasNoErrors();
        $this->actingAs($habit->user)->get(route('habits.show', $habit))->assertOk()
            ->assertSee(e($habit->name), false)->assertSee(e($habit->description), false)
            ->assertDontSee($habit->name, false)->assertDontSee('PRIVATE NOTE SENTINEL');
        $this->actingAs(User::factory()->create())->get(route('habits.show', $habit))->assertNotFound();
        auth()->logout();
        $this->get(route('habits.show', $habit))->assertRedirect(route('login'));
    }
}
