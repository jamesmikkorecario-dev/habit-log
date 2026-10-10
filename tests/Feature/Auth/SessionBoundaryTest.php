<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Feature\Tracking\TrackingTestCase;

class SessionBoundaryTest extends TrackingTestCase
{
    use RefreshDatabase;

    public function test_owner_page_snapshots_cannot_be_reused_after_logout_or_account_switch(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $habit = $this->habit(user: $owner);
        foreach ([
            'dashboard' => [], 'categories.index' => [], 'habits.index' => [], 'habits.create' => [],
            'habits.edit' => ['habit' => $habit->id], 'habits.show' => ['habit' => $habit->id],
            'tracking.index' => [], 'entries.index' => ['habit' => $habit->id],
            'entries.edit' => ['habit' => $habit->id, 'date' => $owner->localToday()],
        ] as $route => $params) {
            $response = $this->actingAs($owner)->get(route($route, $params))->assertOk();
            preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
            $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]];
            Auth::logout();
            $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), $payload)->assertUnauthorized();
            $this->actingAs($other)->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), $payload)->assertForbidden();
        }
    }

    public function test_old_category_draft_cannot_be_saved_to_a_different_account(): void
    {
        [$owner, $other] = User::factory()->count(2)->create()->all();
        $page = Livewire::actingAs($owner)->test('pages::categories.index')
            ->set('name', 'Private draft category')->set('description', 'Private draft details')->call('review');
        $this->actingAs($other);
        $page->call('save')->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Private draft category']);
    }
}
