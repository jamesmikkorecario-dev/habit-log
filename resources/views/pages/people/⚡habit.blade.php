<?php

use App\Models\User;
use App\Services\SocialAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Shared habit')] class extends Component
{
    #[Locked] public int $viewerId;
    #[Locked] public int $subjectId;
    #[Locked] public int $habitId;

    public function mount(User $user, int $habit): void
    {
        $this->viewerId = auth()->id();
        $this->subjectId = $user->id;
        $this->habitId = $habit;
    }

    public function hydrate(): void
    {
        abort_unless(auth()->check() && auth()->id() === $this->viewerId, 403);
    }

    #[Computed]
    public function detail(): array
    {
        $this->hydrate();
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

<div class="space-y-6">
    <a href="{{ route('people.show', ['user' => $this->detail['username']]) }}" wire:navigate class="text-emerald-300 underline">{{ '@'.$this->detail['username'] }}</a>
    <h1 class="break-words text-2xl font-semibold">{{ $this->detail['habit']->name }}</h1>
    <p class="whitespace-pre-wrap break-words">{{ $this->detail['habit']->description }}</p>
    <p class="text-zinc-400">{{ $this->detail['habit']->category->name }} · {{ $this->detail['habit']->visibility->label() }}{{ $this->detail['habit']->archived_at ? ' · Archived' : '' }}</p>
    @if($this->detail['setup'])
        <p class="text-sm">{{ $this->detail['setup']->type->label() }} · Starts {{ $this->detail['setup']->start_date->toDateString() }}{{ $this->detail['setup']->end_date ? ' · Ends '.$this->detail['setup']->end_date->toDateString() : '' }}
            · {{ $this->detail['setup']->goal_value === null ? 'No numeric goal' : 'Goal / limit: '.$this->detail['setup']->goal_value.' '.$this->detail['setup']->goal_unit }}
            {{ $this->detail['setup']->end_date && $this->detail['setup']->end_date->toDateString() < $this->detail['today'] ? ' · Ended' : '' }}</p>
    @endif
    <p class="text-sm text-zinc-400">Read-only activity. Notes, N/A reasons, journals and mood are never shared.</p>
    <livewire:activity-heatmap :subject-id="$subjectId" :habit-id="$habitId" />
</div>
