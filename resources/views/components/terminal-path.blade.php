@props(['path'])

<p wire:ignore wire:key="terminal-path-{{ $path }}" data-terminal-path="{{ $path }}" class="font-mono text-xs text-accent-content">
    <span class="sr-only">{{ $path }}</span>
    <span class="terminal-path-visual" aria-hidden="true"><span class="terminal-path-space">{{ $path }}<span class="terminal-path-cursor"></span></span><span class="terminal-path-overlay"><span data-terminal-text>{{ $path }}</span><span class="terminal-path-cursor"></span></span></span>
</p>
