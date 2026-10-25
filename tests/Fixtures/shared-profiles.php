<?php

use App\Actions\Follows\FollowUser;
use App\Actions\Follows\TransitionFollow;
use App\Actions\Habits\SaveHabit;
use App\Actions\Habits\SaveHabitEntry;
use App\Actions\Habits\TransitionHabit;
use App\Models\Category;
use App\Models\Journal;
use App\Models\User;
use App\Services\TrackingDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

if (getenv('HABIT_LOG_E2E') !== '1' || app()->configurationIsCached() || config('database.connections.mysql.database') !== 'habit_log_test' || config('database.connections.mysql.url') || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
    throw new RuntimeException('Test database only');
}
if (! is_dir('storage/app/profiles-shared-refinements')) {
    mkdir('storage/app/profiles-shared-refinements', 0755, true);
}
$today = Carbon::now('UTC')->startOfDay();
$users = [];
foreach (['public', 'private', 'empty', 'long', 'paged', 'viewer', 'follower', 'pending', 'nohandle'] as $key) {
    $users[$key] = User::factory()->create(['name' => 'REGISTRATION-SECRET', 'email' => 'profile-'.$key.'@example.test', 'username' => $key === 'nohandle' ? null : ($key === 'long' ? str_repeat('w', 30) : 'review'.$key), 'display_name' => $key === 'long' ? str_repeat('W', 50) : 'Review '.ucfirst($key), 'bio' => $key === 'long' ? str_repeat('W', 160) : ($key === 'private' ? 'PRIVATE-BIO-CANARY' : 'Shared review bio'), 'is_private' => $key === 'private', 'timezone' => 'UTC', 'password' => bcrypt('Browser-test-123!')]);
}
$category = Category::whereNull('user_id')->firstOrFail();
$make = function ($owner, $name, $type = 'boolean', $visibility = 'public', $over = []) use ($category, $today) {
    return app(SaveHabit::class)->handle($owner, null, $over + ['name' => $name, 'category_id' => $category->id, 'description' => 'Shared habit description.', 'type' => $type, 'visibility' => $visibility, 'goal_value' => $type === 'count' ? 10 : null, 'goal_unit' => $type === 'count' ? 'cups' : null, 'start_date' => $today->copy()->subDays(4)->toDateString(), 'end_date' => null, 'checkpoints' => $type === 'checkpoint' ? [['label' => 'CHECKPOINT-SECRET-A'], ['label' => 'CHECKPOINT-SECRET-B']] : []]);
};
$record = function ($owner, $habit, $date, $status, $value = null, $checked = 0) {
    $version = app(TrackingDate::class)->version($habit, $date);

    return app(SaveHabitEntry::class)->handle($owner, $habit->id, $date, ['status' => $status, 'value' => $value, 'completed' => $version->checkpoints->take($checked)->pluck('id')->all(), 'note' => 'NOTE-SECRET-CANARY', 'not_applicable_reason' => $status === 'not_applicable' ? 'REASON-SECRET-CANARY' : null], null, $version->id, $version->revision, 'UTC');
};
$habits = [];
$u = $users['public'];
Carbon::setTestNow($today->copy()->subDays(4)->setTime(12, 0));
$h = $make($u, 'Read pages', 'count');
$habits['historical'] = $h->id;
$record($u, $h, $today->copy()->subDays(4)->toDateString(), 'completed', 5);
Carbon::setTestNow($today->copy()->subDays(3)->setTime(12, 0));
$record($u, $h, $today->copy()->subDays(3)->toDateString(), 'completed', 10);
app(SaveHabit::class)->handle($u, $h->id, ['goal_value' => 20] + $h->fresh()->attributesToArray() + ['checkpoints' => []]);
Carbon::setTestNow();
$record($u, $h, $today->copy()->subDays(2)->toDateString(), 'missed', 0);
$record($u, $h, $today->copy()->subDay()->toDateString(), 'not_applicable');
$record($u, $h, $today->toDateString(), 'completed', 10);
foreach (['followers', 'private'] as $v) {
    $h = $make($u, $v === 'private' ? 'PRIVATE-HABIT-CANARY' : 'Follower practice', 'boolean', $v);
    $habits[$v] = $h->id;
    for ($i = 0; $i < 5; $i++) {
        $record($u, $h, $today->copy()->subDays($i)->toDateString(), $v === 'private' ? 'completed' : 'missed');
    }
}
$h = $make($u, 'Checkpoint practice', 'checkpoint');
$habits['checkpoint'] = $h->id;
$record($u, $h, $today->toDateString(), 'partial', null, 1);
$h = $make($u, 'Archived practice');
$habits['archived'] = $h->id;
$record($u, $h, $today->copy()->subDays(4)->toDateString(), 'completed');
app(TransitionHabit::class)->handle($u, $h->id, 'archive');
$h = $make($u, 'Ended practice', 'boolean', 'public', ['end_date' => $today->copy()->subDays(2)->toDateString()]);
$habits['ended'] = $h->id;
$record($u, $h, $today->copy()->subDays(3)->toDateString(), 'completed');
$h = $make($u, 'TRASHED-HABIT-CANARY');
$habits['trashed'] = $h->id;
app(TransitionHabit::class)->handle($u, $h->id, 'delete');
Journal::factory()->for($u)->create(['journal_date' => $today->toDateString(), 'content' => 'JOURNAL-SECRET-CANARY', 'mood' => 5]);
foreach (['public', 'followers'] as $v) {
    $h = $make($users['private'], 'Private account '.$v, 'boolean', $v);
    $habits['private_'.$v] = $h->id;
    $record($users['private'], $h, $today->toDateString(), 'completed');
}
$h = $make($users['long'], str_repeat('W', 100), 'count', 'public', ['goal_unit' => str_repeat('W', 30), 'description' => str_repeat('LongDescription', 133).'ABCDE']);
$habits['long'] = $h->id;
$record($users['long'], $h, $today->toDateString(), 'completed', 5);
for ($i = 1; $i <= 25; $i++) {
    $make($users['paged'], 'Paged habit '.$i);
}
foreach (['public', 'private'] as $key) {
    $follow = app(FollowUser::class)->handle($users['follower'], $users[$key]->id);
    if ($key === 'private') {
        app(TransitionFollow::class)->handle($users[$key], $follow->id, 'approve');
    }
}
app(FollowUser::class)->handle($users['pending'], $users['private']->id);
file_put_contents('storage/app/profiles-shared-refinements/fixtures.json', json_encode(['users' => array_map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'email' => $u->email], $users), 'habits' => $habits, 'today' => $today->toDateString(), 'old' => $today->copy()->subDays(4)->toDateString(), 'na' => $today->copy()->subDay()->toDateString()], JSON_PRETTY_PRINT));
echo "Prepared synthetic profile review data.\n";
