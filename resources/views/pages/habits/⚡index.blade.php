<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Models\Category;
use App\Models\Habit;
use App\Actions\Habits\TransitionHabit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Habits')] class extends Component
{
    use RequiresSameUser;

    use WithPagination;

    #[Url]
    public string $tab = 'active';
    #[Url]
    public string $category = '';
    #[Locked]
    public ?int $pendingTrashId = null;
    #[Locked]
    public string $pendingTrashName = '';
    public bool $showTrashModal = false;
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Habit::class);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        $this->authorize('viewAny', Category::class);
        return Category::availableTo(auth()->user())->orderBy('normalized_name')->get();
    }

    #[Computed]
    public function habits()
    {
        $this->authorize('viewAny', Habit::class);
        $query = auth()->user()->habits()->with('category');
        match ($this->tab) {
            'trash' => $query->onlyTrashed(),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };
        if ($this->category !== '') {
            $query->where('category_id', $this->category);
        }
        return $query->orderByDesc('id')->paginate(20);
    }

    public function archive(int $id): void
    {
        $this->applyLifecycleTransition($id, 'archive');
        $this->status = 'Habit archived. Its configuration is preserved.';
    }

    public function unarchive(int $id): void
    {
        $this->applyLifecycleTransition($id, 'unarchive');
        $this->status = 'Habit unarchived. Its dates are unchanged.';
    }

    public function restore(int $id): void
    {
        $this->applyLifecycleTransition($id, 'restore');
        $this->status = 'Habit restored to its previous active or archived state.';
    }

    public function confirmTrash(int $id): void
    {
        $habit = auth()->user()->habits()->findOrFail($id);
        $this->authorize('delete', $habit);
        $this->pendingTrashId = $habit->id;
        $this->pendingTrashName = $habit->name;
        $this->showTrashModal = true;
    }

    public function trash(): void
    {
        abort_if($this->pendingTrashId === null, 404);
        $this->applyLifecycleTransition($this->pendingTrashId, 'delete');
        $this->pendingTrashId = null;
        $this->pendingTrashName = '';
        $this->showTrashModal = false;
        $this->status = 'Habit moved to trash. You can restore it from the Trash tab.';
    }

    private function applyLifecycleTransition(int $id, string $action): void
    {
        app(TransitionHabit::class)->handle(auth()->user(), $id, $action);
        unset($this->habits);
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h1 class="text-2xl font-semibold">Your habits</h1><p class="mt-2 text-sm text-zinc-400">Build a routine at your own pace.</p></div>
        <flux:button :href="route('habits.create')" variant="primary" wire:navigate>Create a habit</flux:button>
    </div>
    <div role="status" aria-live="polite" class="text-sm text-emerald-300">{{ $status ?: session('status') }}</div>
    <div class="grid items-end gap-4 sm:grid-cols-2">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Habit lists">
            @foreach(['active' => 'Active', 'archived' => 'Archived', 'trash' => 'Trash'] as $value => $label)
                <flux:button wire:click="$set('tab', '{{ $value }}')" :variant="$tab === $value ? 'primary' : 'filled'" :aria-pressed="$tab === $value ? 'true' : 'false'">{{ $label }}</flux:button>
            @endforeach
        </div>
        <flux:select wire:model.live="category" label="Filter by category">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach($this->categories as $option)<flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>@endforeach
        </flux:select>
    </div>
    @if($tab === 'trash')<p class="text-sm text-zinc-400">Trashed habits stay recoverable. Automatic expiry and permanent deletion are not available.</p>@endif
    <div class="space-y-3" wire:loading.class="opacity-60" wire:target="tab,category">
        @forelse($this->habits as $habit)
            <article wire:key="habit-{{ $habit->id }}" class="space-y-4 rounded-xl border border-emerald-900/50 p-4 sm:p-5">
                <div class="min-w-0 space-y-2">
                    <h2 class="break-words text-lg font-medium">
                        @if($habit->trashed()){{ $habit->name }}@else<flux:link :href="route('habits.show', $habit->id)" wire:navigate>{{ $habit->name }}</flux:link>@endif
                    </h2>
                    <p class="break-words text-sm text-zinc-300">{{ $habit->category->name }} · {{ $habit->type->label() }} · {{ $habit->visibility->label() }}</p>
                    <p class="text-sm text-zinc-400">{{ $habit->trashed() ? 'Trashed' : ($habit->archived_at ? 'Archived' : 'Active') }} · {{ $habit->start_date->toDateString() }}{{ $habit->end_date ? ' to '.$habit->end_date->toDateString() : ' onward' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($habit->trashed())
                        <flux:button wire:click="restore({{ $habit->id }})" :aria-label="'Restore '.$habit->name">Restore</flux:button>
                    @else
                        <flux:button :href="route('habits.edit', $habit->id)" wire:navigate :aria-label="'Edit '.$habit->name">Edit</flux:button>
                        @if($habit->archived_at)
                            <flux:button wire:click="unarchive({{ $habit->id }})" :aria-label="'Unarchive '.$habit->name">Unarchive</flux:button>
                        @else
                            <flux:button wire:click="archive({{ $habit->id }})" :aria-label="'Archive '.$habit->name">Archive</flux:button>
                        @endif
                        <flux:button wire:click="confirmTrash({{ $habit->id }})" :data-trash-trigger="$habit->id" :aria-label="'Move '.$habit->name.' to trash'">Move to trash</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-emerald-900/50 p-8 text-center">
                <h2 class="font-medium">No habits in this list</h2>
                <p class="mt-2 text-sm text-zinc-400">{{ $category !== '' ? 'Try another category filter.' : ($tab === 'trash' ? 'Habits you move to trash will appear here.' : ($tab === 'archived' ? 'Archived habits will appear here.' : 'Create your first habit to get started.')) }}</p>
            </div>
        @endforelse
    </div>
    {{ $this->habits->links() }}
    <flux:modal name="trash-habit" wire:model="showTrashModal"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'trash-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'trash-description')"
        x-on:close="$nextTick(() => document.querySelector('[data-trash-trigger=&quot;' + $wire.pendingTrashId + '&quot;]')?.focus())"
        class="w-[calc(100%-2rem)] min-w-0! space-y-5">
        <h2 id="trash-title" class="pr-6 text-lg font-semibold">Move habit to trash?</h2>
        <p id="trash-description" class="break-words text-sm text-zinc-300">“{{ $pendingTrashName }}” will leave your active and archived lists. Its configuration stays intact, and you can restore it from Trash.</p>
        <div class="flex flex-wrap justify-end gap-3">
            <flux:modal.close><flux:button autofocus>Cancel</flux:button></flux:modal.close>
            <flux:button wire:click="trash" variant="danger">Confirm move to trash</flux:button>
        </div>
    </flux:modal>
</div>
