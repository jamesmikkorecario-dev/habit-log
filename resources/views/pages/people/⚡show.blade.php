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
    <header class="space-y-2">
        <h1 id="people-heading" tabindex="-1" class="break-words text-2xl font-semibold focus-visible:outline-2 focus-visible:outline-emerald-300">{{ $this->profile['name'] }}</h1>
        <p>{{ '@'.$this->profile['username'] }} · {{ $this->profile['private'] ? 'Private account' : 'Public account' }}</p>
    </header>
    @if($this->profile['own'])
        <p class="text-sm text-zinc-400">This is your profile. You see your private habits; other people see only habits they may access.</p>
    @elseif(!$this->profile['canFollow'])
        <a class="text-emerald-300 underline" href="{{ route('settings.profile') }}" wire:navigate>Choose a username to follow people</a>
    @elseif($this->profile['relationshipStatus'] === 'pending')
        <p>Follow request pending.</p>
        <flux:button wire:click="changeFollow({{ $this->profile['relationshipId'] }}, 'cancel')" wire:loading.attr="disabled">Cancel request</flux:button>
    @elseif($this->profile['relationshipStatus'] === 'accepted')
        <p>Following</p>
        <flux:button data-follow-trigger wire:click="confirmFollowRemoval({{ $this->profile['relationshipId'] }}, 'unfollow')" wire:loading.attr="disabled">Unfollow</flux:button>
    @else
        <flux:button wire:click="follow({{ $subjectId }})" wire:loading.attr="disabled">{{ $this->profile['private'] ? 'Request to follow' : 'Follow' }}</flux:button>
    @endif
    <p role="status" class="text-emerald-300">{{ $feedback }}</p>
    <flux:error name="relationship" />
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
