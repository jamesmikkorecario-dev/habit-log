<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Query-builder backfill deliberately does not depend on evolving application models.
        DB::table('habits')->orderBy('id')->chunkById(100, function ($habits) {
            foreach ($habits as $habit) {
                DB::transaction(function () use ($habit) {
                    $version = DB::table('habit_tracking_versions')->where('habit_id', $habit->id)->first();
                    if (! $version) {
                        $id = DB::table('habit_tracking_versions')->insertGetId([
                            'habit_id' => $habit->id, 'effective_date' => $habit->start_date,
                            'type' => $habit->type, 'goal_value' => $habit->goal_value, 'goal_unit' => $habit->goal_unit,
                            'start_date' => $habit->start_date, 'end_date' => $habit->end_date,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        foreach (DB::table('habit_checkpoints')->where('habit_id', $habit->id)->orderBy('sort_order')->orderBy('id')->get() as $order => $checkpoint) {
                            DB::table('habit_tracking_checkpoints')->insert([
                                'habit_tracking_version_id' => $id, 'habit_checkpoint_id' => $checkpoint->id,
                                'label' => $checkpoint->label, 'sort_order' => $order,
                            ]);
                        }
                    }
                    foreach (['archive' => $habit->archived_at, 'trash' => $habit->deleted_at] as $reason => $timestamp) {
                        if ($timestamp && ! DB::table('habit_suspensions')->where('habit_id', $habit->id)->where('reason', $reason)->exists()) {
                            DB::table('habit_suspensions')->insert([
                                'habit_id' => $habit->id, 'reason' => $reason,
                                'start_date' => Carbon::parse($timestamp, 'UTC')->addDay()->toDateString(),
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    }
                });
            }
        });
    }

    public function down(): void
    {
        // History is removed only by the schema migration's down(), never by replaying backfill.
    }
};
