<?php

use App\Livewire\Concerns\RequiresSameUser;
use App\Actions\Categories\CreateCategory;
use App\Models\Category;
use App\Services\CategoryRecommender;
use App\Support\NormalizedName;
use Illuminate\Database\UniqueConstraintViolationException;
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

    #[Computed]
    public function categoryReview(): array
    {
        $normalized = NormalizedName::from($this->reviewedName ?? '');
        // Inspect all available exact names: the three-suggestion limit must not
        // hide an owner duplicate when several system categories share a name.
        $exact = $this->reviewedName === null ? collect() : $this->categories->whereStrict('normalized_name', $normalized);

        return [
            'exact' => $exact,
            'personal' => $exact->firstWhere('is_system', false),
            'similar' => $this->recommendations->reject(fn (Category $category) => $category->normalized_name === $normalized),
        ];
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
            $category = app(CreateCategory::class)->handle(auth()->user(), $data);
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

<div class="min-w-0 space-y-6 sm:space-y-8" x-data>
    @unless($inline)
        <header>
            <x-terminal-path path="~/categories" />
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-100 sm:text-4xl">Categories</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">Give your habits a place to belong.</p>
        </header>
    @endunless
    <div @class(['grid min-w-0 items-start gap-6 lg:grid-cols-3' => !$inline])>
        <form wire:submit="review" aria-label="Create a category" @class(['min-w-0 w-full max-w-xl space-y-5', 'rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6' => !$inline])>
            <div>
                <p class="font-mono text-xs" :class="$wire.reviewedName !== null && $wire.name === $wire.reviewedName ? 'text-zinc-400' : 'text-emerald-300'" :aria-current="$wire.reviewedName !== null && $wire.name === $wire.reviewedName ? null : 'step'">01 / Describe</p>
                @unless($inline)<h2 class="mt-2 text-lg font-semibold text-zinc-100">Create a category</h2>@endunless
                <p class="mt-2 text-sm leading-relaxed text-zinc-400">Start with a name, then check whether an existing category fits.</p>
            </div>
            <flux:input id="category-name" wire:model="name" label="Category name" maxlength="60" aria-required="true" class="scroll-mt-24" />
            <flux:textarea wire:model="description" label="Description (optional)" maxlength="500" rows="3" />
            <flux:button type="submit" wire:loading.attr="disabled" class="min-h-11 w-full sm:w-auto lg:w-full">
                <span wire:loading.remove wire:target="review">Check for similar categories</span><span wire:loading wire:target="review">Checking…</span>
            </flux:button>
            <div role="region" aria-label="Category recommendations" aria-live="polite" aria-atomic="true">
                @if($reviewedName !== null)
                    <div class="border-t border-zinc-800 pt-5">
                        <p x-show="$wire.name !== $wire.reviewedName" x-cloak class="text-sm leading-relaxed text-amber-300">Name changed. Check for similar categories again before creating.</p>
                        <div x-show="$wire.name === $wire.reviewedName" class="space-y-4">
                            <div>
                                <p class="font-mono text-xs text-emerald-300" :aria-current="$wire.name === $wire.reviewedName ? 'step' : null">02 / Review and choose</p>
                                @if($this->categoryReview['exact']->isNotEmpty())
                                    <h3 class="mt-2 font-medium text-zinc-100">This category already exists.</h3>
                                    <p class="mt-2 break-words text-sm leading-relaxed text-zinc-400">{{ $this->categoryReview['personal'] ? 'You already have a category with this name.' : 'A system category already has this name.' }} Name matching ignores capitalization and treats punctuation as spaces. Use an existing category below.</p>
                                @elseif($this->categoryReview['similar']->isNotEmpty())
                                    <h3 class="mt-2 font-medium text-zinc-100">We found similar categories.</h3>
                                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">These are suggestions, not duplicates. Choose one that fits, or create your own.</p>
                                @else
                                    <h3 class="mt-2 font-medium text-zinc-100">No similar categories found.</h3>
                                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">You're ready to create your own category.</p>
                                @endif
                                <p class="mt-2 text-sm text-zinc-400">Nothing has been created yet.</p>
                            </div>
                            @if($this->recommendations->isNotEmpty() || $this->categoryReview['exact']->isNotEmpty())
                                <p class="text-sm leading-relaxed text-zinc-300">{{ $inline ? 'Selecting a category returns to your unfinished habit with it selected. Your habit is not saved.' : 'Using a category opens Create Habit with it selected. No new category is created.' }}</p>
                            @endif
                            @foreach($this->categoryReview['exact']->concat($this->categoryReview['similar']) as $category)
                                <div wire:key="suggestion-{{ $category->id }}" @class(['min-w-0 space-y-2 rounded-lg border bg-zinc-950/40 p-3', 'border-emerald-800' => $this->categoryReview['exact']->contains('id', $category->id), 'border-zinc-700' => !$this->categoryReview['exact']->contains('id', $category->id)])>
                                    <p class="break-words text-sm font-medium text-zinc-100">{{ $category->name }}</p>
                                    <p class="text-xs text-zinc-400">{{ $category->is_system ? 'System category' : 'Your category' }} · {{ $this->categoryReview['exact']->contains('id', $category->id) ? 'Exact name match' : 'Similar suggestion' }}</p>
                                    @if($category->description !== null)<p class="break-words text-sm leading-relaxed text-zinc-400">{{ \Illuminate\Support\Str::limit($category->description, 160) }}</p>@endif
                                    <flux:button type="button" wire:click="useCategory({{ $category->id }})" aria-label="{{ $inline ? 'Select category' : 'Use existing category' }}: {{ $category->name }}" variant="{{ $loop->first ? 'primary' : 'outline' }}" wire:loading.attr="disabled" class="min-h-11">
                                        {{ $inline ? 'Select category' : 'Use existing category' }}
                                    </flux:button>
                                </div>
                            @endforeach
                            @unless($this->categoryReview['personal'])
                                <div class="space-y-3">
                                    @if($this->categoryReview['exact']->isNotEmpty())
                                        <p class="text-sm leading-relaxed text-zinc-400">Need a separate personal category? You can create your own with this system category's name. It will belong to you and leave the system category unchanged.</p>
                                    @endif
                                    <p class="break-words text-sm leading-relaxed text-zinc-400">Create “{{ $reviewedName }}” as your personal category. {{ $inline ? 'It is saved separately, then selected in your unfinished habit.' : 'Then continue to Create Habit with it selected.' }}</p>
                                    <flux:button type="button" wire:click="save" variant="{{ $this->recommendations->isEmpty() && $this->categoryReview['exact']->isEmpty() ? 'primary' : 'outline' }}" wire:loading.attr="disabled" class="min-h-11">
                                        <span wire:loading.remove wire:target="save">{{ $this->recommendations->isEmpty() && $this->categoryReview['exact']->isEmpty() ? 'Create category' : 'Create a new category' }}</span><span wire:loading wire:target="save">Creating…</span>
                                    </flux:button>
                                </div>
                            @endunless
                        </div>
                    </div>
                @endif
            </div>
        </form>
        @unless($inline)
            <section aria-labelledby="available-categories" class="min-w-0 space-y-6 lg:col-span-2">
                <div>
                    <h2 id="available-categories" class="text-xl font-semibold text-zinc-100">Available categories</h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-400">Use an existing category to start a habit, or make a category of your own.</p>
                </div>
                @if($this->categories->isEmpty())
                    <div class="space-y-3 rounded-xl border border-dashed border-zinc-700 bg-zinc-900/30 p-6">
                        <h3 class="font-semibold text-zinc-100">No categories available yet.</h3>
                        <p class="text-sm leading-relaxed text-zinc-400">Create a personal category to give your first habit a home.</p>
                        <flux:button href="#category-name" variant="ghost" class="min-h-11">Create a category</flux:button>
                    </div>
                @else
                    @foreach([1 => 'System categories', 0 => 'Your categories'] as $system => $groupLabel)
                        @php($group = $this->categories->where('is_system', (bool) $system))
                        @if($group->isNotEmpty() || !$system)
                            <section aria-labelledby="category-group-{{ $system }}" class="min-w-0 space-y-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 id="category-group-{{ $system }}" class="font-medium text-zinc-200">{{ $groupLabel }}</h3>
                                    <span class="rounded-md border border-zinc-800 px-2 py-0.5 font-mono text-xs text-zinc-400" aria-label="{{ $group->count() }} {{ strtolower($groupLabel) }}">{{ $group->count() }}</span>
                                </div>
                                @if($group->isEmpty())
                                    <div class="space-y-3 rounded-xl border border-dashed border-zinc-700 bg-zinc-900/30 p-5">
                                        <h4 class="font-medium text-zinc-100">Make your own categories.</h4>
                                        <p class="text-sm leading-relaxed text-zinc-400">Organize your habits in a way that makes sense to you. System categories are ready to use, too.</p>
                                        <flux:button href="#category-name" variant="ghost" class="min-h-11">Create a category</flux:button>
                                    </div>
                                @else
                                    <div class="grid min-w-0 items-start gap-3 sm:grid-cols-2">
                                        @foreach($group as $category)
                                            <article wire:key="category-{{ $category->id }}" data-category-card class="min-w-0 space-y-2 rounded-xl border border-zinc-800 bg-zinc-900/30 p-4 focus-within:border-emerald-800 hover:border-zinc-600">
                                                <p @class(['text-xs', 'text-zinc-400' => $category->is_system, 'text-emerald-300' => !$category->is_system])>{{ $category->is_system ? 'System category' : 'Your category' }}</p>
                                                <h4 class="break-words font-semibold text-zinc-100">{{ $category->name }}</h4>
                                                @if($category->description !== null)<p class="break-words text-sm leading-relaxed text-zinc-400">{{ $category->description }}</p>@endif
                                                <flux:button wire:click="useCategory({{ $category->id }})" aria-label="Create habit in {{ $category->name }}" variant="ghost" icon:trailing="arrow-right" class="min-h-11" wire:loading.attr="disabled">Create a habit</flux:button>
                                            </article>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        @endif
                    @endforeach
                @endif
            </section>
        @endunless
    </div>
</div>
