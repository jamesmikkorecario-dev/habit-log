<?php

namespace Tests\Feature\Analytics;

use App\Actions\Habits\TransitionHabit;
use App\Livewire\ActivityHeatmap;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class EmptyPresentationTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(12, 0));
    }

    private function card($page, string $label): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $card = (new \DOMXPath($document))->query('//article[@aria-label="'.$label.'"]')->item(0);
        $this->assertNotNull($card);

        return preg_replace('/\s+/u', ' ', $card->textContent);
    }

    public function test_new_account_guidance_preserves_zero_streaks_and_calendar(): void
    {
        $user = User::factory()->create();
        $page = Livewire::actingAs($user)->test(ActivityHeatmap::class);
        $this->assertStringContainsString('— Create your first habit to get started.', $this->card($page, '30-day consistency'));
        $this->assertStringContainsString('0 days', $this->card($page, 'Current streak'));
        $this->assertStringContainsString('0 days', $this->card($page, 'Longest streak'));
        $page->assertSee('data-date="2026-01-01"', false)->assertSee('data-date="2026-12-31"', false)
            ->assertSee('How these numbers work')->assertSee('Browse dates');
    }

    public function test_first_check_in_guidance_is_period_aware_and_never_replaces_a_valid_zero(): void
    {
        $habit = $this->habit(overrides: ['start_date' => '2026-09-27']);
        $page = Livewire::actingAs($habit->user)->test(ActivityHeatmap::class);
        $this->assertStringContainsString('— Your progress will appear after your first check-in.', $this->card($page, '30-day consistency'));
        $this->assertSame('pending', collect($page->instance()->activity['cells'])->firstWhere('date', '2026-09-27')['state']);
        $page->call('changeYear', -1);
        $this->assertStringContainsString('— No eligible activity for this period.', $this->card($page, 'Yearly consistency'));
        $this->assertStringContainsString('first check-in', $this->card($page, '30-day consistency'));
        $this->travel(1)->days();
        $page->call('changeYear', 0);
        $this->assertStringContainsString('0.0%', $this->card($page, '30-day consistency'));
        $this->assertStringNotContainsString('—', $this->card($page, 'Yearly consistency'));
        $this->assertStringContainsString('0 days', $this->card($page, 'Current streak'));
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_not_applicable_and_unavailable_have_distinct_messages_and_no_fabricated_scores(): void
    {
        $habit = $this->habit('checkpoint', ['start_date' => '2026-09-27']);
        $this->editor($habit)->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors();
        $page = Livewire::actingAs($habit->user)->test(ActivityHeatmap::class);
        $this->assertStringContainsString('— Not applicable for this period.', $this->card($page, 'Yearly consistency'));
        $this->habit(overrides: ['start_date' => '2026-09-27'], user: $habit->user);
        $page->call('$refresh');
        $this->assertStringContainsString('— Some activity is still awaiting a check-in.', $this->card($page, 'Yearly consistency'));
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        DB::table('habit_checkpoint_entries')->where('habit_entry_id', $habit->entries()->sole()->id)->limit(1)->delete();
        $before = app(ActivityAnalytics::class)->report($habit->user, 2026);
        $page->call('$refresh');
        $this->assertStringContainsString('— Some activity data is unavailable for this period.', $this->card($page, 'Yearly consistency'));
        $this->assertStringContainsString('0 days', $this->card($page, 'Current streak'));
        $this->assertSame($before, app(ActivityAnalytics::class)->report($habit->user, 2026));
        $this->assertSame(1, $before['yearly']['pending']);
        $this->assertSame(1, $before['yearly']['unavailable']);
    }

    public function test_archived_history_trash_and_old_check_ins_are_not_misrepresented_as_new_accounts(): void
    {
        $habit = $this->habit(overrides: ['start_date' => '2026-09-27']);
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'archive');
        $this->travel(40)->days();
        $page = Livewire::actingAs($habit->user)->test(ActivityHeatmap::class);
        $this->assertStringContainsString('— No eligible activity for this period.', $this->card($page, '30-day consistency'));
        $this->assertStringContainsString('100.0%', $this->card($page, 'Yearly consistency'));
        $this->assertStringContainsString('1 days', $this->card($page, 'Current streak'));
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'delete');
        $page->call('$refresh')->assertDontSee('Create your first habit to get started.');
        $this->assertStringContainsString('— No eligible activity for this period.', $this->card($page, 'Yearly consistency'));
        $this->habit(overrides: ['start_date' => $habit->user->localToday()], user: $habit->user);
        $page->call('$refresh')->assertDontSee('Your progress will appear after your first check-in.');
        $this->assertStringContainsString('awaiting a check-in', $this->card($page, 'Yearly consistency'));
        app(TransitionHabit::class)->handle($habit->user, $habit->id, 'restore');
        $page->call('$refresh');
        $this->assertStringContainsString('100.0%', $this->card($page, 'Yearly consistency'));
    }

    public function test_inactive_dates_and_shared_views_do_not_invent_account_history(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $this->habit(overrides: ['start_date' => '2026-09-28', 'visibility' => 'public'], user: $owner);
        $page = Livewire::actingAs($owner)->test(ActivityHeatmap::class);
        $this->assertStringContainsString('— No eligible activity for this period.', $this->card($page, 'Yearly consistency'));
        $viewer = User::factory()->create();
        $shared = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id]);
        $before = $this->card($shared, 'Yearly consistency');
        $private = $this->habit(overrides: ['start_date' => '2026-09-27'], user: $owner);
        $this->editor($private)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $shared = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id]);
        $this->assertStringContainsString('— No eligible activity for this period.', $this->card($shared, 'Yearly consistency'));
        $this->assertSame($before, $this->card($shared, 'Yearly consistency'));
        $shared->assertDontSee('first check-in')->assertDontSee('Create your first habit');
        $this->travel(1)->days();
        $shared->call('$refresh');
        $this->assertStringContainsString('— Some activity is still awaiting a check-in.', $this->card($shared, 'Yearly consistency'));
    }
}
