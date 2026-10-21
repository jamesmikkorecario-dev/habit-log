<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_links_guests_to_existing_authentication_routes(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('habit.log')
            ->assertSee('Get Started')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('Illustrative preview')
            ->assertDontSee('laravel.com');
    }

    public function test_signed_in_visitors_can_return_to_the_dashboard_without_exposing_profile_data(): void
    {
        $user = User::factory()->create(['name' => 'PRIVATE-LANDING-CANARY']);

        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('Open dashboard')
            ->assertDontSee('Get Started')
            ->assertDontSee('Create your account')
            ->assertDontSee($user->name)
            ->assertDontSee($user->email);
    }
}
