<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Models\Category;
use App\Services\CategoryRecommender;
use App\Support\NormalizedName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Categories')] class extends Component
{
    use RequiresSameUser;

    public $name = '';
    public $description = '';
    #[Locked]
    public ?string $reviewedName = null;
    #[Locked]
    public bool $inline = false;

    public function mount(bool $inline = false): void
    {
        $this->authorize('viewAny', Category::class);
        $this->inline = $inline;
    }

    #[Computed]
    public function categories()
    {
        $this->authorize('viewAny', Category::class);
        return Category::availableTo(auth()->user())->orderByDesc('is_system')->orderBy('normalized_name')->orderBy('id')->get();
    }

    #[Computed]
    public function recommendations()
    {
        return $this->reviewedName === null ? collect() : app(CategoryRecommender::class)->recommend(auth()->user(), $this->reviewedName);
    }

    private function categoryData(): array
    {
        $this->authorize('create', Category::class);
        foreach (['name', 'description'] as $field) {
            if (is_string($this->$field)) {
                $this->$field = trim($this->$field);
            }
        }
        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $data['normalized_name'] = NormalizedName::from($data['name']);
        if ($data['normalized_name'] === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Include letters or numbers in the category name.']);
        }
        $data['description'] = $data['description'] === '' ? null : $data['description'];
        return $data;
    }

    public function review(): void
    {
        $data = $this->categoryData();
        $this->reviewedName = $data['name'];
        unset($this->recommendations);
    }

    public function save(): void
    {
        $data = $this->categoryData();
        if ($this->reviewedName !== $data['name']) {
            $this->review();
            return;
        }
        // Refresh matches on submission; the reviewed choice never grants access.
        unset($this->recommendations);
        $this->recommendations;
        if (auth()->user()->categories()->where('normalized_name', $data['normalized_name'])->exists()) {
            $this->addError('name', $this->inline ? 'You already have this category. Choose the matching suggestion or close this dialog and select it in your habit.' : 'You already have this category. Choose it from the list below.');
            return;
        }
        try {
            $category = auth()->user()->categories()->make($data + ['slug' => 'custom-'.Str::uuid()]);
            $category->forceFill(['is_system' => false])->save();
        } catch (UniqueConstraintViolationException $exception) {
            if (! auth()->user()->categories()->where('normalized_name', $data['normalized_name'])->exists()) {
                throw $exception;
            }
            $this->addError('name', $this->inline ? 'You already have this category. Choose the matching suggestion or close this dialog and select it in your habit.' : 'You already have this category. Choose it from the list below.');
            return;
        }
        if ($this->inline) {
            $this->dispatch('habit-category-selected', categoryId: $category->id);
            return;
        }
        session()->flash('status', 'Category created. You can now use it for a habit.');
        $this->redirectRoute('habits.create', ['category' => $category->id], navigate: true);
    }

    public function useCategory(int $id): void
    {
        $category = Category::availableTo(auth()->user())->findOrFail($id);
        $this->authorize('view', $category);
        if ($this->inline) {
            $this->dispatch('habit-category-selected', categoryId: $category->id);
            return;
        }
        $this->redirectRoute('habits.create', ['category' => $category->id], navigate: true);
    }
}; ?>

<div class="space-y-8">
    @unless($inline)
    <div>
        <h1 class="text-2xl font-semibold">Categories</h1>
        <p class="mt-2 text-sm text-zinc-400">Choose a category for your habit, or create one of your own.</p>
    </div>
    @endunless
    <form wire:submit="review" @class(['max-w-xl space-y-4', 'rounded-xl border border-emerald-900/50 p-4 sm:p-6' => !$inline])>
        @unless($inline)<h2 class="text-lg font-medium">Create a category</h2>@endunless
        <flux:input wire:model="name" label="Category name" maxlength="60" />
        <flux:textarea wire:model="description" label="Description (optional)" maxlength="500" rows="2" />
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" class="min-h-11">Check for similar categories</flux:button>
        @if($reviewedName !== null)
            <div class="space-y-3" role="region" aria-label="Category recommendations" aria-live="polite">
                <p class="text-sm text-zinc-300">Suggestions for “{{ $reviewedName }}”</p>
                @forelse($this->recommendations as $category)
                    <div wire:key="suggestion-{{ $category->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-zinc-900 p-3">
                        <span class="break-words">{{ $category->name }}</span>
                        <flux:button type="button" wire:click="useCategory({{ $category->id }})" aria-label="Use {{ $category->name }}" wire:loading.attr="disabled" class="min-h-11">Use this category</flux:button>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400">No matching categories found.</p>
                @endforelse
                <flux:button type="button" wire:click="save" wire:loading.attr="disabled" class="min-h-11">Create my category</flux:button>
            </div>
        @endif
    </form>
    @unless($inline)
    <section aria-labelledby="available-categories" class="space-y-3">
        <h2 id="available-categories" class="text-lg font-medium">Available categories</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            @forelse($this->categories as $category)
                <article wire:key="category-{{ $category->id }}" class="min-w-0 space-y-2 rounded-xl border border-emerald-900/50 p-4">
                    <h3 class="break-words font-medium">{{ $category->name }}</h3>
                    <p class="text-xs text-zinc-400">{{ $category->is_system ? 'System category' : 'Your category' }}</p>
                    @if($category->description !== null)<p class="break-words text-sm text-zinc-300">{{ $category->description }}</p>@endif
                    <flux:button wire:click="useCategory({{ $category->id }})" aria-label="Create habit in {{ $category->name }}">Create a habit</flux:button>
                </article>
            @empty
                <p class="text-sm text-zinc-400">No categories available yet. Create your first category above.</p>
            @endforelse
        </div>
    </section>
    @endunless
</div>
