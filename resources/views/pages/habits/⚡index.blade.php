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
        $query = auth()->user()->habits()->with('category')->withCount('checkpoints');
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

    #[Computed]
    public function hasHabits(): bool
    {
        $this->authorize('viewAny', Habit::class);
        return auth()->user()->habits()->withTrashed()->exists();
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

<div class="min-w-0 space-y-6 sm:space-y-8" data-habits-management>
    <header class="flex flex-wrap items-start justify-between gap-5">
        <div>
            <p class="font-mono text-xs uppercase tracking-widest text-emerald-300">Build your routine</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Your habits</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">Small actions. Meaningful progress.</p>
        </div>
        <flux:button :href="route('habits.create')" variant="primary" icon="plus" class="min-h-11 w-full sm:w-auto" wire:navigate>Create a habit</flux:button>
    </header>
    <div role="status" aria-live="polite" class="empty:hidden text-sm text-emerald-300">{{ $status ?: session('status') }}</div>
    <section aria-label="Filter habits" class="flex flex-wrap items-end justify-between gap-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-4 sm:p-5">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Habit lists">
            @foreach(['active' => 'Active', 'archived' => 'Archived', 'trash' => 'Trash'] as $value => $label)
                @php($selected = $value === (in_array($tab, ['archived', 'trash'], true) ? $tab : 'active'))
                <flux:button wire:click="$set('tab', '{{ $value }}')" :variant="$selected ? 'primary' : 'ghost'" :aria-pressed="$selected ? 'true' : 'false'" class="min-h-11">{{ $label }}</flux:button>
            @endforeach
        </div>
        <div class="w-full min-w-0 sm:w-56">
            <flux:select wire:model.live="category" label="Filter by category" class="min-h-11">
                <flux:select.option value="">All categories</flux:select.option>
                @foreach($this->categories as $option)<flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>@endforeach
            </flux:select>
        </div>
    </section>
    @if($tab === 'trash')
        <p class="text-sm leading-relaxed text-zinc-400">Trashed habits stay recoverable. Restore returns each habit to its previous active or archived state. Automatic expiry and permanent deletion are not available.</p>
    @elseif($tab === 'archived')
        <p class="text-sm leading-relaxed text-zinc-400">A pause in your routine. Archived habits keep their history and dates; unarchive whenever you are ready.</p>
    @endif
    <div class="space-y-3" wire:loading.class="opacity-60" wire:target="tab,category">
        @forelse($this->habits as $habit)
            <article wire:key="habit-{{ $habit->id }}" data-habit-card class="min-w-0 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1 space-y-2">
                        <h2 class="break-words text-lg font-semibold text-zinc-100">
                            @if($habit->trashed()){{ $habit->name }}@else<flux:link :href="route('habits.show', $habit->id)" wire:navigate>{{ $habit->name }}</flux:link>@endif
                        </h2>
                        <p class="break-words text-sm text-zinc-400">{{ $habit->category->name }}</p>
                    </div>
                    <span @class(['rounded-md border px-2.5 py-1 text-xs font-medium', 'border-emerald-900/60 bg-emerald-950/40 text-emerald-300' => !$habit->trashed() && !$habit->archived_at, 'border-zinc-700 bg-zinc-800/50 text-zinc-300' => $habit->trashed() || $habit->archived_at])>{{ $habit->trashed() ? 'Trashed' : ($habit->archived_at ? 'Archived' : 'Active') }}</span>
                </div>
                <dl class="mt-5 grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                    <div class="min-w-0">
                        <dt class="text-xs text-zinc-400">Saved tracking setup</dt>
                        <dd class="mt-1 text-zinc-200">{{ $habit->type->label() }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-zinc-400">{{ $habit->type->value === 'avoidance' ? 'Maximum' : ($habit->type->value === 'checkpoint' ? 'Checkpoints' : 'Goal') }}</dt>
                        <dd class="mt-1 break-words text-zinc-200">
                            @if($habit->type->value === 'checkpoint')
                                {{ $habit->checkpoints_count }} {{ $habit->checkpoints_count === 1 ? 'checkpoint' : 'checkpoints' }}
                            @elseif($habit->type->value === 'boolean')
                                Yes or no
                            @elseif($habit->goal_value !== null)
                                {{ number_format($habit->goal_value) }} {{ $habit->goal_unit }}
                            @else
                                {{ $habit->type->value === 'avoidance' ? 'No maximum set' : 'No numeric goal' }}
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-zinc-400">Saved dates</dt>
                        <dd class="mt-1 text-zinc-200">{{ $habit->start_date->toDateString() }}{{ $habit->end_date ? ' to '.$habit->end_date->toDateString() : ' onward' }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-zinc-400">Visibility preference</dt>
                        <dd class="mt-1 text-zinc-200">{{ $habit->visibility->label() }}</dd>
                    </div>
                </dl>
                <div class="mt-5 flex flex-wrap gap-2 border-t border-zinc-800 pt-4">
                    @if($habit->trashed())
                        <flux:button wire:click="restore({{ $habit->id }})" :aria-label="'Restore '.$habit->name" icon="arrow-uturn-left" class="min-h-11">Restore</flux:button>
                    @else
                        <flux:button :href="route('habits.edit', $habit->id)" wire:navigate :aria-label="'Edit '.$habit->name" class="min-h-11">Edit</flux:button>
                        @if($habit->archived_at)
                            <flux:button wire:click="unarchive({{ $habit->id }})" :aria-label="'Unarchive '.$habit->name" variant="ghost" class="min-h-11">Unarchive</flux:button>
                        @else
                            <flux:button wire:click="archive({{ $habit->id }})" :aria-label="'Archive '.$habit->name" variant="ghost" class="min-h-11">Archive</flux:button>
                        @endif
                        <flux:button wire:click="confirmTrash({{ $habit->id }})" :data-trash-trigger="$habit->id" :aria-label="'Move '.$habit->name.' to trash'" variant="ghost" class="min-h-11 text-red-300! sm:ml-auto">Move to trash</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <section aria-label="Empty habit list" class="rounded-xl border border-zinc-800 bg-zinc-900/30 px-5 py-10 sm:px-8 sm:py-12">
                <div class="mx-auto max-w-md space-y-4 text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-xl border border-emerald-900/50 bg-emerald-950/30 text-emerald-300" aria-hidden="true"><flux:icon name="{{ $tab === 'trash' ? 'trash' : ($tab === 'archived' ? 'archive-box' : 'squares-plus') }}" class="size-5" /></div>
                    @if($this->habits->total() > 0)
                        <h2 class="text-xl font-semibold text-zinc-100">No habits on this page.</h2>
                        <p class="text-sm leading-relaxed text-zinc-400">Your habits are on an earlier page in this list.</p>
                        <flux:button wire:click="setPage(1)" class="min-h-11">Return to first page</flux:button>
                    @elseif($category !== '')
                        <h2 class="text-xl font-semibold text-zinc-100">No habits found in this category.</h2>
                        <p class="text-sm leading-relaxed text-zinc-400">Try another category or show all categories in this list.</p>
                        <flux:button wire:click="$set('category', '')" class="min-h-11">Show all categories</flux:button>
                    @elseif($tab === 'archived')
                        <h2 class="text-xl font-semibold text-zinc-100">No archived habits yet.</h2>
                        <p class="text-sm leading-relaxed text-zinc-400">Habits you archive will appear here, with their history preserved.</p>
                    @elseif($tab === 'trash')
                        <h2 class="text-xl font-semibold text-zinc-100">Your trash is empty.</h2>
                        <p class="text-sm leading-relaxed text-zinc-400">Habits you move to trash will appear here for recovery.</p>
                    @elseif(!$this->hasHabits)
                        <h2 class="text-xl font-semibold text-zinc-100">Your first habit starts here.</h2>
                        <p class="text-sm leading-relaxed text-zinc-400">Start small. Choose something meaningful, and build consistency one day at a time.</p>
                        <flux:button :href="route('habits.create')" variant="primary" class="min-h-11" wire:navigate>Create your first habit</flux:button>
                    @else
                        <h2 class="text-xl font-semibold text-zinc-100">No active habits right now.</h2>
                        <p class="text-sm leading-relaxed text-zinc-400">Your existing habits are in Archived or Trash. You can bring one back whenever you are ready.</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <flux:button wire:click="$set('tab', 'archived')" class="min-h-11">Review archived habits</flux:button>
                            <flux:button wire:click="$set('tab', 'trash')" variant="ghost" class="min-h-11">Review trash</flux:button>
                        </div>
                    @endif
                </div>
            </section>
        @endforelse
    </div>
    @if($this->habits->isNotEmpty() && $tab !== 'trash')
        <p class="text-xs leading-relaxed text-zinc-400">Lists show saved configuration. Open a habit to see its current setup and any pending tracking changes.</p>
    @endif
    {{ $this->habits->links() }}
    <flux:modal name="trash-habit" wire:model="showTrashModal"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'trash-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'trash-description')"
        x-on:close="$nextTick(() => document.querySelector('[data-trash-trigger=&quot;' + $wire.pendingTrashId + '&quot;]')?.focus())"
        class="w-[calc(100%-2rem)] min-w-0! space-y-5">
        <h2 id="trash-title" class="pr-6 text-lg font-semibold">Move habit to trash?</h2>
        <p id="trash-description" class="break-words text-sm text-zinc-300">“{{ $pendingTrashName }}” will leave your active and archived lists. Its configuration stays intact, and you can restore it from Trash.</p>
        <div class="flex flex-wrap justify-end gap-3">
            <flux:modal.close><flux:button autofocus class="min-h-11">Cancel</flux:button></flux:modal.close>
            <flux:button wire:click="trash" variant="danger" class="min-h-11">Confirm move to trash</flux:button>
        </div>
    </flux:modal>
</div>
