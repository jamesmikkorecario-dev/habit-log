<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\CancelTrackingChange;
use App\Services\Analytics\HabitScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigurationComparisonTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(12, 0));
    }

    public static function maximumChanges(): array
    {
        return [[null, 0], [0, null], [0, 2], [2, 0]];
    }

    private function setMaximum($habit, ?int $value): void
    {
        Livewire::actingAs($habit->user)->test('pages::habits.edit', ['habit' => $habit->id])
            ->set('form.goal_value', $value === null ? '  ' : (string) $value)
            ->call('save')->assertHasNoErrors();
    }

    #[DataProvider('maximumChanges')]
    public function test_maximum_changes_update_the_baseline_and_reject_stale_entry_drafts(?int $before, ?int $after): void
    {
        $habit = $this->habit('avoidance', ['goal_value' => $before]);
        $version = $habit->versions()->sole();
        $stale = $this->editor($habit)->set('form.status', 'completed')->set('form.value', 1);
        $this->setMaximum($habit, $after);
        $this->assertSame($after, $habit->fresh()->goal_value);
        $this->assertSame($after, $version->fresh()->goal_value);
        $this->assertSame(1, $habit->versions()->count());
        $this->assertSame($version->revision + 1, $version->fresh()->revision);
        $stale->call('save')->assertHasErrors('conflict');
        $this->assertSame(0, $habit->entries()->count());
        $stale->call('loadEntry')->set('form.status', 'completed')->set('form.value', 1)
            ->call('save')->assertHasNoErrors();
    }

    #[DataProvider('maximumChanges')]
    public function test_maximum_changes_preserve_history_and_take_effect_tomorrow(?int $before, ?int $after): void
    {
        $habit = $this->habit('avoidance', ['goal_value' => $before]);
        $original = $habit->versions()->sole();
        $this->editor($habit)->set('form.status', 'partial')->set('form.value', 1)
            ->set('form.note', 'Private historical note')->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole()->toArray();
        $this->setMaximum($habit, $after);
        $pending = $habit->versions()->latest('effective_date')->first();
        $this->assertSame(2, $habit->versions()->count());
        $this->assertSame('2026-09-28', $pending->effective_date->toDateString());
        $this->assertSame($after, $pending->goal_value);
        $this->assertSame($before, $original->fresh()->goal_value);
        $this->assertSame($entry, $habit->entries()->sole()->toArray());
        $this->editor($habit)->assertSet('versionId', $original->id)->assertSet('form.value', 1);
        // An identical pending configuration must not replace it or bump its revision.
        $this->setMaximum($habit, $after);
        $this->assertSame($pending->revision, $pending->fresh()->revision);
        $this->assertSame(2, $habit->versions()->count());
        $this->travel(1)->days();
        $this->editor($habit, '2026-09-28')->assertSet('versionId', $pending->id)
            ->set('form.status', 'completed')->set('form.value', 0)->call('save')->assertHasNoErrors();
        $this->assertSame($entry, $habit->entries()->where('entry_date', '2026-09-27')->sole()->toArray());
    }

    public function test_pending_zero_and_blank_changes_reuse_the_future_version_and_cancel_cleanly(): void
    {
        $habit = $this->habit('avoidance', ['goal_value' => 2]);
        $this->editor($habit)->set('form.status', 'completed')->set('form.value', 0)->call('save')->assertHasNoErrors();
        $this->setMaximum($habit, null);
        $pending = $habit->versions()->latest('effective_date')->first();
        foreach ([0, null, 3, 0] as $maximum) {
            $this->setMaximum($habit, $maximum);
            $this->assertSame(2, $habit->versions()->count());
            $this->assertSame($pending->id, $habit->versions()->latest('effective_date')->first()->id);
            $this->assertSame($maximum, $pending->fresh()->goal_value);
        }
        app(CancelTrackingChange::class)->handle($habit->user, $habit->id);
        $this->assertSame(1, $habit->versions()->count());
        $this->assertSame(2, $habit->fresh()->goal_value);
        $this->assertSame(0, $habit->entries()->sole()->value);
    }

    public static function unchangedTypes(): array
    {
        return [['boolean', null], ['count', 2], ['duration', 30], ['avoidance', 0], ['avoidance', null], ['checkpoint', null]];
    }

    #[DataProvider('unchangedTypes')]
    public function test_semantically_identical_saves_do_not_change_versions_or_checkpoint_ids(string $type, ?int $goal): void
    {
        $habit = $this->habit($type, ['goal_value' => $goal, 'end_date' => '2026-10-01']);
        $original = $habit->versions()->with('checkpoints')->get()->toArray();
        $this->editConfiguration($habit, ['name' => 'Renamed only', 'goal_value' => $goal === null ? null : (string) $goal]);
        $this->assertSame($original, $habit->versions()->with('checkpoints')->get()->toArray());
        $this->editor($habit)->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors();
        $this->editConfiguration($habit, ['description' => 'Only a description changed']);
        $this->assertSame($original, $habit->versions()->with('checkpoints')->get()->toArray());
    }

    public static function numericTypes(): array
    {
        return [['count'], ['duration'], ['avoidance']];
    }

    #[DataProvider('numericTypes')]
    public function test_positive_and_absent_goals_remain_distinct_for_all_numeric_types(string $type): void
    {
        $habit = $this->habit($type);
        foreach ([2, null] as $goal) {
            $this->setMaximum($habit, $goal);
            $this->assertSame($goal, $habit->versions()->sole()->goal_value);
        }
    }

    public function test_numeric_looking_units_and_checkpoint_labels_are_not_equivalent(): void
    {
        $habit = $this->habit('count', ['goal_unit' => '01']);
        $this->editConfiguration($habit, ['goal_unit' => '1']);
        $this->assertSame('1', $habit->versions()->sole()->goal_unit);
        $checklist = $this->habit('checkpoint', ['checkpoints' => [['label' => '01']]]);
        $this->editConfiguration($checklist, ['checkpoints' => [['label' => '1']]]);
        $this->assertSame('1', $checklist->versions()->sole()->checkpoints()->sole()->label);
    }

    public function test_corrected_zero_and_absent_versions_feed_the_existing_scoring_contract(): void
    {
        $habit = $this->habit('avoidance');
        $score = new HabitScore;
        $this->setMaximum($habit, 0);
        $version = $habit->versions()->sole();
        $this->assertSame(1.0, $score->calculate('avoidance', $version->goal_value, 'missed', 0)['score']);
        $this->assertSame(0.0, $score->calculate('avoidance', $version->goal_value, 'completed', 1)['score']);
        $this->setMaximum($habit, null);
        foreach (['completed' => 1.0, 'partial' => 0.5, 'missed' => 0.0] as $assessment => $expected) {
            $this->assertSame($expected, $score->calculate('avoidance', $version->fresh()->goal_value, $assessment, 1)['score']);
        }
    }
}
