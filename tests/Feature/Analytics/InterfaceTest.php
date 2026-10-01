<?php

namespace Tests\Feature\Analytics;

use App\Actions\Habits\TransitionHabit;
use App\Livewire\ActivityHeatmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class InterfaceTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_year_navigation_selection_and_day_list_are_read_only(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        $habit = $this->habit();
        $page = Livewire::actingAs($habit->user)->test(ActivityHeatmap::class, ['habitId' => $habit->id])
            ->assertSee('Habit activity')->assertSee('30-day consistency')->assertSet('year', 2026);
        $page->call('changeYear', -1)->assertSet('year', 2025)->call('changeYear', -1)->assertSet('year', 2024);
        $page->call('selectDay', '2024-02-29')->assertSet('selectedDate', '2024-02-29')->assertSee('Open daily entry');
        $page->call('moveList', 1)->assertSet('listPage', 2)->assertSee('Page 2 of 19');
        $page->call('changeYear', 0)->assertSet('year', 2026)->assertSet('selectedDate', '')->assertSet('listPage', 1);
        $this->assertDatabaseCount('habit_entries', 0);
        $this->actingAs($habit->user)->get(route('habits.show', $habit))->assertOk()->assertSeeLivewire(ActivityHeatmap::class);
        $this->get(route('dashboard'))->assertOk()->assertSee('Overall profile activity');
    }

    public function test_invalid_years_dates_and_pages_are_rejected(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        $user = User::factory()->create();
        foreach ([999, 2027, 'nonsense', ['2024']] as $year) {
            Livewire::actingAs($user)->test(ActivityHeatmap::class)->set('year', $year)->assertStatus(422);
            Livewire::actingAs($user)->test(ActivityHeatmap::class)->set('yearInput', $year)->call('goToYear')->assertHasErrors('yearInput');
        }
        $page = Livewire::actingAs($user)->test(ActivityHeatmap::class);
        $page->call('selectDay', '2026-02-30')->assertHasErrors('date');
        Livewire::actingAs($user)->test(ActivityHeatmap::class)->call('selectDay', '2025-01-01')->assertStatus(422);
        Livewire::actingAs($user)->test(ActivityHeatmap::class)->set('selectedDate', '2025-01-01')->assertStatus(422);
        Livewire::actingAs($user)->test(ActivityHeatmap::class)->set('listPage', -1)->assertStatus(422);
        Livewire::actingAs($user)->test(ActivityHeatmap::class)->call('changeYear', 100)->assertStatus(422);
    }

    public function test_owner_isolation_for_all_visibility_preferences_and_trash(): void
    {
        $other = User::factory()->create();
        foreach (['private', 'followers', 'public'] as $visibility) {
            $habit = $this->habit('boolean', ['visibility' => $visibility]);
            Livewire::actingAs($other)->test(ActivityHeatmap::class, ['habitId' => $habit->id])->assertNotFound();
            $this->actingAs($other)->get(route('habits.show', $habit))->assertNotFound();
            app(TransitionHabit::class)->handle($habit->user, $habit->id, 'delete');
            Livewire::actingAs($habit->user)->test(ActivityHeatmap::class, ['habitId' => $habit->id])->assertNotFound();
        }
    }

    public function test_locked_identity_and_expired_session_deny_livewire_requests(): void
    {
        $habit = $this->habit();
        try {
            Livewire::actingAs($habit->user)->test(ActivityHeatmap::class, ['habitId' => $habit->id])->set('habitId', null);
            $this->fail('Habit identity must stay locked.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertDatabaseCount('habit_entries', 0);
        }
        $response = $this->actingAs($habit->user)->get(route('dashboard'))->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = collect($matches[1])->map(fn ($s) => html_entity_decode($s, ENT_QUOTES | ENT_HTML5))->first(fn ($s) => (json_decode($s, true)['memo']['name'] ?? '') === 'activity-heatmap');
        $this->assertNotNull($snapshot);
        Auth::logout();
        $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'changeYear', 'params' => [-1]]],
        ]]])->assertUnauthorized();
    }
}
