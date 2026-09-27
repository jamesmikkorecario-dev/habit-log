<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------

    public function test_guests_cannot_access_profile_settings(): void
    {
        $response = $this->get(route('settings.profile'));

        $response->assertRedirect(route('login'));
    }

    public function test_logged_out_profile_mutation_is_rejected(): void
    {
        $user = User::factory()->create(['bio' => 'Original bio']);
        $page = $this->actingAs($user)->get(route('settings.profile'))->assertOk();

        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

        Auth::logout();
        $this->assertGuest();

        $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['bio' => 'Unauthorized overwrite'],
                'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
            ]],
        ])->assertUnauthorized();

        $this->assertSame('Original bio', $user->refresh()->bio);
    }

    public function test_profile_update_cannot_change_another_users_profile(): void
    {
        $other = User::factory()->create(['username' => 'other_user', 'bio' => 'Untouched']);
        $original = $other->refresh()->getAttributes();
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('username', $other->username)
            ->set('bio', 'Overwrite attempt')
            ->call('save')
            ->assertHasErrors('username');

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('username', 'my_profile')
            ->set('bio', 'Own biography')
            ->set('is_private', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Own biography', $user->refresh()->bio);
        $this->assertFalse($user->is_private);
        $this->assertSame($original, $other->refresh()->getAttributes());
    }

    // ---------------------------------------------------------------
    // Page rendering
    // ---------------------------------------------------------------

    public function test_profile_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.profile'));

        $response->assertOk()
            ->assertSee('Profile Settings');
    }

    public function test_profile_page_renders_all_form_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.profile'));

        $response->assertOk()
            ->assertSee('Username')
            ->assertSee('Display name')
            ->assertSee('Bio')
            ->assertSee('Private account')
            ->assertSee('Theme')
            ->assertSee('Save changes');
    }

    public function test_profile_loads_current_user_data(): void
    {
        $user = User::factory()->create([
            'username' => 'existinguser',
            'display_name' => 'Existing Name',
            'bio' => 'Existing bio text',
            'is_private' => false,
            'theme_key' => 'terminal-green',
        ]);

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->assertSet('username', 'existinguser')
            ->assertSet('display_name', 'Existing Name')
            ->assertSet('bio', 'Existing bio text')
            ->assertSet('is_private', false)
            ->assertSet('theme_key', 'terminal-green');
    }

    public function test_profile_loads_empty_strings_for_null_fields(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'display_name' => null,
            'bio' => null,
        ]);

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->assertSet('username', '')
            ->assertSet('display_name', '')
            ->assertSet('bio', '');
    }

    // ---------------------------------------------------------------
    // Happy-path save
    // ---------------------------------------------------------------

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'newusername')
            ->set('display_name', 'New Display Name')
            ->set('bio', 'A short bio about me')
            ->set('is_private', false)
            ->set('theme_key', 'terminal-green')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('profile-updated');

        $user->refresh();
        $this->assertSame('newusername', $user->username);
        $this->assertSame('New Display Name', $user->display_name);
        $this->assertSame('A short bio about me', $user->bio);
        $this->assertFalse($user->is_private);
        $this->assertSame('terminal-green', $user->theme_key);
    }

    public function test_profile_update_preserves_unrelated_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.com',
        ]);
        $originalPassword = $user->password;

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'newusername')
            ->set('display_name', 'New Display')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Original Name', $user->name);
        $this->assertSame('original@example.com', $user->email);
        $this->assertSame($originalPassword, $user->password);
    }

    // ---------------------------------------------------------------
    // Nullable field handling
    // ---------------------------------------------------------------

    #[DataProvider('zeroTextProfiles')]
    public function test_zero_text_is_preserved_in_profile(string $field, string $input): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set($field, $input)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0', $user->refresh()->getAttribute($field));
    }

    public static function zeroTextProfiles(): array
    {
        return [
            'display name zero' => ['display_name', '0'],
            'display name padded zero' => ['display_name', ' 0 '],
            'bio zero' => ['bio', '0'],
            'bio padded zero' => ['bio', ' 0 '],
        ];
    }

    public function test_empty_display_name_is_stored_as_null(): void
    {
        $user = User::factory()->create(['display_name' => 'Old Name']);

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'testuser')
            ->set('display_name', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->display_name);
    }

    public function test_empty_bio_is_stored_as_null(): void
    {
        $user = User::factory()->create(['bio' => 'Old bio']);

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'testuser')
            ->set('bio', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->bio);
    }

    public function test_whitespace_only_display_name_is_stored_as_null(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'testuser')
            ->set('display_name', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->display_name);
    }

    public function test_whitespace_only_bio_is_stored_as_null(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'testuser')
            ->set('bio', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->bio);
    }

    // ---------------------------------------------------------------
    // Username validation
    // ---------------------------------------------------------------

    public function test_username_is_required(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', '')
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'taken')
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_user_can_keep_their_own_username(): void
    {
        $user = User::factory()->create(['username' => 'myname']);

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'myname')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_username_must_be_lowercase_alphanumeric(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'HasUpperCase')
            ->call('save')
            ->assertHasErrors('username');

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'has spaces')
            ->call('save')
            ->assertHasErrors('username');

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'has-dashes')
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_username_must_start_with_a_letter(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', '1startswithnumber')
            ->call('save')
            ->assertHasErrors('username');

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', '_startsunderscore')
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_username_allows_underscores(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'user_name_123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('user_name_123', $user->refresh()->username);
    }

    public function test_username_min_length(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'ab')
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_username_at_min_boundary_is_valid(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'abc')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('abc', $user->refresh()->username);
    }

    public function test_username_max_length(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', str_repeat('a', 31))
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_username_at_max_boundary_is_valid(): void
    {
        $user = User::factory()->create();
        $maxUsername = 'a'.str_repeat('b', 29); // 30 chars, starts with letter

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', $maxUsername)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($maxUsername, $user->refresh()->username);
    }

    // ---------------------------------------------------------------
    // Display name validation
    // ---------------------------------------------------------------

    public function test_display_name_max_length(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'validuser')
            ->set('display_name', str_repeat('a', 51))
            ->call('save')
            ->assertHasErrors('display_name');
    }

    public function test_display_name_at_max_boundary_is_valid(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'validuser')
            ->set('display_name', str_repeat('a', 50))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(str_repeat('a', 50), $user->refresh()->display_name);
    }

    // ---------------------------------------------------------------
    // Bio validation
    // ---------------------------------------------------------------

    public function test_bio_max_length(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'validuser')
            ->set('bio', str_repeat('a', 161))
            ->call('save')
            ->assertHasErrors('bio');
    }

    public function test_bio_at_max_boundary_is_valid(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'validuser')
            ->set('bio', str_repeat('a', 160))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(str_repeat('a', 160), $user->refresh()->bio);
    }

    // ---------------------------------------------------------------
    // Theme validation
    // ---------------------------------------------------------------

    public function test_theme_key_must_be_valid(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'validuser')
            ->set('theme_key', 'nonexistent-theme')
            ->call('save')
            ->assertHasErrors('theme_key');
    }

    public function test_invalid_theme_prevents_all_profile_changes(): void
    {
        $user = User::factory()->create(['bio' => 'Original', 'is_private' => true]);
        $original = $user->refresh()->getAttributes();

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('bio', 'Changed')
            ->set('is_private', false)
            ->set('theme_key', 'arbitrary-theme')
            ->call('save')
            ->assertHasErrors('theme_key');

        $this->assertSame($original, $user->refresh()->getAttributes());
    }

    // ---------------------------------------------------------------
    // Privacy toggle
    // ---------------------------------------------------------------

    public function test_user_can_toggle_privacy_off_and_on(): void
    {
        $user = User::factory()->create(['is_private' => true]);

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->assertSet('is_private', true)
            ->set('username', 'testuser')
            ->set('is_private', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($user->refresh()->is_private);

        // Toggle back to private
        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', 'testuser')
            ->set('is_private', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($user->refresh()->is_private);
    }

    // ---------------------------------------------------------------
    // Defaults
    // ---------------------------------------------------------------

    public function test_is_private_defaults_to_true(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_private);
    }

    public function test_new_user_has_default_theme(): void
    {
        $user = User::factory()->create();

        $this->assertSame('terminal-green', $user->theme_key);
    }

    // ---------------------------------------------------------------
    // Multiple errors
    // ---------------------------------------------------------------

    public function test_multiple_validation_errors_returned_at_once(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('username', '')
            ->set('display_name', str_repeat('x', 51))
            ->set('bio', str_repeat('x', 161))
            ->set('theme_key', 'invalid')
            ->call('save')
            ->assertHasErrors(['username', 'display_name', 'bio', 'theme_key']);
    }

    // ---------------------------------------------------------------
    // displayName() accessor
    // ---------------------------------------------------------------

    public function test_display_name_accessor_returns_display_name_when_set(): void
    {
        $user = User::factory()->create([
            'name' => 'Registration Name',
            'display_name' => 'Custom Display',
        ]);

        $this->assertSame('Custom Display', $user->displayName());
    }

    public function test_display_name_accessor_falls_back_to_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Fallback Name',
            'display_name' => null,
        ]);

        $this->assertSame('Fallback Name', $user->displayName());
    }

    public function test_display_name_html_is_escaped_in_dashboard(): void
    {
        $user = User::factory()->create(['display_name' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ---------------------------------------------------------------
    // Registration compatibility
    // ---------------------------------------------------------------

    public function test_registration_creates_user_with_profile_defaults(): void
    {
        if (! in_array(Features::registration(), config('fortify.features', []))) {
            $this->markTestSkipped('Registration is not enabled.');
        }

        $response = $this->post(route('register.store'), [
            'name' => 'Brand New User',
            'email' => 'brandnew@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors();

        $user = User::where('email', 'brandnew@example.com')->firstOrFail();
        $this->assertNull($user->username);
        $this->assertNull($user->display_name);
        $this->assertNull($user->bio);
        $this->assertTrue($user->is_private);
        $this->assertSame('terminal-green', $user->theme_key);
    }

    // ---------------------------------------------------------------
    // Navigation on settings page
    // ---------------------------------------------------------------

    public function test_settings_page_shows_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.profile'));

        $response->assertOk()
            ->assertSee('habit.log')
            ->assertSee('Dashboard')
            ->assertSee('Settings');
    }

    public function test_settings_navigation_is_highlighted_on_profile_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.profile'));

        $response->assertOk()
            ->assertSee('aria-current="page"', false);
    }
}
