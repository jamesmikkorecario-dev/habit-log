<?php

namespace Tests\Feature\Social;

use App\Livewire\ActivityHeatmap;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tracking\TrackingTestCase;

class SharedRefinementsTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_follow_refresh_and_unfollow_replace_the_nested_report_and_filter_the_list(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create();
        $this->habit(overrides: ['name' => 'Public practice', 'visibility' => 'public'], user: $owner);
        $this->habit(overrides: ['name' => 'Follower practice', 'visibility' => 'followers'], user: $owner);
        $this->habit(overrides: ['name' => 'PRIVATE CANARY'], user: $owner);
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner]);
        $page->assertSee('1 visible habits')->assertDontSee('Follower practice')->assertDontSee('PRIVATE CANARY');
        $page->call('follow', $owner->id)->assertSee('2 visible habits')->assertSee('Follower practice')->assertSet('activityRevision', 1);
        $page->call('refreshRelationships')->assertSet('activityRevision', 2);
        $page->call('confirmFollowRemoval', Follow::sole()->id, 'unfollow')->call('removeFollow')
            ->assertSee('1 visible habits')->assertDontSee('Follower practice')->assertDontSee('PRIVATE CANARY')->assertSet('activityRevision', 3);
        $this->assertDatabaseCount('habits', 3);
    }

    public function test_approval_removal_and_account_privacy_are_rechecked_on_profile_refresh(): void
    {
        $owner = User::factory()->create(['is_private' => true, 'bio' => 'GATED BIO']);
        $viewer = User::factory()->create();
        $this->habit(overrides: ['name' => 'Shared practice', 'visibility' => 'public'], user: $owner);
        $follow = Follow::factory()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner])->assertDontSee('GATED BIO')->assertDontSee('Shared practice');
        $follow->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
        $page->call('refreshRelationships')->assertSee('GATED BIO')->assertSee('Shared practice')->assertSet('activityRevision', 1);
        $follow->delete();
        $page->call('refreshRelationships')->assertDontSee('GATED BIO')->assertDontSee('Shared practice')->assertDontSee('Progress overview');
        $owner->update(['is_private' => false]);
        $page->call('refreshRelationships')->assertSee('Shared practice');
        $owner->update(['is_private' => true]);
        $page->call('refreshRelationships')->assertDontSee('Shared practice')->assertDontSee('GATED BIO');
    }

    public static function revocations(): array
    {
        return [['follow'], ['privacy'], ['visibility'], ['trash']];
    }

    #[DataProvider('revocations')]
    public function test_revoked_open_detail_and_heatmap_navigate_without_returning_private_content(string $change): void
    {
        $owner = User::factory()->create(['is_private' => $change === 'follow']);
        $viewer = User::factory()->create();
        $follow = Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
        $habit = $this->habit(overrides: ['name' => 'REVOKED HABIT', 'description' => 'REVOKED DESCRIPTION', 'visibility' => 'public'], user: $owner);
        $detail = Livewire::actingAs($viewer)->test('pages::people.habit', ['user' => $owner, 'habit' => $habit->id]);
        $heatmap = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id, 'habitId' => $habit->id]);
        match ($change) {
            'follow' => $follow->delete(),
            'privacy' => (function () use ($follow, $owner) {
                $follow->delete();
                $owner->update(['is_private' => true]);
            })(),
            'visibility' => $habit->update(['visibility' => 'private']),
            'trash' => $habit->delete(),
        };
        foreach ([[$detail, 'refreshActivity'], [$heatmap, 'changeYear']] as [$page, $action]) {
            $page->call($action, ...($action === 'changeYear' ? [0] : []))->assertStatus(200)->assertRedirect(route('people.show', ['user' => $owner->username]));
            $this->assertTrue($page->effects['redirectUsingNavigate']);
            $this->assertArrayNotHasKey('html', $page->effects);
            $page->call('$refresh')->assertRedirect(route('people.show', ['user' => $owner->username]));
            $this->assertArrayNotHasKey('html', $page->effects);
        }
        $this->actingAs($viewer)->get(route('people.habit', ['user' => $owner->username, 'habit' => $habit->id]))->assertNotFound();
        $this->get(route('people.show', ['user' => $owner->username]))->assertDontSee('REVOKED HABIT')->assertDontSee('REVOKED DESCRIPTION');
    }

    public function test_shared_heatmap_requests_refresh_parent_metadata_without_resetting_the_day(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create();
        $habit = $this->habit(overrides: ['visibility' => 'public'], user: $owner);
        $page = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id, 'habitId' => $habit->id]);
        $page->call('selectDay', $owner->localToday())->assertSet('selectedDate', $owner->localToday())->assertDispatched('shared-activity-updated.'.$owner->id);
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner]);
        $habit->update(['visibility' => 'private']);
        $page->dispatch('shared-activity-updated.'.$owner->id)->assertSee('0 visible habits')->assertSet('activityRevision', 0);
    }

    public function test_empty_and_invalid_pages_are_distinct_and_recoverable_after_visibility_changes(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create();
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner])->assertSee('No habits to display.');
        $habits = collect(range(1, 21))->map(fn ($i) => $this->habit(overrides: ['name' => 'Practice '.$i, 'visibility' => 'public'], user: $owner));
        $page->call('refreshRelationships')->assertSee('21 visible habits')->call('nextPage')->assertSee('Page 2 of 2')->assertSee('Practice 1');
        $page->call('setPage', 99)->assertSee('No habits on this page.')->assertDontSee('No habits to display.')->assertSee('Back to first page');
        $page->call('setPage', 1)->assertSee('Page 1 of 2');
        $page->call('setPage', 2);
        foreach ($habits as $habit) {
            $habit->update(['visibility' => 'private']);
        }
        $page->call('refreshRelationships')->assertSee('0 visible habits')->assertSee('Back to first page')->assertDontSee('Practice 1');
        $page->call('setPage', 1)->assertSee('No habits to display.');
    }

    public function test_switched_sessions_do_not_turn_authentication_failures_into_shared_navigation(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $viewer = User::factory()->create();
        $habit = $this->habit(overrides: ['visibility' => 'public'], user: $owner);
        $detail = Livewire::actingAs($viewer)->test('pages::people.habit', ['user' => $owner, 'habit' => $habit->id]);
        $heatmap = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id, 'habitId' => $habit->id]);
        $this->actingAs($owner);
        $detail->call('refreshActivity')->assertForbidden();
        $heatmap->call('changeYear', 0)->assertForbidden();
    }
}
