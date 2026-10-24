<?php

use App\Actions\Habits\SaveHabit;
use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Models\Category;
use App\Models\User;
use App\Services\TrackingDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

if (getenv('HABIT_LOG_E2E') !== '1' || app()->configurationIsCached() || config('database.connections.mysql.database') !== 'habit_log_test' || config('database.connections.mysql.url') || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
    throw new RuntimeException('Test database only');
}
if (! is_dir('storage/app/entry-history-refinements')) {
    mkdir('storage/app/entry-history-refinements', 0755, true);
}
$today = Carbon::now('UTC')->startOfDay();
$owner = User::factory()->create(['email' => 'history-review@example.test', 'password' => bcrypt('Browser-test-123!'), 'username' => 'historyreview', 'timezone' => 'UTC']);
$other = User::factory()->create(['email' => 'history-other@example.test', 'password' => bcrypt('Browser-test-123!'), 'username' => 'historyother']);
$category = Category::query()->whereNull('user_id')->firstOrFail();
$makeHabit = function ($type, $name, $overrides = []) use ($owner, $category, $today) {

    return app(SaveHabit::class)->handle($owner, null, $overrides + ['name' => $name, 'category_id' => $category->id, 'type' => $type, 'visibility' => 'public', 'description' => null, 'goal_value' => in_array($type, ['count', 'duration']) ? 10 : ($type === 'avoidance' ? 2 : null), 'goal_unit' => $type === 'duration' ? 'minutes' : (in_array($type, ['count', 'avoidance']) ? 'cups' : null), 'start_date' => $today->copy()->subDays(90)->toDateString(), 'end_date' => null, 'checkpoints' => $type === 'checkpoint' ? [['label' => 'Read'], ['label' => 'Reflect'], ['label' => 'Plan']] : []]);
};
$record = function ($habit, $date, $status, $value = null, $checked = 0) use ($owner) {
    $v = app(TrackingDate::class)->version($habit, $date);
    $entry = $habit->entries()->where('entry_date', $date)->first();

    return app(SaveHabitEntry::class)->handle($owner, $habit->id, $date, ['status' => $status, 'value' => $value, 'completed' => $v->checkpoints->take($checked)->pluck('id')->all(), 'note' => 'PRIVATE-HISTORY-NOTE', 'not_applicable_reason' => $status === 'not_applicable' ? 'PRIVATE-HISTORY-REASON' : null], $entry?->revision, $v->id, $v->revision, $owner->timezone);
};
$ids = [];
foreach (['boolean', 'count', 'duration', 'checkpoint', 'avoidance'] as $type) {
    $h = $makeHabit($type, 'History review — '.$type);
    $ids[$type] = $h->id;
    foreach ([0, 1, 2, 3] as $i) {
        $status = ['completed', $type === 'boolean' ? 'missed' : 'partial', 'missed', 'not_applicable'][$i];
        $record($h, $today->copy()->subDays($i)->toDateString(), $status, in_array($type, ['count', 'duration', 'avoidance']) ? ([5, 10, 0, null][$i]) : null, $type === 'checkpoint' ? ([3, 1, 0, 0][$i]) : 0);
    }
}
$empty = $makeHabit('boolean', 'No saved activity yet');
$ids['empty'] = $empty->id;
$long = $makeHabit('count', str_repeat('LongHistory', 9), ['goal_unit' => str_repeat('W', 30)]);
$ids['long'] = $long->id;
// A configuration change in the past creates a real dated version boundary.
Carbon::setTestNow($today->copy()->subDays(25)->setTime(12, 0));
for ($i = 44; $i >= 25; $i--) {
    $record($long, $today->copy()->subDays($i)->toDateString(), 'partial', 5);
}
app(SaveHabit::class)->handle($owner, $long->id, $long->fresh()->attributesToArray() + ['checkpoints' => []]);
$data = $long->fresh()->attributesToArray();
$data['goal_value'] = 20;
$data['goal_unit'] = 'pages';
$data['checkpoints'] = [];
app(SaveHabit::class)->handle($owner, $long->id, $data);
Carbon::setTestNow();
for ($i = 24; $i >= 0; $i--) {
    $record($long, $today->copy()->subDays($i)->toDateString(), 'completed', 10);
}
$archived = $makeHabit('checkpoint', 'Archived history');
$record($archived, $today->copy()->subDays(3)->toDateString(), 'partial', null, 1);
$trashed = $makeHabit('boolean', 'Trashed history');
$record($trashed, $today->copy()->subDays(3)->toDateString(), 'completed');
$restored = $makeHabit('duration', 'Restored history');
$record($restored, $today->copy()->subDays(3)->toDateString(), 'partial', 5);
Carbon::setTestNow($today->copy()->subDays(2)->setTime(12, 0));
foreach ([[$archived, 'archive'], [$trashed, 'delete'], [$restored, 'delete']] as [$h,$a]) {
    app(TransitionHabit::class)->handle($owner, $h->id, $a);
}
Carbon::setTestNow();
app(TransitionHabit::class)->handle($owner, $restored->id, 'restore');
foreach (compact('archived', 'trashed', 'restored') as $k => $h) {
    $ids[$k] = $h->id;
}
// Legal saved date which becomes future-local after timezone travel.
$owner->update(['timezone' => 'Pacific/Kiritimati']);
Carbon::setTestNow($today->copy()->setTime(12, 0));
$travel = $makeHabit('checkpoint', 'Timezone travel history');
$future = $today->copy()->addDay()->toDateString();
$record($travel, $future, 'partial', null, 1);
Carbon::setTestNow();
$owner->update(['timezone' => 'UTC']);
$ids['travel'] = $travel->id;
file_put_contents('storage/app/entry-history-refinements/fixtures.json', json_encode(['ids' => $ids, 'today' => $today->toDateString(), 'future' => $future], JSON_PRETTY_PRINT));
echo "Synthetic history fixtures prepared via application actions.\n";
