<?php

use App\Actions\Accounts\EmailChanges;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\Fixtures\AccountDeletionData;

if (getenv('HABIT_LOG_E2E') !== '1' || ! app()->environment('local') || app()->configurationIsCached()
    || config('database.connections.mysql.url') || config('database.default') !== 'mysql'
    || DB::selectOne('SELECT DATABASE() AS name')->name !== 'habit_log_test') {
    throw new RuntimeException('Isolated browser database required.');
}
$deletionOwner = User::factory()->create(['email' => 'delete-owner@example.test', 'username' => 'deletionowner', 'password' => bcrypt('Browser-test-123!'), 'is_private' => false]);
$deletionForeign = User::factory()->create(['email' => 'delete-foreign@example.test', 'username' => 'deletionforeign', 'password' => bcrypt('Browser-test-123!'), 'is_private' => false]);
$ownerHabits = AccountDeletionData::populate($deletionOwner);
$foreignHabits = AccountDeletionData::populate($deletionForeign);
DB::table('follows')->insert(['follower_id' => $deletionForeign->id, 'followed_id' => $deletionOwner->id, 'status' => 'accepted', 'requested_at' => now(), 'accepted_at' => now()]);
Auth::setUser($deletionOwner);
[, $deletionEmailLink] = app(EmailChanges::class)->request($deletionOwner, ['current_password' => 'Browser-test-123!', 'new_email' => $deletionForeign->email, 'generation' => 'none']);
$deletionResetToken = Password::createToken($deletionOwner);
Auth::forgetGuards();
$deletionDirectory = base_path('storage/app/account-deletion');
if (! is_dir($deletionDirectory)) {
    mkdir($deletionDirectory, 0700, true);
}
file_put_contents($deletionDirectory.'/fixtures.json', json_encode(['ownerId' => $deletionOwner->id, 'ownerHabit' => $ownerHabits[0], 'foreignHabit' => $foreignHabits[0], 'resetToken' => $deletionResetToken, 'emailLink' => $deletionEmailLink], JSON_THROW_ON_ERROR));
chmod($deletionDirectory.'/fixtures.json', 0600);
echo "Prepared isolated account deletion fixtures.\n";
