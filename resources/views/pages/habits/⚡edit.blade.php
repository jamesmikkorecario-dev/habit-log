<?php

use App\Actions\Habits\SaveHabit;
use App\Livewire\Forms\HabitForm;
use App\Models\Category;
use App\Models\Habit;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Habit configuration')] class extends Component
{
    public HabitForm $form;
    #[Locked]
    public ?int $habitId = null;

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

<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-semibold">{{ $habitId === null ? 'Create a habit' : 'Edit habit' }}</h1>
    @if(session('status'))<p role="status" class="text-emerald-300">{{ session('status') }}</p>@endif
    @if($habitId)
        <x-tracking-setup :current="$this->trackingSetup['current']" :pending="$this->trackingSetup['pending']" />
        <p class="text-sm text-zinc-400">After the first daily entry, tracking changes take effect tomorrow in your timezone. Earlier entries and setup are preserved.</p>
        @if($this->trackingSetup['pending'])<flux:button wire:click="cancelTrackingChange" wire:confirm="Cancel the pending tracking change and return to the current setup?">Cancel pending change</flux:button>@endif
    @endif
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="form.name" label="Habit name" maxlength="100" />
        <flux:textarea wire:model="form.description" label="Description (optional)" maxlength="2000" rows="3" />
        <div class="space-y-2">
            <flux:select wire:model="form.category_id" label="Category">
                <flux:select.option value="">Choose a category</flux:select.option>
                @foreach($this->categories as $category)<flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>@endforeach
            </flux:select>
            <flux:link :href="route('categories.index')" wire:navigate>Create or explore categories</flux:link>
            <p class="text-xs text-zinc-400">Create a category before filling in your habit; leaving this form discards unsaved changes.</p>
        </div>
        <flux:select wire:model.live="form.type" label="Tracking type" description="Changing type clears incompatible goals, units, and checkpoints when you save.">
            @foreach(\App\Enums\HabitType::cases() as $type)<flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>@endforeach
        </flux:select>
        @if(in_array($form->type, ['count', 'duration', 'avoidance'], true))
            <flux:input wire:model="form.goal_value" type="number" step="1" :min="$form->type === 'avoidance' ? 0 : 1" max="999999999" :label="$form->type === 'avoidance' ? 'Maximum (optional)' : 'Goal (optional)'" :description="$form->type === 'duration' ? 'Whole minutes. Leave blank to track without a goal.' : 'Whole numbers. Leave blank to track without a goal.'" />
            @if($form->type !== 'duration')<flux:input wire:model="form.goal_unit" label="Unit (optional)" maxlength="30" placeholder="e.g. glasses, pages, times" />@endif
        @endif
        @if($form->type === 'checkpoint')
            <fieldset class="space-y-3" x-data x-on:checkpoint-moved.window="$nextTick(() => document.getElementById('checkpoint-label-' + $event.detail.index)?.focus())">
                <legend class="font-medium">Checkpoints</legend>
                <p class="text-sm text-zinc-400">Add 1–20 distinct labels in the order you want to follow.</p>
                <flux:error name="form.checkpoints" />
                @foreach(is_array($form->checkpoints) ? $form->checkpoints : [] as $index => $checkpoint)
                    <div wire:key="checkpoint-{{ $checkpoint['key'] ?? 'new' }}-{{ $index }}" class="space-y-2 rounded-lg border border-emerald-900/50 p-3">
                        <flux:input :id="'checkpoint-label-'.$index" wire:model="form.checkpoints.{{ $index }}.label" :label="'Checkpoint '.($index + 1)" maxlength="80" />
                        <div class="flex flex-wrap gap-2">
                            <flux:button type="button" wire:click="moveCheckpoint({{ $index }}, -1)" :disabled="$index === 0" :aria-label="'Move checkpoint '.($index + 1).' up'">Up</flux:button>
                            <flux:button type="button" wire:click="moveCheckpoint({{ $index }}, 1)" :disabled="$index === count($form->checkpoints) - 1" :aria-label="'Move checkpoint '.($index + 1).' down'">Down</flux:button>
                            <flux:button type="button" wire:click="removeCheckpoint({{ $index }})" :aria-label="'Remove checkpoint '.($index + 1)">Remove</flux:button>
                        </div>
                    </div>
                @endforeach
                <flux:button type="button" wire:click="addCheckpoint" :disabled="is_array($form->checkpoints) && count($form->checkpoints) >= 20">Add checkpoint</flux:button>
            </fieldset>
        @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.start_date" type="date" label="Start date" min="1000-01-01" max="9999-12-31" />
            <flux:input wire:model="form.end_date" type="date" label="End date (optional)" min="1000-01-01" max="9999-12-31" />
        </div>
        <flux:select wire:model="form.visibility" label="Visibility preference" description="Sharing is not available yet. Only you can access your habits today. New habits always default to private.">
            @foreach(\App\Enums\HabitVisibility::cases() as $visibility)<flux:select.option :value="$visibility->value">{{ $visibility->label() }}</flux:select.option>@endforeach
        </flux:select>
        <div class="flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary">Save habit</flux:button>
            <flux:button :href="$habitId === null ? route('habits.index') : route('habits.show', $habitId)" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
