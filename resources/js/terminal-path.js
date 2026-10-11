// Only page navigation starts an animation. Livewire morphs leave the
// wire:ignore label alone; form updates never initialize it again.
const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
const active = new Map();

function finish(element, state) {
    clearTimeout(state.timer);
    state.text.textContent = state.path;
    element.dataset.terminalState = 'complete';
}

function startPaths() {
    document.querySelectorAll('[data-terminal-path]').forEach((element) => {
        if (active.has(element)) return;

        const state = {
            path: element.dataset.terminalPath,
            text: element.querySelector('[data-terminal-text]'),
            timer: null,
        };
        active.set(element, state);

        if (motion.matches) {
            finish(element, state);
            return;
        }

        const characters = Array.from(state.path);
        let count = 0;
        state.text.textContent = '';
        element.dataset.terminalState = 'typing';

        function type() {
            if (!element.isConnected || motion.matches) {
                finish(element, state);
                return;
            }

            state.text.textContent = characters.slice(0, ++count).join('');
            if (count < characters.length) {
                state.timer = setTimeout(type, 50);
            } else {
                element.dataset.terminalState = 'blinking';
                state.timer = setTimeout(() => finish(element, state), 1800);
            }
        }

        state.timer = setTimeout(type, 50);
    });
}

function leavePage() {
    // Finish before Livewire caches the outgoing DOM. A restored page gets
    // one fresh animation, even when Back/Forward reuses cached markup.
    active.forEach((state, element) => finish(element, state));
    active.clear();
}

document.addEventListener('livewire:navigating', leavePage);
document.addEventListener('livewire:navigated', startPaths);
window.addEventListener('pagehide', leavePage);
window.addEventListener('pageshow', startPaths);
motion.addEventListener('change', () => {
    if (motion.matches) active.forEach((state, element) => finish(element, state));
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPaths, { once: true });
} else {
    startPaths();
}
