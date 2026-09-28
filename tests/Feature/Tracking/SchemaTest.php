<?php

namespace Tests\Feature\Tracking;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class SchemaTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_unique_daily_key_and_historical_foreign_keys_protect_records(): void
    {
        $habit = $this->habit('checkpoint');
        $this->editor($habit)->call('save')->assertHasNoErrors();
        $entry = $habit->entries()->sole();
        foreach ([
            fn () => DB::table('habit_entries')->insert(array_diff_key($entry->getAttributes(), ['id' => true])),
            fn () => $habit->versions()->delete(),
            fn () => $entry->version->checkpoints()->delete(),
            fn () => $habit->forceDelete(),
            fn () => DB::table('habit_entries')->where('id', $entry->id)->update(['habit_tracking_version_id' => 999999]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Constraint must reject invalid change.');
            } catch (QueryException) {
                $this->assertDatabaseHas('habit_entries', ['id' => $entry->id]);
            }
        }
        $habit->checkpoints()->delete();
        $this->assertSame(2, $entry->version->checkpoints()->count());
        $this->assertSame(2, $entry->checkpoints()->count());
        $this->assertSame(0, $entry->version->checkpoints()->whereNotNull('habit_checkpoint_id')->count());
    }

    public function test_suspension_constraint_and_entry_child_uniqueness(): void
    {
        $habit = $this->habit('checkpoint');
        $this->editor($habit)->call('save')->assertHasNoErrors();
        $child = $habit->entries()->sole()->checkpoints()->first();
        try {
            DB::table('habit_suspensions')->insert(['habit_id' => $habit->id, 'reason' => 'archive', 'start_date' => '2026-09-27', 'end_date' => '2026-09-26']);
            $this->fail('Negative intervals must fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('habit_suspensions', 0);
        }
        try {
            DB::table('habit_checkpoint_entries')->insert(array_diff_key($child->getAttributes(), ['id' => true]));
            $this->fail('Duplicate checkpoint must fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('habit_checkpoint_entries', 2);
        }
    }
}
