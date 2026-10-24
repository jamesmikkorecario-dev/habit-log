<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\CancelTrackingChange;
use App\Actions\Habits\TransitionHabit;
use App\Models\User;
use App\Services\TrackingDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class HistoryTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 27)->setTime(12, 0));
    }

    public function test_goal_type_dates_and_units_change_only_from_tomorrow(): void
    {
        $habit = $this->habit('count', ['goal_value' => 10, 'goal_unit' => 'pages']);
        $this->editor($habit)->set('form.status', 'partial')->set('form.value', 6)->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole();
        $this->editConfiguration($habit, ['goal_value' => 20]);
        $this->assertSame(10, $entry->version->goal_value);
        $this->assertSame(20, $habit->versions()->latest('effective_date')->first()->goal_value);
        $this->assertSame('2026-09-28', $habit->versions()->latest('effective_date')->first()->effective_date->toDateString());
        $this->editConfiguration($habit->fresh(), ['type' => 'duration', 'goal_unit' => 'minutes', 'goal_value' => 30, 'end_date' => '2026-09-29']);
        $this->assertSame(2, $habit->versions()->count());
        $this->travel(1)->days();
        $this->editor($habit, '2026-09-28')->assertSee('Actual minutes')->set('form.value', 30)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $this->editor($habit, '2026-09-26')->assertSee('pages')->set('form.value', 9)->set('form.status', 'partial')->call('save')->assertHasNoErrors();
        $this->assertSame('count', $habit->entries()->where('entry_date', '2026-09-26')->first()->version->type->value);
        $this->assertSame(6, $entry->fresh()->value);
        $this->travel(2)->days();
        $this->editor($habit, '2026-09-30')->set('form.status', 'completed')->set('form.value', 1)->call('save')->assertHasErrors('form.status');
        $this->editor($habit, '2026-09-29')->set('form.status', 'completed')->set('form.value', 1)->call('save')->assertHasNoErrors();
    }

    public function test_removed_and_renamed_checkpoints_keep_historical_labels_order_and_completions(): void
    {
        $habit = $this->habit('checkpoint');
        $version = $habit->versions()->first();
        $ids = $version->checkpoints->modelKeys();
        $this->editor($habit)->set('form.completed', [$ids[0]])->call('save')->assertHasNoErrors();
        $current = $habit->checkpoints()->get();
        $this->editConfiguration($habit, ['checkpoints' => [['id' => $current[1]->id, 'label' => 'Changed reflection']]]);
        $this->assertSame(['Read', 'Reflect'], $version->fresh()->checkpoints->pluck('label')->all());
        $this->assertNull($version->fresh()->checkpoints->first()->habit_checkpoint_id);
        $this->editor($habit)->assertSee('Read')->assertSee('Reflect')->assertDontSee('Changed reflection')->assertSet('form.completed', [(string) $ids[0]]);
        $this->editor($habit, '2026-09-26')->set('form.completed', [$ids[1]])->call('save')->assertHasNoErrors();
        $this->travel(1)->days();
        $this->editor($habit, '2026-09-28')->assertSee('Changed reflection')->set('form.completed', [$ids[0]])->call('save')->assertHasErrors('form.completed.0');
    }

    public function test_baseline_changes_reject_stale_form_and_na_starts_history(): void
    {
        $habit = $this->habit('count');
        $old = $this->editor($habit)->set('form.status', 'completed')->set('form.value', 7);
        $this->editConfiguration($habit, ['type' => 'duration', 'goal_unit' => 'minutes']);
        $this->assertSame(1, $habit->versions()->count());
        $old->call('save')->assertHasErrors('conflict');
        $old->call('loadEntry')->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors();
        $this->editConfiguration($habit->fresh(), ['goal_value' => 20]);
        $this->assertSame(2, $habit->versions()->count());
        app(CancelTrackingChange::class)->handle($habit->user, $habit->id);
        $this->assertSame(1, $habit->versions()->count());
        $this->assertNull($habit->fresh()->goal_value);
        $this->assertDatabaseCount('habit_entries', 1);
    }

    public function test_archive_and_trash_gaps_overlap_and_restore_keeps_archived_state(): void
    {
        $habit = $this->habit();
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $transition = app(TransitionHabit::class);
        $transition->handle($habit->user, $habit->id, 'archive');
        $this->editor($habit)->set('form.status', 'missed')->call('save')->assertHasNoErrors();
        $transition->handle($habit->user, $habit->id, 'delete');
        $this->actingAs($habit->user)->get(route('entries.index', $habit))->assertNotFound();
        $this->travel(40)->days();
        $transition->handle($habit->user, $habit->id, 'restore');
        $this->assertNotNull($habit->fresh()->archived_at);
        $this->assertSame(1, $habit->suspensions()->whereNull('end_date')->count());
        $transition->handle($habit->user, $habit->id, 'unarchive');
        $this->assertSame(0, $habit->suspensions()->whereNull('end_date')->count());
        $dates = app(TrackingDate::class);
        $this->assertNull($dates->reason($habit->user, $habit->fresh(), '2026-09-27'));
        $this->assertNotNull($dates->reason($habit->user, $habit->fresh(), '2026-09-28'));
        $this->assertNull($dates->reason($habit->user, $habit->fresh(), $habit->user->localToday()));
        $this->editor($habit, '2026-09-28')->set('form.status', 'completed')->call('save')->assertHasErrors('form.status');
        $this->assertDatabaseCount('habit_entries', 1);
    }

    public function test_same_day_lifecycle_is_empty_and_stale_trash_save_fails(): void
    {
        $habit = $this->habit();
        $page = $this->editor($habit)->set('form.status', 'completed');
        $transition = app(TransitionHabit::class);
        $transition->handle($habit->user, $habit->id, 'archive');
        $transition->handle($habit->user, $habit->id, 'unarchive');
        $period = $habit->suspensions()->sole();
        $this->assertTrue($period->start_date->equalTo($period->end_date));
        $transition->handle($habit->user, $habit->id, 'delete');
        $before = $habit->refresh()->getAttributes();
        $page->call('save')->assertNotFound();
        $this->assertSame($before, $habit->refresh()->getAttributes());
        $this->assertDatabaseCount('habit_entries', 0);
        $this->assertDatabaseCount('habit_checkpoint_entries', 0);
    }

    public function test_trash_rejects_stale_reads_and_updates_without_changing_saved_entry_or_checkpoints(): void
    {
        $habit = $this->habit('checkpoint');
        $ids = $habit->versions()->sole()->checkpoints->modelKeys();
        $this->editor($habit)->set('form.completed', [$ids[0]])->set('form.note', 'Preserved checkpoint note')->call('save')->assertHasNoErrors();
        $reader = $this->editor($habit);
        $writer = $this->editor($habit)->set('form.completed', $ids)->set('form.note', 'Stale replacement');
        $entry = $habit->entries()->sole();
        $before = $entry->getAttributes();
        $children = $entry->checkpoints()->orderBy('id')->get()->map->getAttributes()->all();
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'delete');
        $habitBefore = $habit->refresh()->getAttributes();

        $this->actingAs($habit->user)->get(route('entries.index', $habit))->assertNotFound()->assertDontSee('Preserved checkpoint note');
        $this->get(route('entries.edit', ['habit' => $habit, 'date' => '2026-09-27']))->assertNotFound()->assertDontSee('Preserved checkpoint note');
        $this->editor($habit)->assertNotFound()->assertDontSee('Preserved checkpoint note');
        $reader->call('loadEntry')->assertNotFound();
        $writer->call('save')->assertNotFound();
        $this->assertSame($habitBefore, $habit->refresh()->getAttributes());
        $this->assertSame($before, $entry->refresh()->getAttributes());
        $this->assertSame($children, $entry->checkpoints()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('habit_entries', 1);
        $this->assertDatabaseCount('habit_checkpoint_entries', 2);
    }

    public function test_timezone_changes_do_not_move_dates_and_stale_timezone_form_rejects(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->travelTo(now()->setDate(2026, 9, 27)->setTime(18, 0));
        $habit = $this->habit('boolean', [], $user);
        $page = $this->editor($habit)->set('form.status', 'completed');
        $user->update(['timezone' => 'Asia/Manila']);
        $this->assertSame('2026-09-28', $user->localToday());
        $page->call('save')->assertHasErrors('conflict')->call('loadEntry')->call('save')->assertHasErrors('form.status');
        $page->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $this->assertSame('2026-09-27', $habit->entries()->sole()->entry_date->toDateString());
        Livewire::actingAs($user)->test('pages::habits.edit')->assertSet('form.start_date', '2026-09-28');
        $this->editConfiguration($habit->fresh(), ['type' => 'count']);
        $user->update(['timezone' => 'America/Los_Angeles']);
        try {
            $this->editConfiguration($habit->fresh(), ['type' => 'duration', 'goal_unit' => 'minutes']);
            $this->fail('Pending date mismatch must be explicit.');
        } catch (ValidationException) {
            $this->assertSame('count', $habit->fresh()->type->value);
        }
        app(CancelTrackingChange::class)->handle($user, $habit->id);
        $this->assertSame('boolean', $habit->fresh()->type->value);
    }

    public static function dateCases(): array
    {
        return [['2026-09-28'], ['2023-12-31'], ['2025-02-29'], ['0999-12-31'], ['bad-date']];
    }

    #[DataProvider('dateCases')]
    public function test_ineligible_or_invalid_dates_cannot_create_entries(string $date): void
    {
        $habit = $this->habit();
        if (in_array($date, ['2025-02-29', '0999-12-31', 'bad-date'], true)) {
            $this->actingAs($habit->user)->get(route('entries.edit', ['habit' => $habit, 'date' => $date]))->assertNotFound();
            $this->assertDatabaseCount('habit_entries', 0);

            return;
        }
        try {
            $this->editor($habit, $date)->set('form.status', 'completed')->call('save')->assertHasErrors('form.status');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_leap_day_equal_range_and_dst_boundaries(): void
    {
        $habit = $this->habit('boolean', ['start_date' => '2024-02-29', 'end_date' => '2024-02-29']);
        $this->editor($habit, '2024-02-29')->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $user = $habit->user;
        $user->update(['timezone' => 'America/New_York']);
        foreach (['2026-03-08 06:59:00', '2026-03-08 07:01:00'] as $instant) {
            $this->travelTo(Carbon::parse($instant, 'UTC'));
            $this->assertSame('2026-03-08', $user->localToday());
        }
        foreach (['2026-11-01 05:30:00', '2026-11-01 06:30:00'] as $instant) {
            $this->travelTo(Carbon::parse($instant, 'UTC'));
            $this->assertSame('2026-11-01', $user->localToday());
        }
    }

    public function test_timezone_travel_keeps_existing_future_local_date_readable_but_not_writable(): void
    {
        $this->travelTo(Carbon::parse('2026-09-27 18:00:00', 'UTC'));
        $user = User::factory()->create(['timezone' => 'Asia/Manila']);
        $habit = $this->habit('count', [], $user);
        $this->editor($habit, '2026-09-28')->set('form.status', 'completed')->set('form.value', 3)->set('form.note', 'Preserved across travel')->call('save')->assertHasNoErrors();
        $user->update(['timezone' => 'America/Los_Angeles']);
        $this->editor($habit, '2026-09-28')->assertSee('Saved activity is read-only.')->assertSet('form.note', 'Preserved across travel')->set('form.value', 4)->call('save')->assertHasErrors('form.status');
        $this->assertSame(3, $habit->entries()->sole()->value);
    }
}
