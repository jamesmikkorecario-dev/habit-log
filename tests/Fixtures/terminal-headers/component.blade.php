<div>
    <header>{!! $eyebrow !!}{!! $heading !!}</header>
    <nav aria-label="Fixture pages" class="mt-6 flex gap-4">
        <a href="/__headers/dashboard" wire:navigate>Dashboard</a>
        <a href="/__headers/settings/profile" wire:navigate>Settings</a>
    </nav>
    <form wire:submit="save" class="mt-6 space-y-4">
        <label for="fixture-name">Fixture name</label>
        <input id="fixture-name" wire:model.live="name" class="border">
        @error('name')<p role="alert">{{ $message }}</p>@enderror
        <button type="submit">Validate</button>
        <button type="button" wire:click="increment">Update counter</button>
        <p data-counter>{{ $count }}</p>
    </form>
</div>
