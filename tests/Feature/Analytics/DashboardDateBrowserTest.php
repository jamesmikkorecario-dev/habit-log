<?php

namespace Tests\Feature\Analytics;

use App\Livewire\ActivityHeatmap;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class DashboardDateBrowserTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_dates_cross_years_and_clamp_month_ends_without_changing_activity(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 1)->setTime(12, 0));
        $habit = $this->habit();
        $before = app(ActivityAnalytics::class)->report($habit->user, 2026);
        $page = Livewire::actingAs($habit->user)->test(ActivityHeatmap::class)
            ->assertSet('browseDate', '2026-01-01')
            ->set('browseDate', '2024-01-31')->call('moveBrowseMonth', 1)->assertSet('browseDate', '2024-02-29')
            ->assertSet('selectedDate', '')->assertSet('year', 2026)
            ->call('browseToDate')->assertHasNoErrors()->assertSet('year', 2024)->assertSet('yearInput', 2024)
            ->assertSet('selectedDate', '2024-02-29')->assertDispatched('dashboard-date-selected')->assertSee('Open daily overview');
        $page->set('browseDate', '2023-01-31')->call('moveBrowseMonth', 1)->assertSet('browseDate', '2023-02-28')
            ->set('browseDate', '2024-01-15')->call('moveBrowseMonth', -1)->assertSet('browseDate', '2023-12-15')
            ->set('browseDate', '2024-12-15')->call('moveBrowseMonth', 1)->assertSet('browseDate', '2025-01-15')
            ->set('browseDate', '1000-01-01')->call('moveBrowseMonth', -1)->assertSet('browseDate', '1000-01-01')
            ->set('browseDate', '2026-12-31')->call('moveBrowseMonth', 1)->assertSet('browseDate', '2026-12-31')
            ->call('browseToDate')->assertSet('selectedDate', '2026-12-31')->assertSee('Future date')->assertDontSee('Open daily overview')
            ->call('changeYear', -1)->assertSet('browseDate', '2025-01-01')->assertSet('selectedDate', '')
            ->call('selectDay', '2025-06-12')->assertSet('browseDate', '2025-06-12');
        $this->assertSame($before, app(ActivityAnalytics::class)->report($habit->user, 2026));
        $this->assertDatabaseCount('habit_entries', 0);
    }

    public function test_invalid_dates_preserve_selection_and_use_fresh_owner_local_year(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 12, 31)->setTime(23, 30));
        $user = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
        $page = Livewire::actingAs($user)->test(ActivityHeatmap::class)
            ->set('browseDate', '2024-02-29')->call('browseToDate');
        foreach (['', 'invalid', '2023-02-29', '2024-02-30', '0999-12-31', '2027-01-01'] as $date) {
            $page->set('browseDate', $date)->call('browseToDate')->assertHasErrors('browseDate')
                ->assertSet('selectedDate', '2024-02-29')->assertSet('year', 2024);
        }
        $user->update(['timezone' => 'Asia/Manila']);
        $page->set('browseDate', '2027-12-31')->call('browseToDate')->assertHasNoErrors()->assertSet('year', 2027);
        Livewire::actingAs($user)->test(ActivityHeatmap::class)->call('moveBrowseMonth', 2)->assertStatus(422);
    }

    public function test_browsing_retains_owner_boundary_and_other_pages_keep_their_day_lists(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $habit = $this->habit(user: $owner);
        Livewire::actingAs($owner)->test(ActivityHeatmap::class, ['habitId' => $habit->id])
            ->assertSee('Accessible day list')->assertDontSee('Browse dates')
            ->call('browseToDate')->assertNotFound();
        $viewer = User::factory()->create();
        Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id])
            ->assertSee('Accessible day list')->assertDontSee('Browse dates')
            ->call('moveBrowseMonth', 1)->assertRedirect(route('people.show', ['user' => $owner->username]));
        $page = Livewire::actingAs($owner)->test(ActivityHeatmap::class);
        auth()->login($viewer);
        $page->call('browseToDate')->assertForbidden();
    }
}
