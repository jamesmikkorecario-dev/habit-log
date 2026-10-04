<?php

use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Title('Profile Settings')] class extends Component
{
    #[Locked] public int $ownerId;

    public string $username = '';
    public string $display_name = '';
    public string $bio = '';
    public bool $is_private = true;
    public string $theme_key = 'terminal-green';
    public string $timezone = 'UTC';

    /**
     * Initialize the component with the authenticated user's current profile.
     */
    public function mount(): void
    {
        $this->ownerId = auth()->id();
        $user = auth()->user();

        $this->username = $user->username ?? '';
        $this->display_name = $user->display_name ?? '';
        $this->bio = $user->bio ?? '';
        $this->is_private = $user->is_private;
        $this->theme_key = $user->theme_key;
        $this->timezone = $user->timezone;
    }

    /**
     * Validate and save profile changes to the authenticated user.
     */
    public function hydrate(): void
    {
        abort_unless(auth()->check() && auth()->id() === $this->ownerId, 403);
    }

    public function save(): void
    {
        $this->hydrate();
        $validated = $this->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique(User::class)->ignore(auth()->id()),
            ],
            'display_name' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:160'],
            'is_private' => ['required', 'boolean'],
            'timezone' => ['required', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'theme_key' => ['required', 'string', Rule::in(['terminal-green'])],
        ], [
            'username.regex' => 'Username must start with a letter and contain only lowercase letters, numbers, and underscores.',
        ]);

        // Convert empty or whitespace-only strings to null for nullable fields.
        foreach (['display_name', 'bio'] as $field) {
            $value = trim($validated[$field] ?? '');
            $validated[$field] = $value === '' ? null : $value;
        }

        app(\App\Actions\Profiles\SaveProfile::class)->handle(auth()->user(), $validated);

        $this->dispatch('profile-updated');
    }
};

?>

<div class="space-y-8">
    <div>
        <p class="font-mono text-sm text-emerald-500" aria-hidden="true">~/settings/profile</p>
        <h1 class="mt-3 text-2xl font-semibold tracking-tight text-zinc-100 sm:text-3xl">Profile Settings</h1>
        <p class="mt-2 text-sm text-zinc-400">Manage your username, display preferences, and privacy.</p>
    </div>

    <form wire:submit="save" class="max-w-xl space-y-6">
        {{-- Username --}}
        <flux:input
            wire:model="username"
            :label="__('Username')"
            placeholder="your_username"
            maxlength="30"
            description="Lowercase letters, numbers, and underscores. 3–30 characters, starting with a letter."
        />

        {{-- Display name --}}
        <flux:input
            wire:model="display_name"
            :label="__('Display name')"
            :placeholder="auth()->user()->name"
            maxlength="50"
            description="Optional. Shared profiles fall back to your username; your own greeting falls back to your registration name."
        />

        {{-- Bio --}}
        <flux:textarea
            wire:model="bio"
            :label="__('Bio')"
            placeholder="A short bio about yourself"
            maxlength="160"
            rows="3"
            description="Up to 160 characters."
        />

        {{-- Account privacy --}}
        <flux:checkbox
            wire:model="is_private"
            :label="__('Private account')"
            description="Require approval to see any shared activity. Turning this off lets signed-in users see public habits and their history. Existing pending requests stay pending. New habits always default to private."
        />

        <flux:select wire:model="timezone" label="Timezone" description="Defines today for daily tracking. Existing recorded dates stay unchanged.">
            @foreach(timezone_identifiers_list() as $zone)<flux:select.option :value="$zone">{{ $zone }}</flux:select.option>@endforeach
        </flux:select>

        {{-- Theme --}}
        <flux:select wire:model="theme_key" :label="__('Theme')">
            <flux:select.option value="terminal-green">Terminal Green</flux:select.option>
        </flux:select>

        {{-- Save --}}
        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">
                Save changes
            </flux:button>

            <p
                x-data="{ show: false }"
                x-on:profile-updated.window="show = true; setTimeout(() => show = false, 2000)"
                x-show="show"
                x-cloak
                x-transition.opacity
                role="status"
                class="text-sm text-emerald-400 motion-reduce:transition-none!"
            >
                Saved.
            </p>
        </div>
    </form>
</div>
