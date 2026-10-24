<?php

namespace Tests\Feature\Tracking;

use App\Models\HabitCheckpointEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class EntryTest extends TrackingTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 27)->setTime(12, 0));
    }

    public static function types(): array
    {
        return [['boolean', null, 'completed'], ['count', 0, 'missed'], ['count', 8, 'partial'], ['duration', 30, 'completed'], ['avoidance', 0, 'completed'], ['avoidance', 3, 'partial'], ['checkpoint', null, 'partial']];
    }

    #[DataProvider('types')]
    public function test_types_save_and_reload_without_changing_other_dates(string $type, ?int $value, string $status): void
    {
        $habit = $this->habit($type, $type === 'avoidance' ? ['goal_value' => 0] : []);
        $page = $this->editor($habit)->assertSee('Private note')->set('form.status', $status)->set('form.note', ' 0 ');
        if ($value !== null) {
            $page->set('form.value', $value);
        }
        if ($type === 'checkpoint') {
            $page->set('form.completed', [$habit->versions()->first()->checkpoints()->first()->id]);
        }
        $page->call('save')->assertHasNoErrors()->assertSee('Activity saved.');
        $entry = $habit->entries()->sole();
        $this->assertSame($status, $entry->status->value);
        $this->assertSame($value, $entry->value);
        $this->assertSame('0', $entry->note);
        $this->editor($habit)->assertSet('form.note', '0')->assertSet('entryRevision', 1);
        $page->set('form.note', 'Changed')->call('save')->assertHasNoErrors();
        $this->assertSame(2, $entry->fresh()->revision);
        $this->editor($habit, '2026-09-26')->assertSet('entryRevision', null);
        $this->assertDatabaseCount('habit_entries', 1);
    }

    public static function invalid(): array
    {
        return [
            ['count', 'value', '', 'value'], ['count', 'value', -1, 'value'], ['count', 'value', 1.5, 'value'],
            ['duration', 'value', '1e2', 'value'], ['avoidance', 'value', true, 'value'], ['count', 'value', [], 'value'],
            ['count', 'value', '1000000000', 'value'], ['count', 'value', 'no', 'value'],
            ['boolean', 'status', 'partial', 'status'], ['boolean', 'status', 'invented', 'status'],
            ['boolean', 'note', str_repeat('x', 2001), 'note'], ['boolean', 'not_applicable_reason', str_repeat('x', 501), 'not_applicable_reason'],
            ['boolean', 'value', 10, 'value'], ['checkpoint', 'completed', [999999], 'completed.0'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_entries_make_no_writes(string $type, string $field, mixed $value, string $error): void
    {
        $habit = $this->habit($type);
        $this->editor($habit)->set('form.status', 'completed')->set('form.value', $type === 'count' || $type === 'duration' || $type === 'avoidance' ? 1 : '')
            ->set('form.'.$field, $value)->call('save')->assertHasErrors('form.'.$error);
        $this->assertDatabaseCount('habit_entries', 0);
        $this->assertDatabaseCount('habit_checkpoint_entries', 0);
    }

    public function test_na_preserves_raw_values_and_zero_reason_and_does_not_invent_zero(): void
    {
        $habit = $this->habit('count');
        $page = $this->editor($habit)->set('form.value', 12)->set('form.status', 'partial')->set('form.note', 'Private detail')->call('save')->assertHasNoErrors();
        $page->set('form.status', 'not_applicable')->set('form.not_applicable_reason', ' 0 ')->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole();
        $this->assertSame(12, $entry->value);
        $this->assertSame('0', $entry->not_applicable_reason);
        $page->set('form.status', 'completed')->call('save')->assertHasNoErrors();
        $this->assertNull($entry->fresh()->not_applicable_reason);
        $this->editor($habit, '2026-09-26')->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors()->set('form.status', 'completed')->call('save')->assertHasErrors('form.value');
        $this->assertNull($habit->entries()->where('entry_date', '2026-09-26')->first()->value);
        $this->assertArrayNotHasKey('note', $entry->toArray());
        $this->actingAs($habit->user)->get(route('tracking.index'))->assertDontSee('Private detail');
        $this->get(route('entries.index', $habit))->assertDontSee('Private detail');
    }

    public function test_checkpoint_outcomes_timestamps_and_na_transitions(): void
    {
        $habit = $this->habit('checkpoint');
        $ids = $habit->versions()->first()->checkpoints->modelKeys();
        $page = $this->editor($habit)->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole();
        $this->assertSame('missed', $entry->status->value);
        $this->assertSame(2, $entry->checkpoints()->count());
        $page->set('form.completed', [$ids[0]])->call('save')->assertHasNoErrors();
        $this->assertSame('partial', $entry->fresh()->status->value);
        $timestamp = $entry->checkpoints()->where('is_completed', true)->first()->completed_at;
        $this->travel(5)->minutes();
        $page->set('form.completed', $ids)->call('save')->assertHasNoErrors();
        $this->assertSame('completed', $entry->fresh()->status->value);
        $this->assertTrue($timestamp->equalTo($entry->checkpoints()->first()->completed_at));
        $page->set('form.status', 'not_applicable')->call('save')->assertHasNoErrors();
        $this->assertSame(2, $entry->checkpoints()->where('is_completed', true)->count());
        $page->set('form.status', 'missed')->set('form.completed', [])->call('save')->assertHasNoErrors();
        $this->assertSame(0, $entry->checkpoints()->whereNotNull('completed_at')->count());
        $page->set('form.completed', [$ids[0], $ids[0]])->call('save')->assertHasErrors('form.completed.0');
    }

    public function test_stale_first_save_and_update_are_rejected_with_reload_recovery(): void
    {
        $habit = $this->habit();
        $first = $this->editor($habit)->set('form.status', 'completed');
        $second = $this->editor($habit)->set('form.status', 'missed');
        $first->call('save')->assertHasNoErrors();
        $second->call('save')->assertHasErrors('conflict');
        $second->call('loadEntry')->assertSet('form.status', 'completed')->set('form.status', 'missed')->call('save')->assertHasNoErrors();
        $first->set('form.status', 'completed')->call('save')->assertHasErrors('conflict');
        $this->assertSame('missed', $habit->entries()->sole()->status->value);
    }

    public function test_checkpoint_failure_rolls_back_entry_and_children(): void
    {
        $habit = $this->habit('checkpoint');
        HabitCheckpointEntry::creating(function () {
            throw new \RuntimeException('Controlled child failure');
        });
        try {
            $this->editor($habit)->call('save');
            $this->fail('Expected child failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Controlled child failure', $e->getMessage());
        } finally {
            HabitCheckpointEntry::flushEventListeners();
        }
        $this->assertDatabaseCount('habit_entries', 0);
        $this->assertDatabaseCount('habit_checkpoint_entries', 0);
    }

    public function test_routes_and_actions_enforce_owner_for_all_visibility_preferences(): void
    {
        foreach (['private', 'followers', 'public'] as $visibility) {
            $habit = $this->habit('count', ['visibility' => $visibility]);
            $this->editor($habit)->set('form.status', 'completed')->set('form.value', 1)->set('form.note', 'Secret note')->call('save')->assertHasNoErrors();
            $entry = $habit->entries()->sole();
            $before = $entry->getAttributes();
            $other = User::factory()->create();
            $this->actingAs($other)->get(route('entries.edit', ['habit' => $habit, 'date' => '2026-09-27']))->assertNotFound();
            $this->get(route('entries.index', $habit))->assertNotFound();
            $this->get(route('tracking.index'))->assertDontSee('Daily practice')->assertDontSee('Secret note');
            Livewire::actingAs($other)->test('pages::tracking.edit', ['habit' => $habit->id, 'date' => '2026-09-27'])
                ->assertNotFound()->assertDontSee('Secret note');
            $this->assertSame($before, $entry->refresh()->getAttributes());
            $this->assertSame(1, $habit->entries()->count());
        }
        auth()->logout();
        $this->get(route('tracking.index'))->assertRedirect(route('login'));
        $this->get(route('entries.index', $habit))->assertRedirect(route('login'));
        $this->get(route('entries.edit', ['habit' => $habit, 'date' => '2026-09-27']))->assertRedirect(route('login'));
    }

    public function test_foreign_session_cannot_read_or_overwrite_saved_checkpoint_entry_from_owner_snapshot(): void
    {
        foreach (['private', 'followers', 'public'] as $visibility) {
            $habit = $this->habit('checkpoint', ['visibility' => $visibility]);
            $ids = $habit->versions()->sole()->checkpoints->modelKeys();
            $this->editor($habit)->set('form.completed', [$ids[0]])->set('form.note', 'Protected checkpoint note')->call('save')->assertHasNoErrors();
            $entry = $habit->entries()->sole();
            $before = $entry->getAttributes();
            $children = $entry->checkpoints()->orderBy('id')->get()->map->getAttributes()->all();
            $response = $this->actingAs($habit->user)->get(route('entries.edit', ['habit' => $habit, 'date' => '2026-09-27']))->assertOk();
            preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
            $this->assertNotEmpty($matches[1] ?? null);
            $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

            $this->actingAs(User::factory()->create());
            foreach (['loadEntry', 'save'] as $method) {
                $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), ['components' => [[
                    'snapshot' => $snapshot,
                    'updates' => ['form.completed' => $ids, 'form.note' => 'Unauthorized replacement'],
                    'calls' => [['path' => '', 'method' => $method, 'params' => []]],
                ]]])->assertForbidden()->assertDontSee('Protected checkpoint note');
                $this->assertSame($before, $entry->refresh()->getAttributes());
                $this->assertSame($children, $entry->checkpoints()->orderBy('id')->get()->map->getAttributes()->all());
                $this->assertSame(1, $habit->entries()->count());
            }
        }
    }

    public function test_html_is_escaped_and_numeric_limit_and_private_text_round_trip(): void
    {
        $habit = $this->habit('duration');
        $this->editor($habit)->set('form.status', 'completed')->set('form.value', '999999999')->set('form.note', '<script>alert(1)</script>')->call('save')->assertHasNoErrors();
        $this->get(route('entries.edit', ['habit' => $habit, 'date' => '2026-09-27']))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(999999999, $habit->entries()->sole()->value);
    }
}
