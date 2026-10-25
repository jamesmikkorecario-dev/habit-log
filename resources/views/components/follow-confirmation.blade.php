<flux:modal name="follow-removal" wire:model="showFollowModal" wire:close="cancelFollowRemoval"
    x-on:close="$nextTick(() => (followOrigin?.isConnected ? followOrigin : document.getElementById('people-heading'))?.focus())"
    x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'follow-removal-title'); $el.querySelector('dialog').setAttribute('aria-describedby', 'follow-removal-description')"
    class="w-[calc(100%-2rem)] min-w-0! space-y-5 [&_[data-flux-modal-close]_button]:min-h-11 [&_[data-flux-modal-close]_button]:min-w-11">
    <h2 id="follow-removal-title" class="break-words pr-6 text-lg font-semibold">{{ $this->confirmedOperation === 'remove' ? 'Remove follower' : 'Unfollow' }} {{ $this->confirmationName }}?</h2>
    <p id="follow-removal-description">This ends this follow relationship. Following again may require a new approval. Public activity on a public account stays visible to signed-in users.</p>
    <div class="flex flex-wrap justify-end gap-3">
        <flux:button wire:click="cancelFollowRemoval" class="min-h-11" autofocus>Cancel</flux:button>
        <flux:button wire:click="removeFollow" variant="danger" wire:loading.attr="disabled" class="min-h-11">Confirm</flux:button>
    </div>
</flux:modal>
