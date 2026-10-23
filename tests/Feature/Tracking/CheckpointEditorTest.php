<?php

namespace Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckpointEditorTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 23)->setTime(12, 0));
    }

    public function test_single_tracking_option_preserves_derived_outcomes_and_na_snapshots(): void
    {
        $habit = $this->habit('checkpoint');
        $ids = $habit->versions()->sole()->checkpoints->modelKeys();
        $page = $this->editor($habit, '2026-10-23')->assertSee('data-checkpoint-summary', false)
            ->assertSee('Save activity above checkpoints')->assertSet('entryRevision', null);
        foreach ([[], [$ids[0]], $ids] as $selected) {
            $status = count($selected) === 2 ? 'completed' : ($selected ? 'partial' : 'missed');
            $page->set('form.completed', $selected)->call('save')->assertHasNoErrors()->assertSet('form.status', $status);
            $this->assertSame(1, substr_count(strip_tags($page->html()), 'Track checkpoints'));
            $this->assertSame($status, $habit->entries()->sole()->status->value);
        }
        $page->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors()
            ->assertDontSee('data-entry-checkpoint', false)->assertSee('Private N/A reason');
        $this->assertSame(2, $habit->entries()->sole()->checkpoints()->where('is_completed', true)->count());
        $page->set('form.status', 'missed')->call('save')->assertHasNoErrors()->assertSet('form.status', 'completed');
    }

    public static function invalidSelections(): array
    {
        return [
            'foreign ID' => [[999999]],
            'duplicate ID' => [['duplicate', 'duplicate']],
            'not an array' => ['invalid'],
            'not a list' => [['key' => 999999]],
            'more than twenty' => [array_fill(0, 21, 999999)],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selections_show_one_readable_group_error_without_writes(mixed $input): void
    {
        $habit = $this->habit('checkpoint');
        if ($input === ['duplicate', 'duplicate']) {
            $id = $habit->versions()->sole()->checkpoints->first()->id;
            $input = [$id, $id];
        }
        $message = 'One or more checkpoint selections are invalid. Reload this entry and try again.';
        $page = $this->editor($habit, '2026-10-23')->set('form.completed', $input)->set('form.note', 'Keep my draft')
            ->call('save')->assertHasErrors()->assertSee($message)->assertSet('form.note', 'Keep my draft');
        $this->assertSame(1, substr_count(strip_tags($page->html()), $message));
        $this->assertDatabaseCount('habit_entries', 0);
        $this->assertDatabaseCount('habit_checkpoint_entries', 0);
    }

    public function test_checkpoint_text_errors_are_readable_and_preserve_saved_data(): void
    {
        $habit = $this->habit('checkpoint');
        $page = $this->editor($habit, '2026-10-23')->call('save')->assertHasNoErrors();
        $page->set('form.note', str_repeat('x', 2001))->call('save')->assertHasErrors('form.note')
            ->assertSee('Keep your private note to 2,000 characters or fewer.');
        $page->set('form.note', 'Draft')->set('form.status', 'not_applicable')->set('form.not_applicable_reason', str_repeat('x', 501))
            ->call('save')->assertHasErrors('form.not_applicable_reason')->assertSee('Keep your N/A reason to 500 characters or fewer.');
        $this->assertSame(1, $habit->entries()->sole()->revision);
        $this->assertNull($habit->entries()->sole()->note);
    }

    public function test_summary_uses_historical_setup_and_read_only_dates_have_no_save_buttons(): void
    {
        $habit = $this->habit('checkpoint');
        $version = $habit->versions()->sole();
        $this->editor($habit, '2026-10-22')->set('form.completed', [$version->checkpoints->first()->id])->call('save')->assertHasNoErrors();
        $this->editConfiguration($habit, ['checkpoints' => [['label' => 'Future only']]]);
        $page = $this->editor($habit, '2026-10-22')->assertSee('Read')->assertSee('Reflect')->assertDontSee('Future only')
            ->assertSee('checkpoint-summary-'.$version->id.'-'.$version->revision, false);
        $this->assertSame(2, substr_count($page->html(), 'data-entry-checkpoint'));
        $this->editor($habit, '2026-10-24')->assertDontSee('Save activity')->assertSee('Future dates cannot be recorded.');
    }
}
