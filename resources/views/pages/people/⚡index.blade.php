<?php

use App\Livewire\Concerns\InteractsWithFollows;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('People')] class extends Component
{
    use InteractsWithFollows, WithPagination;

    public string $username = '';
    public string $tab = 'following';

    public function mount(): void
    {
        $this->viewerId = auth()->id();
    }

    public function lookup(): void
    {
        $this->viewer();
        $this->username = strtolower(trim($this->username));
        $this->validate(['username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/']]);
        if (! User::where('username', $this->username)->exists()) {
            throw ValidationException::withMessages(['username' => 'No profile found for that username.']);
        }
        $this->redirectRoute('people.show', ['user' => $this->username], navigate: true);
    }

    public function setTab(string $tab): void
    {
        $this->viewer();
        abort_unless(in_array($tab, ['following', 'followers', 'incoming', 'sent'], true), 422);
        $this->tab = $tab;
        $this->resetPage();
        $this->refreshRelationships();
    }

    #[Computed]
    public function relationships(): array
    {
        $viewer = $this->viewer();
        abort_unless(in_array($this->tab, ['following', 'followers', 'incoming', 'sent'], true), 422);
        $outgoing = in_array($this->tab, ['following', 'sent'], true);
        $person = $outgoing ? 'followed' : 'follower';
        $status = in_array($this->tab, ['following', 'followers'], true) ? 'accepted' : 'pending';
        $rows = Follow::query()->where($outgoing ? 'follower_id' : 'followed_id', $viewer->id)
            ->where('status', $status)->with($person.':id,username,display_name')->orderByDesc('id')->paginate(20);

        return ['rows' => $rows, 'person' => $person, 'username' => $viewer->username];
    }
}; ?>

<div class="space-y-6" x-data="{ followOrigin: null }" x-on:click="followOrigin = $event.target.closest('[data-follow-trigger]') ?? followOrigin"
    x-on:relationship-changed.window="$nextTick(() => document.getElementById('people-heading')?.focus())">
    <h1 id="people-heading" tabindex="-1" class="text-2xl font-semibold focus-visible:outline-2 focus-visible:outline-emerald-300">People</h1>
    <p class="text-sm text-zinc-400">Find a profile by its exact username. Following is one-way; private accounts approve requests.</p>
    @if($this->relationships['username'])
        <flux:button :href="route('people.show', ['user' => $this->relationships['username']])" wire:navigate>My profile</flux:button>
    @else
        <p>Choose a username in <a class="text-emerald-300 underline" href="{{ route('settings.profile') }}" wire:navigate>profile settings</a> before starting a follow relationship.</p>
    @endif
    <form wire:submit="lookup" class="flex max-w-xl flex-wrap items-end gap-3">
        <flux:input wire:model="username" label="Exact username" maxlength="30" />
        <flux:button type="submit" wire:loading.attr="disabled">Find profile</flux:button>
    </form>
    <nav class="flex flex-wrap gap-2" aria-label="Your relationships">
        @foreach(['following' => 'Following', 'followers' => 'Followers', 'incoming' => 'Incoming requests', 'sent' => 'Sent requests'] as $key => $label)
            <flux:button wire:click="setTab('{{ $key }}')" :variant="$tab === $key ? 'primary' : 'outline'" :aria-current="$tab === $key ? 'page' : null">{{ $label }}</flux:button>
        @endforeach
    </nav>
    <p role="status" class="text-emerald-300">{{ $feedback }}</p>
    <flux:error name="relationship" />
    <flux:button wire:click="refreshRelationships" wire:loading.attr="disabled">Refresh list</flux:button>
    <ul class="divide-y divide-zinc-800">
        @forelse($this->relationships['rows'] as $relationship)
            @php($person = $relationship->{$this->relationships['person']})
            <li wire:key="follow-{{ $relationship->id }}" class="flex flex-wrap items-center justify-between gap-3 py-4">
                <div class="min-w-0 break-words">
                    @if($person->username)<a class="text-emerald-300 underline" href="{{ route('people.show', ['user' => $person->username]) }}" wire:navigate>{{ $person->sharedName() }} · {{ '@'.$person->username }}</a>@else<span>Unavailable profile</span>@endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($tab === 'incoming')
                        <flux:button wire:click="changeFollow({{ $relationship->id }}, 'approve')" wire:loading.attr="disabled">Approve</flux:button>
                        <flux:button wire:click="changeFollow({{ $relationship->id }}, 'reject')" wire:loading.attr="disabled">Reject</flux:button>
                    @elseif($tab === 'sent')
                        <flux:button wire:click="changeFollow({{ $relationship->id }}, 'cancel')" wire:loading.attr="disabled">Cancel request</flux:button>
                    @else
                        <flux:button data-follow-trigger wire:click="confirmFollowRemoval({{ $relationship->id }}, '{{ $tab === 'following' ? 'unfollow' : 'remove' }}')" wire:loading.attr="disabled">{{ $tab === 'following' ? 'Unfollow' : 'Remove follower' }}</flux:button>
                    @endif
                </div>
            </li>
        @empty<li class="py-6 text-zinc-400">No relationships in this list.</li>@endforelse
    </ul>
    {{ $this->relationships['rows']->links() }}
    <x-follow-confirmation />
</div>
