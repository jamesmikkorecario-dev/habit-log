<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\TransitionHabit;
use App\Models\Habit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

class EditorDesignTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 11)->setTime(12, 0));
    }

    public static function types(): array
    {
        return [
            'boolean' => ['boolean', 'Yes / No', ''],
            'count' => ['count', 'Actual count', ''],
            'duration' => ['duration', 'Actual minutes', ''],
            'checkpoint' => ['checkpoint', 'Daily checkpoints', 'missed'],
            'avoidance' => ['avoidance', 'Occurrences', ''],
        ];
    }

    #[DataProvider('types')]
    public function test_new_entry_layout_preserves_actual_type_controls_and_unsaved_defaults(string $type, string $label, string $default): void
    {
        $habit = $this->habit($type, ['name' => 'A real habit']);
        $page = $this->editor($habit, '2026-10-11')->assertSee('~/tracking/edit')->assertSee('Record activity')
            ->assertSee('A real habit')->assertSee('2026-10-11')->assertSee('UTC')->assertSee('Daily check-in')
            ->assertSee('Choose a date')->assertSee('Reload entry')->assertSee('No saved activity for this date.')
            ->assertSee($label)->assertSet('form.status', $default)->assertSet('entryRevision', null)
            ->assertSee('Only you can read this note.')->assertSee('Save activity')
            ->assertSee(route('tracking.index', ['date' => '2026-10-11']), false)
            ->assertSee(route('entries.index', $habit->id), false);
        if ($type === 'boolean') {
            $page->assertDontSee('value="partial"', false);
        }
        if ($type === 'checkpoint') {
            $page->assertSee('Read')->assertSee('Reflect')->assertDontSee('Your outcome');
        }
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_validation_preserves_draft_then_success_marks_an_existing_entry(): void
    {
        $habit = $this->habit('count', ['goal_value' => 10, 'goal_unit' => 'pages']);
        $page = $this->editor($habit, '2026-10-11')->set('form.note', 'Keep this private draft')
            ->call('save')->assertHasErrors(['form.status', 'form.value'])
            ->assertSee('Review the highlighted fields. Your changes have not been saved.')
            ->assertSet('form.note', 'Keep this private draft')->assertSet('feedback', '');
        $page->set('form.status', 'partial')->set('form.value', '5')->call('save')->assertHasNoErrors()
            ->assertSee('Editing saved activity.')->assertSet('feedback', 'Activity saved.')->assertSet('entryRevision', 1);
        $page->set('form.status', 'not_applicable')->assertSee('Private N/A reason (optional)')
            ->assertSee('Previously saved measurements and checkpoints are retained.')
            ->assertSee('Only you can read this reason.')->assertDontSee('Actual count');
        $page->set('form.not_applicable_reason', 'A private reason')->call('save')->assertHasNoErrors();
        $this->assertSame(5, $habit->entries()->sole()->value);
    }

    public function test_historical_checkpoint_identity_survives_future_configuration_changes_and_rendering(): void
    {
        $habit = $this->habit('checkpoint');
        $version = $habit->versions()->sole();
        $first = $version->checkpoints->first();
        $this->editor($habit, '2026-10-11')->set('form.completed', [(string) $first->id])->call('save')->assertHasNoErrors();
        $before = $habit->entries()->with('checkpoints')->get()->toArray();
        $this->editConfiguration($habit, ['checkpoints' => [['label' => 'New future checkpoint']]]);
        $this->editor($habit, '2026-10-11')->assertSee('Read')->assertSee('Reflect')->assertDontSee('New future checkpoint')
            ->assertSet('form.completed', [(string) $first->id])->assertSet('form.status', 'partial')
            ->assertSet('versionId', $version->id)->assertSee('Editing saved activity.');
        $this->assertSame($before, $habit->entries()->with('checkpoints')->get()->toArray());
    }

    public function test_zero_maximum_and_goal_free_numeric_guidance_use_the_dated_setup(): void
    {
        $habit = $this->habit('avoidance', ['goal_value' => 0, 'goal_unit' => 'times']);
        $this->editor($habit, '2026-10-11')->assertSee('Acceptable maximum')->assertSee('A zero maximum means abstinence.')
            ->assertSee('At or below your maximum counts as success.');
        $count = $this->habit('count');
        $this->editor($count, '2026-10-11')->assertSee('Without a numeric goal, Completed / Partial / Missed scores 100% / 50% / 0%.');
    }

    public function test_unavailable_dates_keep_existing_navigation_and_never_offer_save(): void
    {
        $habit = $this->habit();
        $this->editor($habit, '2026-10-12')->assertSee('Future dates cannot be recorded.')
            ->assertSee('Saved activity is read-only.')->assertDontSee('Save activity')->assertSee('Reload entry');
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'archive');
        $this->travel(1)->days();
        $this->editor($habit, '2026-10-12')->assertSee('This date falls in an archive or trash period.')
            ->assertDontSee('Save activity')->assertSee('Entry history');
        $futureStart = $this->habit('count', ['start_date' => '2026-10-20']);
        $this->editor($futureStart, '2026-10-12')->assertSee('Outside this habit’s active dates.')
            ->assertDontSee('wire:submit="save"', false)->assertDontSee('Save activity');
        $missing = Habit::factory()->create();
        $this->editor($missing, '2026-10-12')->assertDontSee('wire:submit="save"', false)->assertSee('Daily overview');
    }
}
