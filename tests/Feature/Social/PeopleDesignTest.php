<?php

namespace Tests\Feature\Social;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PeopleDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_onboarding_keeps_lookup_available_and_hides_after_setup(): void
    {
        $viewer = User::factory()->create(['username' => null]);
        $subject = User::factory()->create(['username' => 'find_me']);
        $page = Livewire::actingAs($viewer)->test('pages::people.index')
            ->assertSee('Set up your username')->assertSee('Go to Profile Settings')->assertSee('Exact username');
        $page->set('username', ' FIND_ME ')->call('lookup')->assertRedirect(route('people.show', ['user' => 'find_me']));
        Livewire::actingAs($viewer)->test('pages::people.show', ['user' => $subject])
            ->assertSee('Choose a username to follow people')->assertDontSee('wire:click="follow(', false);
        $viewer->update(['username' => 'my_username']);
        Livewire::actingAs($viewer)->test('pages::people.index')->assertDontSee('Set up your username')->assertSee('My profile');
    }

    public function test_lookup_errors_and_refresh_preserve_input_without_discovering_partial_names(): void
    {
        $viewer = User::factory()->create();
        User::factory()->create(['username' => 'known_person']);
        $page = Livewire::actingAs($viewer)->test('pages::people.index');
        foreach (['', '---', 'ab', 'someone@example.test'] as $invalid) {
            $page->set('username', $invalid)->call('lookup')->assertHasErrors('username')->assertNoRedirect()->assertSet('username', $invalid);
        }
        $page->set('username', 'known')->call('lookup')->assertHasErrors('username')->assertSee('No profile found for that username.')
            ->call('refreshRelationships')->assertSet('username', 'known')->assertNoRedirect();
    }

    public function test_empty_views_and_out_of_range_page_have_accurate_guidance(): void
    {
        $viewer = User::factory()->create();
        $page = Livewire::actingAs($viewer)->test('pages::people.index');
        foreach (['following' => "You're not following anyone yet.", 'followers' => 'No followers yet.', 'incoming' => 'No incoming requests.', 'sent' => 'No pending requests.'] as $tab => $heading) {
            $page->call('setTab', $tab)->assertSee($heading)->assertSee('aria-pressed="true"', false);
        }
        $page->call('gotoPage', 2)->assertSee('No connections on this page.')->assertDontSee('No pending requests.')
            ->call('gotoPage', 1)->assertSee('No pending requests.');
    }

    public function test_populated_views_only_show_scoped_identity_status_and_supported_actions(): void
    {
        $viewer = User::factory()->create();
        $people = User::factory()->count(4)->create(['display_name' => 'Shared label', 'bio' => 'SECRET BIO', 'name' => 'SECRET REGISTRATION']);
        Follow::factory()->accepted()->create(['follower_id' => $viewer->id, 'followed_id' => $people[0]->id]);
        Follow::factory()->accepted()->create(['follower_id' => $people[1]->id, 'followed_id' => $viewer->id]);
        Follow::factory()->create(['follower_id' => $people[2]->id, 'followed_id' => $viewer->id]);
        Follow::factory()->create(['follower_id' => $viewer->id, 'followed_id' => $people[3]->id]);
        $page = Livewire::actingAs($viewer)->test('pages::people.index');
        foreach (['following' => 'Unfollow', 'followers' => 'Remove follower', 'incoming' => 'Approve', 'sent' => 'Cancel request'] as $tab => $action) {
            $page->call('setTab', $tab)->assertSee('Shared label')->assertSee($action)
                ->assertDontSee('SECRET BIO')->assertDontSee('SECRET REGISTRATION');
        }
        $page->call('setTab', 'incoming')->assertSee('Awaiting your approval')->assertSee('Reject');
        $page->call('setTab', 'sent')->assertSee('Request sent · Awaiting approval');
        $this->assertDatabaseCount('follows', 4);
    }
}
