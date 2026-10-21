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

<div class="min-w-0 space-y-6 sm:space-y-8" x-data="{ followOrigin: null }" x-on:click="followOrigin = $event.target.closest('[data-follow-trigger]') ?? followOrigin"
    x-on:relationship-changed.window="$nextTick(() => document.getElementById('people-heading')?.focus())">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-terminal-path path="~/people" />
            <h1 id="people-heading" tabindex="-1" class="mt-3 scroll-mt-24 text-3xl font-semibold tracking-tight text-zinc-100 focus-visible:outline-2 focus-visible:outline-emerald-300 sm:text-4xl">People</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">Build connections. Share progress on your terms.</p>
        </div>
        @if($this->relationships['username'])
            <flux:button :href="route('people.show', ['user' => $this->relationships['username']])" wire:navigate variant="outline" icon="user-circle" class="min-h-11">My profile</flux:button>
        @endif
    </header>

    @unless($this->relationships['username'])
        <section aria-labelledby="username-setup" class="flex flex-col gap-4 rounded-xl border border-emerald-900/60 bg-emerald-950/20 p-5 sm:flex-row sm:items-center sm:p-6">
            <flux:icon.user-circle class="size-8 shrink-0 text-emerald-300" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <h2 id="username-setup" class="text-lg font-semibold text-zinc-100">Set up your username</h2>
                <p class="mt-2 text-sm leading-relaxed text-zinc-400">Choose a unique username so you can follow others. You can still look up profiles before setting it up.</p>
            </div>
            <flux:button :href="route('settings.profile')" wire:navigate variant="primary" class="min-h-11">Go to Profile Settings</flux:button>
        </section>
    @endunless

    <section aria-labelledby="find-someone" class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
        <div>
            <h2 id="find-someone" class="text-xl font-semibold text-zinc-100">Find someone</h2>
            <p id="people-search-help" class="mt-2 text-sm leading-relaxed text-zinc-400">Enter an exact username to open their profile. Following is one-way; private accounts approve requests.</p>
        </div>
        <form wire:submit="lookup" aria-label="Find someone" class="flex max-w-xl flex-col items-stretch gap-3 sm:flex-row sm:items-start">
            <div class="min-w-0 flex-1">
                <flux:input id="people-username" wire:model="username" label="Exact username" maxlength="30" aria-required="true" aria-describedby="people-search-help" autocomplete="off" autocapitalize="none" spellcheck="false" class="scroll-mt-24" />
            </div>
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled" class="min-h-11 sm:mt-7">
                <span wire:loading.remove wire:target="lookup">Find profile</span><span wire:loading wire:target="lookup">Finding…</span>
            </flux:button>
        </form>
        <p wire:loading wire:target="lookup" role="status" class="text-sm text-zinc-400">Looking up this username…</p>
    </section>

    <section aria-labelledby="your-connections" class="min-w-0 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="your-connections" class="text-xl font-semibold text-zinc-100">Your connections</h2>
            <flux:button wire:click="refreshRelationships" variant="ghost" icon="arrow-path" wire:loading.attr="disabled" class="min-h-11">
                <span wire:loading.remove wire:target="refreshRelationships">Refresh list</span><span wire:loading wire:target="refreshRelationships">Refreshing…</span>
            </flux:button>
        </div>
        <div class="grid grid-cols-2 gap-2 rounded-xl border border-zinc-800 bg-zinc-900/30 p-2 sm:flex sm:flex-wrap" role="group" aria-label="Your relationships">
            @foreach(['following' => 'Following', 'followers' => 'Followers', 'incoming' => 'Incoming requests', 'sent' => 'Sent requests'] as $key => $label)
                <flux:button wire:click="setTab('{{ $key }}')" :variant="$tab === $key ? 'primary' : 'ghost'" :aria-pressed="$tab === $key ? 'true' : 'false'" aria-controls="connection-list" wire:key="people-view-{{ $key }}" class="min-h-11">{{ $label }}</flux:button>
            @endforeach
        </div>
        <div role="status" aria-live="polite" class="text-sm text-emerald-300"><span>{{ $feedback }}</span><span wire:loading wire:target="refreshRelationships" class="text-zinc-400">Refreshing connections…</span></div>
        <flux:error name="relationship" />
        @php
            $view = match ($tab) {
                'following' => ['empty' => "You're not following anyone yet.", 'help' => 'Find someone by their username to start connecting.', 'status' => 'Following', 'icon' => 'user-plus'],
                'followers' => ['empty' => 'No followers yet.', 'help' => 'Your followers will appear here when someone connects with you.', 'status' => 'Follows you', 'icon' => 'users'],
                'incoming' => ['empty' => 'No incoming requests.', 'help' => 'Follow requests will appear here when someone asks to connect.', 'status' => 'Wants to follow you · Awaiting your approval', 'icon' => 'inbox'],
                'sent' => ['empty' => 'No pending requests.', 'help' => "Follow requests you've sent will appear here while awaiting a response.", 'status' => 'Request sent · Awaiting approval', 'icon' => 'paper-airplane'],
            };
        @endphp
        <div id="connection-list" class="min-w-0">
            @if($this->relationships['rows']->isEmpty())
                <div class="space-y-3 rounded-xl border border-dashed border-zinc-700 bg-zinc-900/30 p-6 sm:p-8">
                    <flux:icon :name="$view['icon']" class="size-6 text-zinc-400" aria-hidden="true" />
                    @if($this->relationships['rows']->currentPage() > 1)
                        <h3 class="font-semibold text-zinc-100">No connections on this page.</h3>
                        <p class="text-sm leading-relaxed text-zinc-400">The list may have changed. Return to the first page to see current connections.</p>
                        <flux:button wire:click="gotoPage(1)" variant="outline" wire:loading.attr="disabled" class="min-h-11">Return to first page</flux:button>
                    @else
                        <h3 class="font-semibold text-zinc-100">{{ $view['empty'] }}</h3>
                        <p class="text-sm leading-relaxed text-zinc-400">{{ $view['help'] }}</p>
                        @if($tab === 'following')<flux:button href="#people-username" variant="outline" class="min-h-11">Find someone</flux:button>@endif
                    @endif
                </div>
            @else
                <ul class="space-y-3" aria-label="Connections in this view">
                    @foreach($this->relationships['rows'] as $relationship)
                        @php($person = $relationship->{$this->relationships['person']})
                        <li wire:key="follow-{{ $relationship->id }}" data-connection-card class="flex min-w-0 flex-col gap-4 rounded-xl border border-zinc-800 bg-zinc-900/30 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex min-w-0 items-start gap-3">
                                <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-full border border-emerald-900/60 bg-emerald-950/20 font-mono text-sm text-emerald-300">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($person->sharedName(), 0, 1)) }}</span>
                                <div class="min-w-0 space-y-1">
                                    @if($person->username)
                                        <a class="block break-words rounded font-semibold text-zinc-100 hover:text-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300" href="{{ route('people.show', ['user' => $person->username]) }}" wire:navigate>
                                            {{ $person->sharedName() }}<span class="mt-1 block break-all font-mono text-xs font-normal text-zinc-400">{{ '@'.$person->username }}</span>
                                        </a>
                                    @else<span class="text-zinc-400">Unavailable profile</span>@endif
                                    <p @class(['text-sm leading-relaxed', 'text-emerald-300' => in_array($tab, ['following', 'followers']), 'text-zinc-400' => in_array($tab, ['incoming', 'sent'])])>{{ $view['status'] }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2" role="group" aria-label="Actions for {{ $person->sharedName() }}">
                                @if($tab === 'incoming')
                                    <flux:button wire:click="changeFollow({{ $relationship->id }}, 'approve')" variant="primary" wire:loading.attr="disabled" class="min-h-11">Approve</flux:button>
                                    <flux:button wire:click="changeFollow({{ $relationship->id }}, 'reject')" variant="outline" wire:loading.attr="disabled" class="min-h-11">Reject</flux:button>
                                @elseif($tab === 'sent')
                                    <flux:button wire:click="changeFollow({{ $relationship->id }}, 'cancel')" variant="outline" wire:loading.attr="disabled" class="min-h-11">Cancel request</flux:button>
                                @else
                                    <flux:button data-follow-trigger wire:click="confirmFollowRemoval({{ $relationship->id }}, '{{ $tab === 'following' ? 'unfollow' : 'remove' }}')" variant="outline" wire:loading.attr="disabled" class="min-h-11">{{ $tab === 'following' ? 'Unfollow' : 'Remove follower' }}</flux:button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        {{ $this->relationships['rows']->links() }}
    </section>
    <x-follow-confirmation />
</div>
