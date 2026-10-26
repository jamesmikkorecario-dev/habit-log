<?php

namespace Tests\Feature\Analytics;

use App\Livewire\ActivityHeatmap;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class CalendarPresentationTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_dashboard_cells_keep_accessible_scores_and_private_data_out_of_previews(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(12, 0));
        $habit = $this->habit('count', ['goal_value' => 10, 'goal_unit' => 'pages']);
        $this->editor($habit)->set('form.status', 'completed')->set('form.value', 0)
            ->set('form.note', 'PRIVATE-CALENDAR-NOTE')->call('save')->assertHasNoErrors();
        $this->editor($habit, '2026-09-26')->set('form.status', 'not_applicable')
            ->set('form.not_applicable_reason', 'PRIVATE-CALENDAR-REASON')->call('save')->assertHasNoErrors();
        $before = app(ActivityAnalytics::class)->report($habit->user, 2026);
        $entries = $habit->entries()->orderBy('id')->get()->toArray();

        $page = Livewire::actingAs($habit->user)->test(ActivityHeatmap::class)
            ->assertSee('Calendar key')->assertSee('activity-calendar-minimal', false)
            ->assertDontSee('PRIVATE-CALENDAR-NOTE')->assertDontSee('PRIVATE-CALENDAR-REASON');
        $document = new \DOMDocument;
        // libxml's HTML4 parser does not understand Alpine's @/: shorthand.
        $html = preg_replace('/\s[@:]([\w.-]+)=/', ' data-alpine-$1=', $page->html());
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $cells = $xpath->query('//button[@data-date]');
        $this->assertCount(365, $cells);
        foreach ($cells as $cell) {
            $this->assertSame('', trim($cell->textContent));
            $this->assertStringContainsString($cell->getAttribute('data-date'), $cell->getAttribute('aria-label'));
            $this->assertNotEmpty($cell->getAttribute('data-preview-outcome'));
        }
        $zero = $xpath->query('//button[@data-date="2026-09-27"]')->item(0);
        $this->assertSame('recorded', $zero->getAttribute('data-state'));
        $this->assertStringContainsString('0.0% performance', $zero->getAttribute('aria-label'));
        $missing = $xpath->query('//button[@data-date="2026-09-25"]')->item(0);
        $this->assertSame('unlogged', $missing->getAttribute('data-state'));
        $this->assertStringContainsString('Not logged', $missing->getAttribute('data-preview-outcome'));
        $neutral = $xpath->query('//button[@data-date="2026-09-26"]')->item(0);
        $this->assertStringContainsString('No performance score', $neutral->getAttribute('data-preview-outcome'));
        $page->call('selectDay', '2026-09-27')->assertSee('0.0% performance')
            ->call('changeYear', -1)->call('changeYear', 0);
        $this->assertSame($before, app(ActivityAnalytics::class)->report($habit->user, 2026));
        $this->assertSame($entries, $habit->entries()->orderBy('id')->get()->toArray());
    }

    public function test_refinement_is_scoped_to_dashboard_and_does_not_expose_other_activity(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 27));
        $owner = User::factory()->create(['is_private' => false]);
        $habit = $this->habit(user: $owner);
        $this->editor($habit)->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        Livewire::actingAs($owner)->test(ActivityHeatmap::class, ['habitId' => $habit->id])
            ->assertDontSee('activity-calendar-minimal', false)->assertDontSee('data-preview-outcome', false)->assertSee('✓');
        $viewer = User::factory()->create();
        Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id])
            ->assertDontSee('activity-calendar-minimal', false)->assertDontSee('data-preview-outcome', false)
            ->assertDontSee('100.0% performance')->assertSee('No eligible data');
        Livewire::actingAs($viewer)->test(ActivityHeatmap::class)
            ->assertSee('activity-calendar-minimal', false)->assertDontSee('100.0% performance');
    }
}
