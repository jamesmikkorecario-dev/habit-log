<?php

namespace Tests\Feature\Social;

use App\Livewire\ActivityHeatmap;
use App\Models\Follow;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class InterfaceTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_guest_lookup_private_header_and_public_profile_projection(): void
    {
        $owner = User::factory()->create(['username' => 'profile_owner', 'name' => 'SECRET-REGISTRATION', 'display_name' => null, 'bio' => 'GATED-BIO', 'email' => 'private-canary@example.com']);
        $viewer = User::factory()->create();
        Journal::factory()->for($owner)->create(['content' => 'SECRET-JOURNAL']);
        $private = $this->habit(overrides: ['name' => 'SECRET-HABIT'], user: $owner);
        $public = $this->habit(overrides: ['visibility' => 'public', 'name' => 'Visible practice'], user: $owner);
        foreach (['people.index' => [], 'people.show' => ['user' => $owner->username], 'people.habit' => ['user' => $owner->username, 'habit' => $public->id]] as $route => $params) {
            $this->get(route($route, $params))->assertRedirect(route('login'));
        }
        $this->actingAs($viewer)->get(route('people.show', ['user' => $owner->username]))->assertOk()->assertSee('Request to follow')->assertDontSee('GATED-BIO')->assertDontSee('Visible practice')->assertDontSee('SECRET-REGISTRATION')->assertDontSee('private-canary@example.com');
        $this->get(route('people.habit', ['user' => $owner->username, 'habit' => $public->id]))->assertNotFound();
        $owner->update(['is_private' => false]);
        $this->get(route('people.show', ['user' => $owner->username]))->assertOk()->assertSee('GATED-BIO')->assertSee('Visible practice')->assertDontSee('SECRET-HABIT')->assertDontSee('SECRET-JOURNAL')->assertDontSee('SECRET-REGISTRATION')->assertDontSee('private-canary@example.com');
        $this->get(route('people.habit', ['user' => $owner->username, 'habit' => $public->id]))->assertOk()->assertSee('Read-only activity')->assertDontSee('Open daily entry');
        $this->get(route('people.habit', ['user' => $owner->username, 'habit' => $private->id]))->assertNotFound();
        Livewire::actingAs($viewer)->test('pages::people.index')->set('username', ' PROFILE_OWNER ')->call('lookup')->assertRedirect(route('people.show', ['user' => $owner->username]));
        Livewire::actingAs($viewer)->test('pages::people.index')->set('username', $owner->email)->call('lookup')->assertHasErrors('username');
    }

    public function test_follow_request_approval_cancel_and_confirmed_removal_interface(): void
    {
        [$viewer, $owner] = User::factory()->count(2)->create()->all();
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner]);
        $page->call('follow', $owner->id)->assertHasNoErrors()->assertSee('Follow request pending.');
        $id = Follow::sole()->id;
        Livewire::actingAs($owner)->test('pages::people.index')->call('setTab', 'incoming')->assertSee($viewer->username)
            ->call('changeFollow', $id, 'approve')->assertHasNoErrors()->assertSee('Follow request approved.');
        $page = Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner]);
        $page->call('confirmFollowRemoval', $id, 'unfollow')->assertSet('showFollowModal', true)
            ->call('cancelFollowRemoval')->assertSet('showFollowModal', false);
        $this->assertDatabaseCount('follows', 1);
        $page->call('confirmFollowRemoval', $id, 'unfollow')->call('removeFollow')->assertHasNoErrors()->assertSee('Unfollowed.');
        $this->assertDatabaseCount('follows', 0);
        Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $owner])->call('removeFollow')->assertForbidden();
    }

    public function test_nested_heatmap_rechecks_revocation_and_locked_subject(): void
    {
        [$viewer, $owner] = User::factory()->count(2)->create()->all();
        $follow = Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $owner->id]);
        $habit = $this->habit(overrides: ['visibility' => 'followers'], user: $owner);
        $page = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id, 'habitId' => $habit->id])->assertOk();
        $follow->delete();
        $page->call('changeYear', 0)->assertNotFound();
        $owner->update(['is_private' => false]);
        $page = Livewire::actingAs($viewer)->test(ActivityHeatmap::class, ['subjectId' => $owner->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('subjectId', $viewer->id);
    }

    public function test_real_livewire_requests_reject_logout_and_session_switch(): void
    {
        [$viewer, $owner, $other] = User::factory()->count(3)->create()->all();
        foreach (['people.index' => [], 'people.show' => ['user' => $owner->username], 'settings.profile' => []] as $route => $params) {
            $response = $this->actingAs($viewer)->get(route($route, $params))->assertOk();
            preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
            $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]];
            Auth::logout();
            $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), $payload)->assertUnauthorized();
            $this->actingAs($other)->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), $payload)->assertForbidden();
        }
    }

    public function test_relationship_lists_are_paginated_owner_scoped_and_renames_keep_identity(): void
    {
        [$viewer, $subject] = User::factory()->count(2)->create()->all();
        $follow = Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $subject->id]);
        $oldUsername = $subject->username;
        $subject->update(['username' => 'renamed_subject']);
        $this->actingAs($viewer)->get(route('people.show', ['user' => $oldUsername]))->assertNotFound();
        $this->get(route('people.show', ['user' => 'renamed_subject']))->assertOk()->assertSee('Unfollow');
        $this->assertSame($subject->id, $follow->fresh()->followed_id);
        foreach (User::factory()->count(24)->create() as $person) {
            Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $person->id]);
        }
        $unrelated = User::factory()->create(['username' => 'unrelated_requester']);
        Follow::factory()->create(['follower_id' => $unrelated->id, 'followed_id' => $subject->id]);
        $page = Livewire::actingAs($viewer)->test('pages::people.index')->assertDontSee('unrelated_requester')->assertDontSee('renamed_subject');
        $page->call('nextPage')->assertSee('renamed_subject')->assertDontSee('unrelated_requester');
        $page->call('setTab', 'incoming')->assertSee('No incoming requests.')->assertDontSee('unrelated_requester');
    }
}
