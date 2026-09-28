<?php

namespace Tests\Feature\Tracking;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class InterfaceTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_timezone_defaults_validates_and_preserves_profile(): void
    {
        $user = User::factory()->create();
        $this->assertSame('UTC', $user->fresh()->timezone);
        $page = Livewire::actingAs($user)->test('pages::settings.profile')->set('username', 'timezone_user');
        $page->set('timezone', 'Asia/Manila')->call('save')->assertHasNoErrors();
        $this->assertSame('Asia/Manila', $user->fresh()->timezone);
        $page->set('timezone', 'Not/AZone')->set('bio', 'Must not persist')->call('save')->assertHasErrors('timezone');
        $this->assertNull($user->fresh()->bio);
    }

    public function test_navigation_pagination_and_empty_state_do_not_create_entries(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test('pages::tracking.index')->assertSee('No habits yet.');
        for ($i = 0; $i < 21; $i++) {
            $this->habit('boolean', ['name' => sprintf('Own %02d', $i)], $user);
        }
        $this->habit('boolean', ['name' => 'Foreign hidden']);
        $page = Livewire::actingAs($user)->test('pages::tracking.index')->assertSee('Own 00')->assertDontSee('Own 20')->assertDontSee('Foreign hidden');
        $page->call('setPage', 2)->assertSee('Own 20')->assertDontSee('Own 00');
        $page->set('selectedDate', '2024-02-29')->call('goToDate')->assertSet('date', '2024-02-29')->assertSee('Own 00');
        $this->assertDatabaseCount('habit_entries', 0);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('Track today')->assertSee('Tracking');
    }

    public function test_locked_identity_and_logged_out_request_cannot_write(): void
    {
        $habit = $this->habit();
        try {
            $this->editor($habit)->set('habitId', 99999);
            $this->fail('Identity must stay locked.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertDatabaseCount('habit_entries', 0);
        }
        $response = $this->actingAs($habit->user)->get(route('entries.edit', ['habit' => $habit, 'date' => '2026-09-27']))->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        Auth::logout();
        $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), 'updates' => ['form.status' => 'completed'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]])->assertUnauthorized();
        $this->assertDatabaseCount('habit_entries', 0);
    }
}
