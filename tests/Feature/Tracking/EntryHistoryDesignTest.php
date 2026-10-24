<?php

namespace Tests\Feature\Tracking;

use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class EntryHistoryDesignTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(12, 0));
    }

    private function history($habit)
    {
        return Livewire::actingAs($habit->user)->test('pages::tracking.history', ['habit' => $habit->id]);
    }

    public static function scoringCases(): array
    {
        return [
            ['boolean', null, 'completed', null, 100.0], ['boolean', null, 'missed', null, 0.0],
            ['count', 10, 'completed', 5, 50.0], ['duration', 30, 'missed', 15, 50.0],
            ['avoidance', 2, 'completed', 3, 0.0], ['avoidance', 0, 'missed', 0, 100.0],
            ['count', null, 'partial', 5, 50.0], ['duration', null, 'completed', 5, 100.0],
            ['avoidance', null, 'missed', 0, 0.0],
        ];
    }

    #[DataProvider('scoringCases')]
    public function test_history_separates_assessment_and_performance_using_the_historical_goal(string $type, ?int $goal, string $status, ?int $value, float $score): void
    {
        $habit = $this->habit($type, ['goal_value' => $goal]);
        $this->editor($habit)->set('form.status', $status)->set('form.value', $value)->set('form.note', 'PRIVATE HISTORY NOTE')->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole();
        $before = $entry->getAttributes();
        $this->editConfiguration($habit, ['goal_value' => $type === 'boolean' ? null : 50]);
        $page = $this->history($habit)->assertSee('Historical performance')->assertSee($score.'%');
        $row = $page->instance()->history['entries']->first();
        $this->assertSame($goal, $row['version']->goal_value);
        $this->assertSame($score, (float) $row['performance']);
        $this->assertSame($status, $row['entry']->status->value);
        $page->assertSee($type === 'boolean' ? 'Recorded outcome' : 'Personal assessment');
        $this->assertStringNotContainsString('PRIVATE HISTORY NOTE', $page->html());
        $cell = collect(app(ActivityAnalytics::class)->report($habit->user, 2026, $habit->id)['cells'])->firstWhere('date', '2026-09-27');
        $this->assertSame($cell['score'] * 100, $score);
        $this->assertSame($before, $entry->refresh()->getAttributes());
    }

    public function test_checkpoint_snapshots_and_na_neutrality_survive_configuration_changes(): void
    {
        $habit = $this->habit('checkpoint');
        $ids = $habit->versions()->sole()->checkpoints->modelKeys();
        $this->editor($habit)->set('form.completed', [$ids[0]])->call('save')->assertHasNoErrors();
        $this->editConfiguration($habit, ['checkpoints' => [['label' => 'New item']]]);
        $page = $this->history($habit)->assertSee('1 of 2 completed')->assertSee('50%')->assertDontSee('New item');
        $this->editor($habit)->set('form.status', 'not_applicable')->set('form.not_applicable_reason', 'PRIVATE REASON')->call('save')->assertHasNoErrors();
        $page->call('$refresh')->assertSee('N/A · Neutral')->assertDontSee('50%')->assertDontSee('1 of 2 completed');
        $this->assertStringNotContainsString('PRIVATE REASON', $page->html());
        $this->assertSame(1, $habit->entries()->sole()->checkpoints()->where('is_completed', true)->count());
    }

    public function test_forty_five_entries_remain_newest_first_with_bounded_queries_and_page_recovery(): void
    {
        $habit = $this->habit('count', ['goal_unit' => str_repeat('W', 30), 'goal_value' => 10]);
        $version = $habit->versions()->sole();
        for ($i = 0; $i < 45; $i++) {
            app(SaveHabitEntry::class)->handle($habit->user, $habit->id, now()->subDays($i)->toDateString(), [
                'status' => 'completed', 'value' => 5, 'note' => 'PRIVATE NOTE', 'completed' => [],
            ], null, $version->id, $version->revision, 'UTC');
        }
        DB::enableQueryLog();
        $page = $this->history($habit)->assertSee(str_repeat('W', 30))->assertSee('Showing 1–20 of 45');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(12, $queries->count());
        $this->assertStringNotContainsString('`note`', $queries->implode(' '));
        $this->assertStringNotContainsString('`not_applicable_reason`', $queries->implode(' '));
        $dates = $page->instance()->history['entries']->pluck('date')->all();
        $page->call('nextPage')->assertSee('Showing 21–40 of 45');
        $dates = [...$dates, ...$page->instance()->history['entries']->pluck('date')->all()];
        $page->call('nextPage')->assertSee('Showing 41–45 of 45');
        $dates = [...$dates, ...$page->instance()->history['entries']->pluck('date')->all()];
        $this->assertSame(array_map(fn ($i) => now()->subDays($i)->toDateString(), range(0, 44)), $dates);
        $page->call('setPage', 99)->assertSee('No entries on this page.')->assertSee('Back to first page')->assertDontSee('No recorded activity yet.')->assertDontSee('Showing');
        $page->call('setPage', 1)->assertSee('Showing 1–20 of 45');
        $this->actingAs($habit->user)->get(route('entries.index', ['habit' => $habit, 'page' => 99]))->assertOk()->assertSee('No entries on this page.');
        $this->assertDatabaseCount('habit_entries', 45);
    }

    public function test_empty_archived_trashed_restored_and_future_local_history_keep_access_rules(): void
    {
        $habit = $this->habit();
        $this->history($habit)->assertSee('No recorded activity yet.')->assertSee("Open today's entry", false);
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $transition = app(TransitionHabit::class);
        $transition->handle($habit->user, $habit->id, 'archive');
        $this->travel(1)->days();
        $page = $this->history($habit)->assertSee('Archived habit')->assertSee('100%')->assertDontSee("Open today's entry", false);
        $transition->handle($habit->user, $habit->id, 'delete');
        $page->call('nextPage')->assertNotFound();
        $this->actingAs($habit->user)->get(route('entries.index', $habit))->assertNotFound();
        $transition->handle($habit->user, $habit->id, 'restore');
        $this->history($habit)->assertSee('Archived habit')->assertSee('2026-09-27');
        $this->travelBack();
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(0, 30));
        $habit->user->update(['timezone' => 'America/Los_Angeles']);
        $this->history($habit)->assertSee('2026-09-27')->assertSee('Read-only')->assertSee('Future dates cannot be recorded.')->assertSee('Neutral · Inactive date')->assertDontSee('100%');
        $this->assertDatabaseCount('habit_entries', 1);
    }

    public function test_foreign_and_switched_sessions_never_read_history_even_for_public_habits(): void
    {
        $habit = $this->habit(overrides: ['visibility' => 'public']);
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $page = $this->history($habit);
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('entries.index', $habit))->assertNotFound();
        Livewire::actingAs($other)->test('pages::tracking.history', ['habit' => $habit->id])->assertNotFound();
        $page->call('nextPage')->assertForbidden();
        auth()->logout();
        $this->get(route('entries.index', $habit))->assertRedirect(route('login'));
        $this->assertDatabaseCount('habit_entries', 1);
    }

    public function test_missing_checkpoint_membership_is_unavailable_and_na_hides_retained_numeric_values(): void
    {
        $habit = $this->habit('checkpoint');
        $this->editor($habit)->call('save')->assertHasNoErrors();
        $habit->entries()->sole()->checkpoints()->first()->delete();
        $this->history($habit)->assertSee('Unavailable')->assertDontSee('0%')->assertDontSee('0 of 2');
        $numeric = $this->habit('count', ['goal_value' => 10]);
        $this->editor($numeric)->set('form.status', 'completed')->set('form.value', 987654321)->call('save')->assertHasNoErrors();
        $this->editor($numeric)->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors();
        $this->history($numeric)->assertSee('N/A · Neutral')->assertDontSee('987654321')->assertDontSee('100%');
        $this->assertSame(987654321, $numeric->entries()->sole()->value);
    }
}
