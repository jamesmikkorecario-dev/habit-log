<dialog data-settings-leave aria-labelledby="settings-leave-title" aria-describedby="settings-leave-description"
    class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl border border-zinc-700 bg-zinc-900 p-6 text-zinc-100 shadow-xl backdrop:bg-black/70">
    <h2 id="settings-leave-title" class="text-xl font-semibold">Leave unsaved settings?</h2>
    <p id="settings-leave-description" class="mt-3 text-sm leading-relaxed text-zinc-300">You have unsaved changes. Leaving will discard changes that have not been submitted.</p>
    <div class="mt-6 flex flex-wrap justify-end gap-3">
        <flux:button type="button" data-settings-stay autofocus class="min-h-11">Stay and keep editing</flux:button>
        <flux:button type="button" data-settings-discard variant="primary" class="min-h-11">Discard changes and leave</flux:button>
    </div>
</dialog>
