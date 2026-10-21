<?php

use App\Livewire\Concerns\InteractsWithFollows;
use App\Models\Follow;
use App\Models\User;
use App\Services\SocialAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Profile')] class extends Component
{
    use InteractsWithFollows, WithPagination;

    #[Locked] public int $subjectId;

    public function mount(User $user): void
    {
        $this->viewerId = auth()->id();
        $this->subjectId = $user->id;
    }

    #[Computed]
    public function profile(): array
    {
        $viewer = $this->viewer();
        return DB::transaction(function () use ($viewer) {
            $subject = User::select(['id', 'username', 'display_name', 'is_private', 'timezone'])->findOrFail($this->subjectId);
            abort_if($subject->username === null, 404);
            $access = app(SocialAccess::class);
            $allowed = $access->profile($viewer, $subject);
            $relationship = Follow::where('follower_id', $viewer->id)->where('followed_id', $subject->id)->first(['id', 'status']);
            $data = ['name' => $subject->sharedName(), 'username' => $subject->username, 'private' => $subject->is_private,
                'own' => $viewer->id === $subject->id, 'allowed' => $allowed, 'canFollow' => $viewer->username !== null,
                'relationshipId' => $relationship?->id, 'relationshipStatus' => $relationship?->status->value];
            if ($allowed) {
                $this->authorize('viewShared', $subject);
                $fields = User::select(['bio', 'theme_key'])->findOrFail($subject->id);
                $data += ['bio' => $fields->bio, 'theme' => $fields->theme_key,
                    'followers' => $subject->followers()->where('status', 'accepted')->count(),
                    'following' => $subject->following()->where('status', 'accepted')->count(),
                    'today' => $subject->localToday(),
                    'habits' => $access->habits($viewer, $subject)->select(['id', 'name', 'visibility', 'archived_at'])
                        ->with(['versions' => fn ($query) => $query->select(['id', 'habit_id', 'effective_date', 'end_date'])->where('effective_date', '<=', $subject->localToday())->orderByDesc('effective_date')])
                        ->orderByDesc('id')->paginate(20)];
            }
            return $data;
        });
    }
}; ?>

<div class="space-y-6" x-data="{ followOrigin: null }" x-on:click="followOrigin = $event.target.closest('[data-follow-trigger]') ?? followOrigin"
    x-on:relationship-changed.window="$nextTick(() => document.getElementById('people-heading')?.focus())">
    <a href="{{ route('people.index') }}" wire:navigate class="text-emerald-300 underline">People</a>
    <section aria-label="Profile and connection" class="max-w-2xl space-y-4 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
        <header class="flex min-w-0 items-start gap-3">
            <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-full border border-emerald-900/60 bg-emerald-950/20 font-mono text-lg text-emerald-300">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($this->profile['name'], 0, 1)) }}</span>
            <div class="min-w-0 space-y-2">
                <h1 id="people-heading" tabindex="-1" class="scroll-mt-24 break-words text-2xl font-semibold tracking-tight focus-visible:outline-2 focus-visible:outline-emerald-300">{{ $this->profile['name'] }}</h1>
                <p class="break-all font-mono text-sm text-zinc-400">{{ '@'.$this->profile['username'] }}</p>
                <p class="text-sm text-zinc-300">{{ $this->profile['private'] ? 'Private account' : 'Public account' }}</p>
            </div>
        </header>
        @if($this->profile['own'])
            <p class="text-sm text-zinc-400">This is your profile. You see your private habits; other people see only habits they may access.</p>
        @elseif(!$this->profile['canFollow'])
            <a class="text-emerald-300 underline" href="{{ route('settings.profile') }}" wire:navigate>Choose a username to follow people</a>
        @elseif($this->profile['relationshipStatus'] === 'pending')
            <p class="text-sm text-zinc-300">Follow request pending.</p>
            <flux:button wire:click="changeFollow({{ $this->profile['relationshipId'] }}, 'cancel')" wire:loading.attr="disabled" variant="outline" class="min-h-11">Cancel request</flux:button>
        @elseif($this->profile['relationshipStatus'] === 'accepted')
            <p class="text-sm text-emerald-300">Following</p>
            <flux:button data-follow-trigger wire:click="confirmFollowRemoval({{ $this->profile['relationshipId'] }}, 'unfollow')" wire:loading.attr="disabled" variant="outline" class="min-h-11">Unfollow</flux:button>
        @else
            <flux:button wire:click="follow({{ $subjectId }})" wire:loading.attr="disabled" variant="primary" class="min-h-11">{{ $this->profile['private'] ? 'Request to follow' : 'Follow' }}</flux:button>
        @endif
        <p role="status" class="text-emerald-300">{{ $feedback }}</p>
        <flux:error name="relationship" />
    </section>
    <flux:button wire:click="refreshRelationships" wire:loading.attr="disabled">Refresh profile</flux:button>
    @if(!$this->profile['allowed'])
        <p>This account is private. An accepted follow request is required to see shared activity.</p>
    @else
        <p class="whitespace-pre-wrap break-words">{{ $this->profile['bio'] }}</p>
        <p class="text-sm text-zinc-400">{{ $this->profile['followers'] }} followers · {{ $this->profile['following'] }} following · {{ $this->profile['habits']->total() }} visible habits · Terminal Green</p>
        <livewire:activity-heatmap :subject-id="$subjectId" :key="'profile-activity-'.$subjectId" />
        <h2 class="text-xl font-semibold">Habits you can view</h2>
        <ul class="divide-y divide-zinc-800">
            @forelse($this->profile['habits'] as $habit)
                <li class="py-4" wire:key="shared-habit-{{ $habit->id }}">
                    <a href="{{ route('people.habit', ['user' => $this->profile['username'], 'habit' => $habit->id]) }}" wire:navigate class="break-words text-emerald-300 underline">{{ $habit->name }}</a>
                    <span class="text-sm text-zinc-400"> · {{ $habit->visibility->label() }}{{ $habit->archived_at ? ' · Archived' : '' }}{{ $habit->versions->first()?->end_date && $habit->versions->first()->end_date->toDateString() < $this->profile['today'] ? ' · Ended' : '' }}</span>
                </li>
            @empty<li class="py-4 text-zinc-400">No habits to display.</li>@endforelse
        </ul>
        {{ $this->profile['habits']->links() }}
    @endif
    <x-follow-confirmation />
</div>
