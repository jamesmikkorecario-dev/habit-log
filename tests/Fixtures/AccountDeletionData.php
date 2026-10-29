<?php

namespace Tests\Fixtures;

use App\Actions\Habits\SaveHabit;
use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Models\Category;
use App\Models\User;
use App\Services\TrackingDate;
use Illuminate\Support\Facades\DB;

class AccountDeletionData
{
    public static function populate(User $owner): array
    {
        if (DB::connection()->getDatabaseName() !== 'habit_log_test' || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
            throw new \RuntimeException('Synthetic account-deletion data requires habit_log_test.');
        }
        $category = Category::factory()->create(['user_id' => $owner->id]);
        $category->aliases()->create(['alias' => 'Synthetic alias', 'normalized_alias' => 'synthetic alias']);
        $habits = [];
        foreach (['active', 'archive', 'delete'] as $lifecycle) {
            $habit = app(SaveHabit::class)->handle($owner, null, [
                'name' => 'Deletion '.$lifecycle, 'category_id' => $category->id, 'type' => 'checkpoint',
                'visibility' => 'public', 'description' => 'Synthetic description', 'goal_value' => null, 'goal_unit' => null,
                'start_date' => '2024-01-01', 'end_date' => null, 'checkpoints' => [['label' => 'Read'], ['label' => 'Reflect']],
            ]);
            $version = app(TrackingDate::class)->version($habit, '2024-01-02');
            app(SaveHabitEntry::class)->handle($owner, $habit->id, '2024-01-02', [
                'status' => 'completed', 'completed' => $version->checkpoints->pluck('id')->all(),
                'value' => null, 'note' => 'DELETE-PRIVATE-NOTE', 'not_applicable_reason' => null,
            ], null, $version->id, $version->revision, $owner->timezone);
            if ($lifecycle !== 'active') {
                app(TransitionHabit::class)->handle($owner, $habit->id, $lifecycle);
            }
            $habits[] = $habit->id;
        }
        DB::table('journals')->insert(['user_id' => $owner->id, 'journal_date' => '2024-01-02', 'content' => 'DELETE-PRIVATE-JOURNAL', 'mood' => 4, 'revision' => 1]);

        return $habits;
    }
}
