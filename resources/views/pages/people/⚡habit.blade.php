<?php

use App\Enums\HabitType;
use App\Livewire\Concerns\RedirectsRevokedSharedAccess;
use App\Models\User;
use App\Services\SocialAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shared habit')] class extends Component
{
    use RedirectsRevokedSharedAccess;

    #[Locked] public int $viewerId;
    #[Locked] public int $subjectId;
    #[Locked] public int $habitId;
    #[Locked] public int $activityRevision = 0;

    public function refreshActivity(): void
    {
        $this->authorizeViewer();
        unset($this->detail);
        $this->activityRevision++;
        $this->detail;
    }

    #[On('shared-activity-updated.{subjectId}')]
    public function refreshMetadata(): void
    {
        $this->authorizeViewer();
        unset($this->detail);
        $this->detail;
    }

    public function mount(User $user, int $habit): void
    {
        $this->viewerId = auth()->id();
        $this->subjectId = $user->id;
        $this->habitId = $habit;
    }

    public function hydrate(): void
    {
        $this->authorizeViewer();
        $this->detail;
    }

    private function authorizeViewer(): void
    {
        abort_unless(auth()->check() && auth()->id() === $this->viewerId, 403);
    }

    #[Computed]
    public function detail(): array
    {
        $this->authorizeViewer();
        return DB::transaction(function () {
            $viewer = auth()->user()->fresh();
            $subject = User::select(['id', 'username', 'display_name', 'is_private', 'timezone'])->findOrFail($this->subjectId);
            $habit = app(SocialAccess::class)->habits($viewer, $subject)->with('category:id,name')
                ->select(['id', 'user_id', 'category_id', 'name', 'description', 'visibility', 'archived_at'])->findOrFail($this->habitId);
            $this->authorize('viewShared', $habit);
            $setup = $habit->versions()->where('effective_date', '<=', $subject->localToday())->orderByDesc('effective_date')
                ->first(['type', 'start_date', 'end_date', 'goal_value', 'goal_unit']);
            return ['username' => $subject->username, 'habit' => $habit, 'setup' => $setup, 'today' => $subject->localToday()];
        });
    }
}; ?>

<div class="min-w-0 space-y-6" data-shared-habit>
    <header class="space-y-3">
        <x-terminal-path path="~/people/habits/details" />
        <a href="{{ route('people.show', ['user' => $this->detail['username']]) }}" wire:navigate class="inline-flex min-h-11 max-w-full items-center gap-2 text-emerald-300 underline" aria-label="Back to profile: {{ '@'.$this->detail['username'] }}"><span aria-hidden="true">←</span><span class="min-w-0 break-all">{{ '@'.$this->detail['username'] }}</span></a>
        <h1 class="break-words text-3xl font-semibold tracking-tight sm:text-4xl">{{ $this->detail['habit']->name }}</h1>
    </header>
    <p class="whitespace-pre-wrap break-words">{{ $this->detail['habit']->description }}</p>
    <p class="break-words text-zinc-400">{{ $this->detail['habit']->category->name }} · {{ $this->detail['habit']->visibility->label() }}{{ $this->detail['habit']->archived_at ? ' · Archived' : '' }}</p>
    @if($this->detail['setup'])
        <p class="break-words text-sm">{{ $this->detail['setup']->type->label() }} · Starts {{ $this->detail['setup']->start_date->toDateString() }}{{ $this->detail['setup']->end_date ? ' · Ends '.$this->detail['setup']->end_date->toDateString() : '' }}
            @if(in_array($this->detail['setup']->type, [HabitType::Count, HabitType::Duration, HabitType::Avoidance], true))
                · {{ $this->detail['setup']->type === HabitType::Avoidance ? 'Maximum' : 'Goal' }}: {{ $this->detail['setup']->goal_value === null ? 'Not set · Uses personal assessment' : $this->detail['setup']->goal_value.' '.$this->detail['setup']->goal_unit }}
            @elseif($this->detail['setup']->type === HabitType::Checkpoint) · Performance uses completed checkpoints.
            @else · Performance uses the recorded outcome.
            @endif
            {{ $this->detail['setup']->end_date && $this->detail['setup']->end_date->toDateString() < $this->detail['today'] ? ' · Ended' : '' }}</p>
    @endif
    <p class="text-sm text-zinc-400">Read-only activity. Notes, N/A reasons, journals and mood are never shared.</p>
    <div class="flex flex-wrap items-center gap-3">
        <flux:button wire:click="refreshActivity" class="min-h-11">Refresh activity</flux:button>
        <span wire:loading wire:target="refreshActivity" role="status" class="text-sm text-zinc-400">Refreshing activity…</span>
    </div>
    <livewire:activity-heatmap :subject-id="$subjectId" :habit-id="$habitId" :key="'shared-habit-'.$habitId.'-'.$activityRevision" />
</div>
