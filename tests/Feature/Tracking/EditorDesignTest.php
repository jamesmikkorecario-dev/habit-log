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
            ->assertSee('Choose an assessment.')->assertSee('Enter the actual count.')
            ->assertDontSee('The form.status field')->assertDontSee('The form.value field')
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
        $this->editor($habit, '2026-10-11')->assertSee('Maximum: 0')->assertSee('Complete avoidance requires zero occurrences.')
            ->assertSee('Performance is 100% at zero, otherwise 0%.');
        $count = $this->habit('count');
        $this->editor($count, '2026-10-11')->assertSee('Assessment determines performance: Completed 100%, Partial 50%, Missed 0%.');
    }

    public function test_count_labels_and_guidance_use_the_selected_dates_configuration(): void
    {
        $habit = $this->habit('count', ['goal_value' => 20, 'goal_unit' => 'pages']);
        $this->editor($habit, '2026-10-10')->assertSee('Actual count (pages)')->assertSee('Your assessment')
            ->assertDontSee('Your outcome')
            ->assertSee('Performance is calculated from count ÷ goal; assessment is stored separately.')
            ->set('form.status', 'partial')->set('form.value', 10)->call('save')->assertHasNoErrors();
        $this->editConfiguration($habit, ['goal_value' => null, 'goal_unit' => 'chapters']);
        $this->editor($habit, '2026-10-10')->assertSee('Actual count (pages)')->assertDontSee('Actual count (chapters)')
            ->assertSee('Performance is calculated from count ÷ goal; assessment is stored separately.')
            ->assertSet('form.value', 10);
        $this->travel(1)->days();
        $this->editor($habit, '2026-10-12')->assertSee('Actual count (chapters)')
            ->assertSee('Assessment determines performance: Completed 100%, Partial 50%, Missed 0%.');
        $unitless = $this->habit('count');
        $this->editor($unitless, '2026-10-12')->assertSee('Actual count')->assertDontSee('Actual count (')
            ->set('form.status', 'invalid')->set('form.value', '-1')->call('save')
            ->assertHasErrors(['form.status', 'form.value'])->assertSee('Choose a valid assessment.')
            ->assertSee('Enter a whole number from 0 to 999,999,999.');
    }

    public function test_duration_messages_and_guidance_follow_dated_goals_without_changing_saved_minutes(): void
    {
        $habit = $this->habit('duration', ['goal_value' => 30]);
        $guidance = 'Performance is calculated from recorded minutes ÷ goal (capped at 100%); assessment is stored separately.';
        $page = $this->editor($habit, '2026-10-10')->assertSee('Your assessment')->assertDontSee('Your outcome')
            ->assertSee('Actual minutes')->assertSee($guidance)->call('save')
            ->assertHasErrors(['form.status', 'form.value'])->assertSee('Choose an assessment.')
            ->assertSee('Enter the actual minutes.')->assertDontSee('The form.status field')->assertDontSee('The form.value field');
        $page->set('form.status', 'invalid')->set('form.value', '1.5')->call('save')
            ->assertHasErrors(['form.status', 'form.value'])->assertSee('Choose a valid assessment.')
            ->assertSee('Enter a whole number from 0 to 999,999,999.');
        $page->set('form.status', 'partial')->set('form.value', 15)->call('save')->assertHasNoErrors();
        $page->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors();
        $this->assertSame(15, $habit->entries()->sole()->value);
        $this->editConfiguration($habit, ['goal_value' => null]);
        $this->editor($habit, '2026-10-10')->assertSee($guidance)->assertSet('form.value', 15);
        $this->travel(1)->days();
        $this->editor($habit, '2026-10-12')
            ->assertSee('Assessment determines performance: Completed 100%, Partial 50%, Missed 0%.')
            ->assertDontSee($guidance)->assertSet('form.value', '');
    }

    public function test_avoidance_readable_errors_and_dated_guidance_preserve_zero_and_absence(): void
    {
        $habit = $this->habit('avoidance', ['goal_value' => 0, 'goal_unit' => 'cups']);
        $this->editor($habit, '2026-10-11')->assertSee('Your assessment')->assertSee('Occurrences (cups)')
            ->assertSee('Maximum: 0')->assertDontSee('No maximum set.')
            ->call('save')->assertHasErrors(['form.status', 'form.value'])
            ->assertSee('Choose an assessment.')->assertSee('Enter the number of occurrences.')
            ->assertDontSee('The form.status field')->assertDontSee('The form.value field')
            ->set('form.status', 'completed')->set('form.value', 0)->call('save')->assertHasNoErrors();
        $this->editConfiguration($habit, ['goal_value' => null]);
        $this->editor($habit, '2026-10-11')->assertSee('Maximum: 0')->assertSee('Complete avoidance requires zero occurrences.');
        $this->travel(1)->days();
        $this->editor($habit, '2026-10-12')->assertSee('No maximum set.')->assertDontSee('Maximum: 0')
            ->assertSee('Without a maximum, assessment determines performance');
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
