<?php

namespace Tests\Feature\Social;

use App\Actions\Follows\FollowUser;
use App\Actions\Follows\TransitionFollow;
use App\Enums\FollowStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_requests_are_directional_idempotent_and_require_target_approval(): void
    {
        [$actor, $target] = User::factory()->count(2)->create()->all();
        $follow = app(FollowUser::class)->handle($actor, $target->id);
        $this->assertSame(FollowStatus::Pending, $follow->status);
        $this->assertNull($follow->accepted_at);
        $this->assertSame($follow->id, app(FollowUser::class)->handle($actor, $target->id)->id);
        $this->assertSame(0, $target->following()->count());
        app(TransitionFollow::class)->handle($target, $follow->id, 'approve');
        $this->assertSame(FollowStatus::Accepted, $follow->fresh()->status);
        $this->assertNotNull($follow->fresh()->accepted_at);
        app(TransitionFollow::class)->handle($actor, $follow->id, 'unfollow');
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_privacy_changes_keep_pending_and_accepted_rows_and_only_new_public_follows_auto_accept(): void
    {
        [$actor, $target, $other] = User::factory()->count(3)->create()->all();
        $pending = app(FollowUser::class)->handle($actor, $target->id);
        $before = $pending->fresh()->getAttributes();
        Livewire::actingAs($target)->test('pages::settings.profile')->set('is_private', false)->call('save')->assertHasNoErrors();
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertSame(FollowStatus::Pending, app(FollowUser::class)->handle($actor, $target->id)->status);
        $accepted = app(FollowUser::class)->handle($other, $target->id);
        $this->assertSame(FollowStatus::Accepted, $accepted->status);
        Livewire::actingAs($target)->test('pages::settings.profile')->set('is_private', true)->call('save')->assertHasNoErrors();
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertSame(FollowStatus::Accepted, $accepted->fresh()->status);
        app(TransitionFollow::class)->handle($target, $accepted->id, 'remove');
        app(TransitionFollow::class)->handle($target, $pending->id, 'reject');
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_stale_actions_cannot_change_recreated_request_or_accepted_state(): void
    {
        [$actor, $target] = User::factory()->count(2)->create()->all();
        $old = app(FollowUser::class)->handle($actor, $target->id);
        app(TransitionFollow::class)->handle($actor, $old->id, 'cancel');
        $new = app(FollowUser::class)->handle($actor, $target->id);
        foreach (['approve', 'reject'] as $operation) {
            try {
                app(TransitionFollow::class)->handle($target, $old->id, $operation);
                $this->fail('Stale request must fail.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('relationship', $e->errors());
            }
        }
        app(TransitionFollow::class)->handle($target, $new->id, 'approve');
        try {
            app(TransitionFollow::class)->handle($actor, $new->id, 'cancel');
            $this->fail('Cannot cancel accepted request.');
        } catch (ValidationException $e) {
            $this->assertSame(FollowStatus::Accepted, $new->fresh()->status);
        }
    }

    public function test_foreign_actor_self_follow_and_missing_usernames_are_denied(): void
    {
        [$actor, $target, $other] = User::factory()->count(3)->create()->all();
        $follow = app(FollowUser::class)->handle($actor, $target->id);
        foreach (['approve', 'reject', 'cancel', 'unfollow', 'remove'] as $operation) {
            try {
                app(TransitionFollow::class)->handle($other, $follow->id, $operation);
                $this->fail('Foreign actor must fail.');
            } catch (AuthorizationException) {
                $this->assertSame(FollowStatus::Pending, $follow->fresh()->status);
            }
        }
        foreach ([[$actor, $actor], [User::factory()->create(['username' => null]), $target], [$actor, User::factory()->create(['username' => null])]] as [$from, $to]) {
            try {
                app(FollowUser::class)->handle($from, $to->id);
                $this->fail('Invalid follow must fail.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('follows', 1);
            }
        }
    }
}
