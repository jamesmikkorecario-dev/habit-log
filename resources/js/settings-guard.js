// Settings drafts live only in the current document. Never persist credentials.
const profileFields = ['username', 'display_name', 'bio', 'is_private', 'timezone', 'theme_key'];
const baselines = new WeakMap();
const boundDialogs = new WeakSet();
let leaving = false;
let pending = null;
let approvedSubmit = null;
const forms = () => [...document.querySelectorAll('form[data-settings-form]')];
const controls = form => [...form.querySelectorAll('input, textarea, select')]
    .filter(el => el.name && !['hidden', 'submit', 'button'].includes(el.type));
const wire = form => window.Livewire?.find(form.closest('[wire\\:id]')?.getAttribute('wire:id'));
const values = form => form.dataset.settingsForm === 'profile'
    ? Object.fromEntries(profileFields.map(key => [key, wire(form)?.[key]]))
    : Object.fromEntries(controls(form).map(el => [el.name, el.type === 'checkbox' ? el.checked : el.value]));
function baseline(form) {
    if (!baselines.has(form)) {
        baselines.set(form, form.dataset.settingsForm === 'profile'
            ? JSON.parse(form.dataset.settingsBaseline)
            : Object.fromEntries(controls(form).map(el => [el.name, el.type === 'checkbox' ? false : ''])));
    }
    return baselines.get(form);
}
function dirty(form) {
    const saved = baseline(form);
    return Object.entries(values(form)).some(([key, value]) => value !== saved[key]);
}
export function settingsDirty(except = null) {
    return !leaving && forms().some(form => form !== except && dirty(form));
}
function clearSecrets() {
    forms().forEach(form => form.querySelectorAll('input[autocomplete="current-password"], input[autocomplete="new-password"]').forEach(input => {
        input.value = '';
        input.removeAttribute('value');
    }));
}
export function permitSettingsLeave() { leaving = true; }
export function confirmSettingsLeave(proceed) {
    if (pending) return;
    const dialog = document.querySelector('[data-settings-leave]');
    const focus = document.activeElement;
    pending = { proceed, focus };
    dialog.showModal();
    dialog.querySelector('[data-settings-stay]').focus();
}
function cancelLeave() {
    const focus = pending?.focus;
    pending = null;
    document.querySelector('[data-settings-leave]')?.close();
    if (focus?.isConnected) focus.focus({ preventScroll: true });
}
function startPage() {
    leaving = false;
    pending = null;
    approvedSubmit = null;
    forms().forEach(baseline);
    const dialog = document.querySelector('[data-settings-leave]');
    if (!dialog || boundDialogs.has(dialog)) return;
    boundDialogs.add(dialog);
    dialog.addEventListener('cancel', event => { event.preventDefault(); cancelLeave(); });
    dialog.querySelector('[data-settings-stay]').addEventListener('click', cancelLeave);
    dialog.querySelector('[data-settings-discard]').addEventListener('click', () => {
        const proceed = pending?.proceed;
        pending = null;
        dialog.close();
        proceed?.();
    });
}
document.addEventListener('DOMContentLoaded', startPage);
document.addEventListener('livewire:navigated', startPage);
document.addEventListener('profile-updated', event => {
    const form = event.target.closest('[wire\\:id]')?.querySelector('[data-settings-form="profile"]');
    if (form && event.detail.values) baselines.set(form, event.detail.values);
});
document.addEventListener('livewire:navigate', event => {
    // Re-authorize Settings history entries and load saved values, never a
    // cached component snapshot containing a previously discarded draft.
    if (event.detail.history && new URL(event.detail.url).pathname.startsWith('/settings/')) {
        event.preventDefault();
        permitSettingsLeave();
        location.replace(event.detail.url.href);
        return;
    }
    if (event.detail.history || !settingsDirty()) return;
    event.preventDefault();
    confirmSettingsLeave(() => {
        permitSettingsLeave();
        window.Livewire.navigate(event.detail.url.href);
        // Approval covers this attempt. Until the page actually swaps, a
        // failed destination fetch must not disable protection for new edits.
        leaving = false;
    });
});
document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
        || link.hasAttribute('download') || (link.target && link.target !== '_self') || link.hasAttribute('wire:navigate') || !settingsDirty()) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) return; // Native beforeunload covers external links.
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    confirmSettingsLeave(() => { permitSettingsLeave(); location.assign(url.href); });
}, true);
document.addEventListener('submit', event => {
    const form = event.target;
    if (!forms().length || form.hasAttribute('wire:submit')) return;
    const forced = form.hasAttribute('data-settings-force-leave');
    if (!forced && approvedSubmit !== form && settingsDirty(form)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        confirmSettingsLeave(() => {
            approvedSubmit = form;
            form.requestSubmit(submitter);
        });
        return;
    }
    approvedSubmit = null;
    // Do not clear inputs here: the browser has not constructed the POST body yet.
    queueMicrotask(() => {
        if (event.defaultPrevented) return;
        permitSettingsLeave();
        forms().forEach(form => { form.inert = true; });
    });
}, true);
window.addEventListener('beforeunload', event => {
    if (settingsDirty()) { event.preventDefault(); event.returnValue = ''; }
});
document.addEventListener('livewire:navigating', () => { permitSettingsLeave(); clearSecrets(); pending = null; });
window.addEventListener('pagehide', clearSecrets);
window.addEventListener('pageshow', event => {
    if (event.persisted && forms().length) { permitSettingsLeave(); clearSecrets(); location.reload(); }
});
document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onRedirect, onError }) => {
        if (!forms().length) return;
        onRedirect(() => { permitSettingsLeave(); clearSecrets(); });
        onError(({ response, preventDefault }) => {
            if (![401, 403, 419].includes(response.status)) return;
            preventDefault();
            permitSettingsLeave();
            clearSecrets();
            location.assign(response.status === 403 ? '/settings/profile' : '/login');
        });
    });
});
