// Track history positions so cancelling Back/Forward can restore the original
// entry before Livewire consumes popstate. No journal content enters history.
const positionKey = 'habitLogPosition';
let position = Number.isInteger(history.state?.[positionKey]) ? history.state[positionKey] : 0;
let restoring = false;
const pushState = history.pushState.bind(history);
const replaceState = history.replaceState.bind(history);

replaceState({ ...history.state, [positionKey]: position }, '');
history.pushState = (state, title, url) => {
    const next = position + 1;
    pushState({ ...state, [positionKey]: next }, title, url);
    position = next;
};
history.replaceState = (state, title, url) => {
    replaceState({ ...state, [positionKey]: position }, title, url);
};

window.addEventListener('popstate', (event) => {
    if (restoring) {
        event.stopImmediatePropagation();
        restoring = false;
        return;
    }

    const destination = event.state?.[positionKey];
    const editor = document.querySelector('[data-journal-editor]');
    const dirty = editor && window.Alpine?.$data(editor).dirty;

    if (dirty && Number.isInteger(destination) && destination !== position) {
        if (!confirm('Leave this page and discard unsaved journal changes?')) {
            event.stopImmediatePropagation();
            restoring = true;
            history.go(position - destination);
            return;
        }
    }

    if (Number.isInteger(destination)) position = destination;
}, { capture: true });
