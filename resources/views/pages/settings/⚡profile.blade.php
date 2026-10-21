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

<div class="min-w-0 space-y-6 sm:space-y-8">
    <header>
        <x-terminal-path path="~/settings/profile" />
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Profile Settings</h1>
        <p class="mt-3 text-sm leading-relaxed text-zinc-400">Manage your identity, privacy, and preferences.</p>
    </header>

    <form wire:submit="save" aria-label="Profile settings" class="min-w-0 space-y-6" data-profile-settings
        x-data="{ saved: false }" x-on:profile-updated.window="saved = true"
        x-on:input="saved = false" x-on:change="saved = false" x-on:submit="saved = false"
        x-on:focusin="
            if ($event.target.matches('input, textarea, select, button, summary')) {
                const top = $event.target.getBoundingClientRect().top - 32;
                const navBottom = document.querySelector('body > div > nav')?.getBoundingClientRect().bottom ?? 0;
                if (top < navBottom + 16) window.scrollBy(0, top - navBottom - 16);
            }
        ">
        @if($errors->any())
            <p role="alert" tabindex="-1" class="scroll-mt-24 rounded-lg border border-red-900/60 p-4 text-sm text-red-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">Please review the highlighted fields. Your changes have not been saved.</p>
        @endif
        <div class="grid min-w-0 items-start gap-6 lg:grid-cols-3">
            <section aria-labelledby="profile-information" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6 lg:col-span-2">
                <div>
                    <h2 id="profile-information" class="text-xl font-semibold text-zinc-100">Profile information</h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">Choose how people recognize you.</p>
                </div>
                <div class="max-w-xl space-y-5">
                    <flux:input wire:model="username" label="Username" placeholder="your_username" maxlength="30" aria-required="true" autocapitalize="none" spellcheck="false"
                        description="3–30 lowercase letters, numbers, or underscores. Start with a letter; your username must be unique." />
                    <p class="text-sm leading-relaxed text-zinc-400">Changing your username changes your profile link. Existing connections stay; old links stop working.</p>
                    <flux:input wire:model="display_name" label="Display name" :placeholder="auth()->user()->name" maxlength="50"
                        description="Optional. The name shown on shared profiles. If blank, others see your username; your own greeting uses your registration name." />
                    <div class="space-y-2">
                        <flux:textarea wire:model="bio" label="Bio" placeholder="A short bio about yourself" rows="4" aria-describedby="bio-count"
                            description="Optional. Up to 160 characters of plain text." />
                        <p id="bio-count" class="text-right font-mono text-xs text-zinc-400" :class="{ 'text-red-400!': Array.from($wire.bio).length > 160 }">
                            <span x-text="Array.from($wire.bio).length">{{ mb_strlen($bio) }}</span> / 160 characters
                        </p>
                    </div>
                </div>
            </section>

            <div class="min-w-0 space-y-6">
                <section aria-labelledby="profile-privacy" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
                    <h2 id="profile-privacy" class="text-xl font-semibold text-zinc-100">Privacy</h2>
                    <div class="py-2">
                        <flux:checkbox wire:model="is_private" label="Private account" description="Require your approval before someone can see shared activity." />
                    </div>
                    <p class="text-sm leading-relaxed text-zinc-300">Turning this off lets signed-in users see your public habits and their history.</p>
                    <details class="group rounded-lg border border-zinc-800 px-3">
                        <summary class="min-h-11 cursor-pointer py-3 text-sm font-medium text-accent-content focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Learn about privacy</summary>
                        <div class="space-y-3 pb-4 text-sm leading-relaxed text-zinc-400">
                            <p>A private account gates all shared activity, including public habits, until you approve a follower. Your username and display name remain visible to signed-in visitors.</p>
                            <p>On a public account, signed-in users can see public habits. Followers-only habits require an accepted follow relationship. Private habits are always yours alone.</p>
                            <p>Existing accepted followers keep access. Pending requests stay pending when you change privacy; new follows to a public account are accepted immediately.</p>
                            <p>Journal, mood, habit notes, and N/A reasons stay private. This setting does not change individual habit visibility. New habits always default to private.</p>
                        </div>
                    </details>
                </section>

                <section aria-labelledby="profile-preferences" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
                    <h2 id="profile-preferences" class="text-xl font-semibold text-zinc-100">Preferences</h2>
                    <flux:select wire:model="timezone" label="Timezone" description="Your timezone determines when a new tracking day begins. Previously recorded dates stay unchanged.">
                        @foreach(timezone_identifiers_list() as $zone)<flux:select.option :value="$zone">{{ $zone }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:select wire:model="theme_key" label="Theme" description="The appearance saved for your account.">
                        <flux:select.option value="terminal-green">Terminal Green</flux:select.option>
                    </flux:select>
                </section>
            </div>
        </div>

        <div class="flex flex-col gap-4 border-t border-zinc-800 pt-5 sm:flex-row sm:items-center">
            <flux:button type="submit" variant="primary" :loading="false" wire:loading.attr="disabled" class="min-h-11 w-full sm:w-auto">
                <span wire:loading.remove wire:target="save">Save changes</span><span wire:loading wire:target="save">Saving…</span>
            </flux:button>
            <p class="text-sm text-zinc-400">Save your changes before leaving this page.</p>
            <p role="status" aria-live="polite" class="text-sm text-accent-content"><span x-show="saved" x-cloak>Saved.</span></p>
        </div>
    </form>
</div>
