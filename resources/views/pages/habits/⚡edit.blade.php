<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Actions\Habits\SaveHabit;
use App\Livewire\Forms\HabitForm;
use App\Models\Category;
use App\Models\Habit;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Habit configuration')] class extends Component
{
    use RequiresSameUser;

    public HabitForm $form;
    #[Locked]
    public ?int $habitId = null;
    public bool $showCategoryModal = false;
    public string $categoryStatus = '';

    #[On('habit-category-selected')]
    public function selectCategory(int $categoryId): void
    {
        $category = Category::availableTo(auth()->user())->findOrFail($categoryId);
        $this->authorize('view', $category);
        $this->form->category_id = $category->id;
        $this->showCategoryModal = false;
        $this->categoryStatus = 'Category selected: '.$category->name.'. Save your habit to apply it.';
        $this->resetValidation('form.category_id');
        unset($this->categories);
        $this->dispatch('habit-category-applied');
    }

    public function mount(?int $habit = null): void
    {
        if ($habit !== null) {
            $record = auth()->user()->habits()->with('checkpoints')->findOrFail($habit);
            $this->authorize('update', $record);
            $this->habitId = $record->id;
            $this->form->fillHabit($record);
        } else {
            $this->authorize('create', Habit::class);
            $this->form->start_date = auth()->user()->localToday();
            if (request()->filled('category')) {
                $category = Category::availableTo(auth()->user())->findOrFail(request()->query('category'));
                $this->authorize('view', $category);
                $this->form->category_id = $category->id;
            }
        }
    }

    #[Computed]
    public function categories()
    {
        $this->authorize('viewAny', Category::class);
        return Category::availableTo(auth()->user())->orderBy('normalized_name')->orderBy('id')->get();
    }

    public function addCheckpoint(): void
    {
        if (is_array($this->form->checkpoints) && count($this->form->checkpoints) < 20) {
            $this->form->checkpoints[] = ['id' => null, 'label' => '', 'key' => (string) Str::uuid()];
        }
    }

    public function removeCheckpoint(int $index): void
    {
        if (is_array($this->form->checkpoints) && array_key_exists($index, $this->form->checkpoints)) {
            array_splice($this->form->checkpoints, $index, 1);
            $this->resetValidation('form.checkpoints');
        }
    }

    public function moveCheckpoint(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (in_array($direction, [-1, 1], true) && is_array($this->form->checkpoints) && isset($this->form->checkpoints[$index], $this->form->checkpoints[$target])) {
            [$this->form->checkpoints[$index], $this->form->checkpoints[$target]] = [$this->form->checkpoints[$target], $this->form->checkpoints[$index]];
            $this->resetValidation();
            $this->dispatch('checkpoint-moved', index: $target);
        }
    }

    #[Computed]
    public function trackingSetup(): array
    {
        if ($this->habitId === null) { return ['current' => null, 'pending' => null]; }
        $habit = auth()->user()->habits()->findOrFail($this->habitId);
        $this->authorize('view', $habit);
        $today = auth()->user()->fresh()->localToday();
        return [
            'current' => $habit->versions()->with('checkpoints')->where('effective_date', '<=', $today)->latest('effective_date')->first(),
            'pending' => $habit->entries()->exists() ? $habit->versions()->where('effective_date', '>', $today)->latest('effective_date')->first() : null,
        ];
    }

    public function cancelTrackingChange(): void
    {
        abort_if($this->habitId === null, 404);
        app(\App\Actions\Habits\CancelTrackingChange::class)->handle(auth()->user(), $this->habitId);
        $this->form->fillHabit(auth()->user()->habits()->with('checkpoints')->findOrFail($this->habitId));
        unset($this->trackingSetup);
    }

    public function save(): void
    {
        $record = $this->habitId === null ? Habit::class : auth()->user()->habits()->findOrFail($this->habitId);
        $this->authorize($this->habitId === null ? 'create' : 'update', $record);
        $habit = app(SaveHabit::class)->handle(auth()->user(), $this->habitId, $this->form->validatedData($this->habitId));
        session()->flash('status', $this->habitId === null ? 'Habit created.' : 'Habit saved.');
        $this->redirectRoute('habits.show', ['habit' => $habit->id], navigate: true);
    }
}; ?>

<div class="min-w-0 space-y-6 sm:space-y-8" data-habit-configuration
    x-data="{
        dirty: false,
        navObserver: null,
        stopSaveRedirect: null,
        init() {
            const nav = document.querySelector('body > div > nav');
            this.navObserver = new ResizeObserver(() => this.$el.style.setProperty('--habit-nav-height', nav.getBoundingClientRect().height + 'px'));
            this.navObserver.observe(nav);
            this.stopSaveRedirect = this.$wire.$interceptRequest('save', ({ onRedirect }) => {
                onRedirect(() => { this.dirty = false; });
            });
        },
        destroy() { this.navObserver?.disconnect(); this.stopSaveRedirect?.(); },
        keepVisible(target) {
            if (target.closest('dialog')) return;
            const field = target.closest('[data-flux-field]') ?? target;
            const top = field.getBoundingClientRect().top;
            const offset = document.querySelector('body > div > nav').getBoundingClientRect().bottom + 16;
            if (top < offset) window.scrollBy({ top: top - offset, behavior: 'instant' });
        }
    }"
    x-on:focusin="$nextTick(() => keepVisible($event.target))"
    x-on:input="if ($event.target.closest('[data-habit-fields]')) dirty = true"
    x-on:change="if ($event.target.closest('[data-habit-fields]')) dirty = true"
    x-on:click="if ($event.target.closest('[data-habit-draft-change]')) dirty = true"
    x-on:habit-category-applied.window="dirty = true"
    x-on:livewire:navigate.window="if (!$event.detail.history && dirty && !confirm('Leave this page and discard unsaved habit changes?')) $event.preventDefault()"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">
    <header>
        <x-terminal-path :path="$habitId === null ? '~/habits/create' : '~/habits/edit'" />
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">{{ $habitId === null ? 'Create a habit' : 'Edit habit' }}</h1>
        <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ $habitId === null ? 'Start with one small action. Make it your own.' : 'Shape your routine as your needs change.' }}</p>
    </header>
    @if(session('status'))<p role="status" class="text-emerald-300">{{ session('status') }}</p>@endif
    @if($habitId)
        <div class="space-y-3">
            <x-tracking-setup :current="$this->trackingSetup['current']" :pending="$this->trackingSetup['pending']" />
            <p class="text-sm text-zinc-400">After the first daily entry, tracking changes take effect tomorrow in your timezone. Earlier entries and setup are preserved.</p>
            @if($this->trackingSetup['pending'])<flux:button wire:click="cancelTrackingChange" wire:confirm="Cancel the pending tracking change and return to the current setup?" class="min-h-11">Cancel pending change</flux:button>@endif
        </div>
    @endif
    <form wire:submit="save" class="space-y-5" data-habit-fields>
        @if($errors->isNotEmpty())
            <p role="alert" tabindex="-1" x-init="$nextTick(() => $el.focus())" class="rounded-lg border border-red-900/60 p-4 text-sm text-red-300 focus-visible:outline-2 focus-visible:outline-red-300">Please review the highlighted fields. Your habit has not been saved.</p>
        @endif
        <div class="grid min-w-0 items-start gap-6 lg:grid-cols-3" data-habit-sections>
            <div class="min-w-0 space-y-5 lg:col-span-2">
                <section aria-labelledby="habit-basics" class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
                    <div>
                        <h2 id="habit-basics" class="text-lg font-semibold text-zinc-100">The basics</h2>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-400">Give your habit a clear name and a place in your routine.</p>
                    </div>
                    <flux:input wire:model="form.name" label="Habit name" maxlength="100" aria-required="true" />
                    <flux:textarea wire:model="form.description" label="Description (optional)" maxlength="2000" rows="3" />
                    <div class="space-y-2">
                        <flux:select wire:model="form.category_id" label="Category" aria-required="true">
                            <flux:select.option value="">Choose a category</flux:select.option>
                            @foreach($this->categories as $category)<flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:button type="button" id="habit-category-trigger" wire:click="$set('showCategoryModal', true)" variant="ghost" class="min-h-11">Create a category</flux:button>
                        <p class="text-xs text-zinc-400">Add a category here without leaving your habit draft.</p>
                        <p role="status" class="empty:hidden text-sm text-emerald-300">{{ $categoryStatus }}</p>
                    </div>
                </section>
                <section aria-labelledby="habit-tracking" class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
                    <div>
                        <h2 id="habit-tracking" class="text-lg font-semibold text-zinc-100">How you track</h2>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-400">Choose the approach that fits this habit.</p>
                    </div>
                    <flux:select wire:model.live="form.type" label="Tracking type" :description="$habitId === null ? 'Choose how you will record this habit each day.' : 'Changing type clears incompatible goals, units, and checkpoints when you save. Earlier daily entries keep their original setup.'">
                        @foreach(\App\Enums\HabitType::cases() as $type)<flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>@endforeach
                    </flux:select>
                    <p class="text-sm leading-relaxed text-zinc-300">
                        @switch($form->type)
                            @case('boolean') Mark whether you completed this habit for the day. @break
                            @case('count') Record a whole-number amount, with an optional daily goal. @break
                            @case('duration') Track how many minutes you spend on this habit. @break
                            @case('checkpoint') Follow your own checklist and record each step. @break
                            @case('avoidance') Record occurrences of a behavior you want to reduce or avoid. @break
                        @endswitch
                    </p>
                    @if(in_array($form->type, ['count', 'duration', 'avoidance'], true))
                        <div class="max-w-sm space-y-5">
                            <flux:input wire:model="form.goal_value" type="number" step="1" :min="$form->type === 'avoidance' ? 0 : 1" max="999999999" :label="$form->type === 'avoidance' ? 'Maximum (optional)' : 'Goal (optional)'" :description="match ($form->type) { 'duration' => 'Whole minutes, e.g. 30 minutes of reading per day. Leave blank to track without a target.', 'avoidance' => 'Maximum occurrences allowed per day. Enter 0 for complete avoidance, or leave blank for no maximum.', default => 'A whole-number daily target, such as 8 glasses of water. Leave blank to track without a target.' }" />
                            @if($form->type !== 'duration')<flux:input wire:model="form.goal_unit" label="Unit (optional)" maxlength="30" :placeholder="$form->type === 'avoidance' ? 'e.g. times, occurrences' : 'e.g. glasses, pages'" />@endif
                        </div>
                    @endif
                    @if($form->type === 'checkpoint')
                        <fieldset class="space-y-3" x-data x-on:checkpoint-moved.window="$nextTick(() => document.getElementById('checkpoint-label-' + $event.detail.index)?.focus())">
                            <legend class="font-medium">Checkpoints</legend>
                            <p class="text-sm text-zinc-400">Add 1–20 distinct labels in the order you want to follow.</p>
                            <flux:error name="form.checkpoints" />
                            @foreach(is_array($form->checkpoints) ? $form->checkpoints : [] as $index => $checkpoint)
                                <div wire:key="checkpoint-{{ $checkpoint['key'] ?? 'new' }}-{{ $index }}" class="space-y-2 rounded-lg border border-zinc-700 bg-zinc-950/30 p-4">
                                    <flux:input :id="'checkpoint-label-'.$index" wire:model="form.checkpoints.{{ $index }}.label" :label="'Checkpoint '.($index + 1)" maxlength="80" />
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button type="button" data-habit-draft-change class="min-h-11" wire:click="moveCheckpoint({{ $index }}, -1)" :disabled="$index === 0" :aria-label="'Move checkpoint '.($index + 1).' up'">Up</flux:button>
                                        <flux:button type="button" data-habit-draft-change class="min-h-11" wire:click="moveCheckpoint({{ $index }}, 1)" :disabled="$index === count($form->checkpoints) - 1" :aria-label="'Move checkpoint '.($index + 1).' down'">Down</flux:button>
                                        <flux:button type="button" data-habit-draft-change class="min-h-11" wire:click="removeCheckpoint({{ $index }})" :aria-label="'Remove checkpoint '.($index + 1)">Remove</flux:button>
                                    </div>
                                </div>
                            @endforeach
                            <flux:button type="button" data-habit-draft-change class="min-h-11" wire:click="addCheckpoint" :disabled="is_array($form->checkpoints) && count($form->checkpoints) >= 20">Add checkpoint</flux:button>
                        </fieldset>
                    @endif
                </section>
            </div>
            <section aria-labelledby="habit-dates-privacy" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6">
                <div>
                    <h2 id="habit-dates-privacy" class="text-lg font-semibold text-zinc-100">Dates and privacy</h2>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-400">Set your date range and choose who can see this habit.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <flux:input wire:model="form.start_date" type="date" label="Start date" min="1000-01-01" max="9999-12-31" aria-required="true" />
                    <flux:input wire:model="form.end_date" type="date" label="End date (optional)" min="1000-01-01" max="9999-12-31" />
                </div>
                <flux:select wire:model="form.visibility" label="Visibility preference" description="Choose who can see this habit and its activity. New habits start private.">
                    @foreach(\App\Enums\HabitVisibility::cases() as $visibility)<flux:select.option :value="$visibility->value">{{ $visibility->label() }}</flux:select.option>@endforeach
                </flux:select>
                <details class="rounded-lg border border-zinc-800 p-4 text-sm">
                    <summary class="cursor-pointer rounded py-2 font-medium text-zinc-200 focus-visible:outline-2 focus-visible:outline-emerald-300">Learn about visibility</summary>
                    <ul class="mt-3 list-disc space-y-2 pl-5 leading-relaxed text-zinc-400">
                        <li><strong class="text-zinc-200">Private:</strong> only you can see this habit and its activity.</li>
                        <li><strong class="text-zinc-200">Followers:</strong> only you and your accepted followers can see them.</li>
                        <li><strong class="text-zinc-200">Public:</strong> signed-in users can see them. If your account is private, they must be approved followers first.</li>
                        <li>Private accounts require approval for both Followers and Public habits. Sharing includes historical activity.</li>
                        <li>Habit notes, N/A reasons, journals, and mood stay private regardless of this setting.</li>
                    </ul>
                </details>
            </section>
        </div>
        <div class="flex flex-wrap gap-3 border-t border-zinc-800 pt-5" data-habit-actions>
            <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Save habit</span><span wire:loading wire:target="save">Saving…</span>
            </flux:button>
            <flux:button :href="$habitId === null ? route('habits.index') : route('habits.show', $habitId)" variant="ghost" class="min-h-11" wire:navigate>Cancel</flux:button>
        </div>
    </form>
    <flux:modal name="habit-category" wire:model="showCategoryModal" class="w-[calc(100%-2rem)] max-w-xl min-w-0! space-y-5"
        x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'habit-category-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'habit-category-description')"
        x-on:close="$nextTick(() => document.getElementById('habit-category-trigger')?.focus())">
        <h2 id="habit-category-title" class="pr-6 text-lg font-semibold">Create a category</h2>
        <p id="habit-category-description" class="text-sm text-zinc-400">Review similar categories first. Your habit draft stays in place. Creating a category saves it separately from your habit.</p>
        @if($showCategoryModal)
            <livewire:pages::categories.index :inline="true" />
        @endif
        <flux:modal.close><flux:button class="min-h-11">Cancel category creation</flux:button></flux:modal.close>
    </flux:modal>
</div>
