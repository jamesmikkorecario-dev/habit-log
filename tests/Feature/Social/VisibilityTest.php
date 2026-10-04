<?php

namespace Tests\Feature\Social;

use App\Models\Follow;
use App\Models\Habit;
use App\Models\User;
use App\Services\SocialAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_query_and_routes_match_full_account_relationship_visibility_lifecycle_matrix(): void
    {
        $owner = User::factory()->create();
        $accepted = User::factory()->create();
        $pending = User::factory()->create();
        $stranger = User::factory()->create();
        Follow::factory()->accepted()->create(['follower_id' => $accepted->id, 'followed_id' => $owner->id]);
        Follow::factory()->create(['follower_id' => $pending->id, 'followed_id' => $owner->id]);
        // Reverse direction must not grant the stranger access to the owner's habits.
        Follow::factory()->accepted()->create(['follower_id' => $owner->id, 'followed_id' => $stranger->id]);
        foreach (['private', 'followers', 'public'] as $visibility) {
            foreach (['active', 'archived', 'ended', 'trashed'] as $lifecycle) {
                $habit = Habit::factory()->for($owner)->create(['visibility' => $visibility, 'archived_at' => $lifecycle === 'archived' ? now() : null, 'end_date' => $lifecycle === 'ended' ? '2026-01-01' : null]);
                if ($lifecycle === 'trashed') {
                    $habit->delete();
                }
                foreach ([true, false] as $private) {
                    $owner->update(['is_private' => $private]);
                    foreach (['owner' => $owner, 'accepted' => $accepted, 'pending' => $pending, 'stranger' => $stranger] as $kind => $viewer) {
                        $allowed = $lifecycle !== 'trashed' && ($kind === 'owner' || ((! $private || $kind === 'accepted') && ($visibility === 'public' || ($visibility === 'followers' && $kind === 'accepted'))));
                        $this->assertSame($allowed, app(SocialAccess::class)->habits($viewer, $owner)->whereKey($habit->id)->exists());
                        $this->assertSame($allowed, Gate::forUser($viewer)->allows('viewShared', $habit));
                        $response = $this->actingAs($viewer)->get(route('people.habit', ['user' => $owner->username, 'habit' => $habit->id]));
                        $response->assertStatus($allowed ? 200 : 404);
                        if ($kind !== 'owner') {
                            foreach (['view', 'update', 'archive', 'unarchive', 'delete', 'restore', 'forceDelete'] as $ability) {
                                $this->assertFalse(Gate::forUser($viewer)->allows($ability, $habit));
                            }
                            $this->assertFalse(Gate::forUser($viewer)->allows('record', [$habit, '2026-10-04']));
                        }
                    }
                }
            }
        }
    }

    public function test_followers_cannot_use_owner_management_or_tracking_routes(): void
    {
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $habit = Habit::factory()->for($owner)->create(['visibility' => 'followers']);
        Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
        foreach (['habits.show' => ['habit' => $habit->id], 'habits.edit' => ['habit' => $habit->id], 'entries.index' => ['habit' => $habit->id], 'entries.edit' => ['habit' => $habit->id, 'date' => '2026-10-04']] as $route => $params) {
            $this->actingAs($viewer)->get(route($route, $params))->assertNotFound();
        }
        $this->get(route('people.habit', ['user' => $viewer->username, 'habit' => $habit->id]))->assertNotFound();
    }
}
