<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('Welcome, '.$user->displayName())
            ->assertSee('wire:id=', false);
    }

    public function test_dashboard_shows_display_name_when_set(): void
    {
        $user = User::factory()->create([
            'name' => 'Registration Name',
            'display_name' => 'Custom Display',
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('Welcome, Custom Display')
            ->assertDontSee('Welcome, Registration Name');
    }

    public function test_dashboard_falls_back_to_name_when_no_display_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Fallback Name',
            'display_name' => null,
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('Welcome, Fallback Name');
    }

    public function test_navigation_shows_on_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('habit.log')
            ->assertSee('Dashboard')
            ->assertSee('Settings');
    }

    public function test_navigation_highlights_dashboard_as_active(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    public function test_user_initials_shown_in_navigation(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee($user->initials());
    }

    public function test_user_dropdown_shows_display_name(): void
    {
        $user = User::factory()->create([
            'display_name' => 'My Display Name',
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('My Display Name');
    }

    public function test_user_dropdown_shows_username_when_set(): void
    {
        $user = User::factory()->create([
            'username' => 'johndoe',
        ]);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('@johndoe');
    }

    public function test_logout_form_present_on_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('Log out')
            ->assertSee(route('logout'), false);
    }
}
