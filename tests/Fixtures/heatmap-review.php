<?php

use App\Actions\Habits\SaveHabit;
use App\Actions\Habits\SaveHabitEntry;
use App\Models\Category;
use App\Models\User;
use App\Services\TrackingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

if (getenv('HABIT_LOG_E2E') !== '1' || ! app()->environment('local') || app()->configurationIsCached()
    || config('database.default') !== 'mysql' || config('database.connections.mysql.url')
    || config('database.connections.mysql.database') !== 'habit_log_test'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
    throw new RuntimeException('Heatmap review requires the isolated browser test database.');
}

$heatmapOwner = User::factory()->create(['email' => 'heatmap-review@example.test', 'password' => bcrypt('Browser-test-123!'), 'username' => 'heatmapreview', 'display_name' => 'Alex', 'timezone' => 'UTC']);
User::factory()->create(['email' => 'heatmap-empty@example.test', 'password' => bcrypt('Browser-test-123!'), 'username' => 'heatmapempty', 'display_name' => 'Alex', 'timezone' => 'UTC']);
$heatmapHabit = app(SaveHabit::class)->handle($heatmapOwner, null, [
    'name' => 'Read a few pages', 'category_id' => Category::whereNull('user_id')->firstOrFail()->id,
    'type' => 'count', 'visibility' => 'private', 'description' => null,
    'goal_value' => 10, 'goal_unit' => 'pages', 'start_date' => '2024-01-08', 'end_date' => null, 'checkpoints' => [],
]);
// Deterministic leap-year history: all five bands, N/A, missing, and neutral.
for ($index = 0; $index < 224; $index++) {
    $date = CarbonImmutable::parse('2024-01-08')->addDays($index)->toDateString();
    $outcome = $index % 8;
    if ($outcome === 6) {
        continue;
    }
    $version = app(TrackingDate::class)->version($heatmapHabit, $date);
    $entry = app(SaveHabitEntry::class)->handle($heatmapOwner, $heatmapHabit->id, $date, [
        'status' => $outcome === 5 ? 'not_applicable' : ($outcome === 0 ? 'missed' : 'completed'),
        'value' => [0, 2, 4, 7, 10, null, null, 5][$outcome], 'completed' => [],
        'note' => 'PRIVATE-HEATMAP-NOTE', 'not_applicable_reason' => $outcome === 5 ? 'PRIVATE-HEATMAP-REASON' : null,
    ], null, $version->id, $version->revision, $heatmapOwner->timezone);
    if ($outcome === 7) {
        // Deliberately damaged synthetic measurements exercise unavailable
        // presentation. Production actions never create this invalid state.
        DB::table('habit_entries')->where('id', $entry->id)->update(['value' => null]);
    }
}
echo "Prepared isolated heatmap review data.\n";
