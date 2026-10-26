import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { register, action } from './support.js';

const password = 'Browser-test-123!';
const directory = 'storage/app/session-management';
mkdirSync(directory, { recursive: true });
async function login(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}
async function capture(page, state) {
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.evaluate(() => scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: `${directory}/${state}-${width}.png`, fullPage: true });
    }
}

test('individual then bulk session revocation isolates browsers and rejects stale writes and remembered sign-ins', async ({ page, browser }) => {
    const user = await register(page, 'sessionowner');
    const selected = await browser.newContext({ userAgent: 'Mozilla/5.0 Windows Firefox/130.0' });
    const survivor = await browser.newContext({ userAgent: 'Mozilla/5.0 Macintosh Safari/600.0' });
    const recalled = await browser.newContext();
    try {
        const target = await selected.newPage();
        const keep = await survivor.newPage();
        await login(target, user.email);
        await login(keep, user.email);
        await target.goto('/settings/profile');
        const snapshot = await target.locator('[wire\\:snapshot]').first().getAttribute('wire:snapshot');
        const csrf = await target.locator('input[name=_token]').first().inputValue();
        const update = await target.evaluate(() => document.querySelector('script[data-update-uri]').getAttribute('data-update-uri'));
        const cookies = await selected.cookies();
        await page.goto('/settings/security');
        await expect(page.getByText('Other sessions (2)', { exact: true })).toBeVisible();
        await capture(page, 'sessions');
        const row = page.getByRole('list', { name: 'Other active sessions' }).getByRole('listitem').filter({ hasText: 'Firefox on Windows' });
        await row.getByRole('button', { name: 'Sign out this session', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Confirm session sign out' });
        await expect(dialog).toBeVisible();
        await capture(page, 'confirmation');
        await dialog.getByLabel('Current password to sign out sessions').fill(password);
        await dialog.getByRole('button', { name: 'Confirm sign out', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Selected session signed out.' })).toBeFocused();
        await expect(page.getByText('Other sessions (1)', { exact: true })).toBeVisible();
        const stale = await selected.request.post(update, { headers: { Accept: 'application/json', 'X-Livewire': 'true' }, data: {
            _token: csrf, components: [{ snapshot, updates: { bio: 'Rejected session overwrite' }, calls: [{ path: '', method: 'save', params: [] }] }],
        } });
        expect([401, 419]).toContain(stale.status());
        await recalled.addCookies(cookies.filter(cookie => cookie.name.startsWith('remember_')));
        expect((await recalled.request.get('/settings/profile', { maxRedirects: 0 })).status()).toBe(302);
        await keep.goto('/settings/profile');
        await expect(keep.getByLabel('Bio', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Sign out all other sessions', exact: true }).click();
        await dialog.getByLabel('Current password to sign out sessions').fill(password);
        await dialog.getByRole('button', { name: 'Confirm sign out', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Other sessions signed out.' })).toBeFocused();
        await keep.reload();
        await expect(keep).toHaveURL(/\/login$/);
        await page.goto('/settings/profile');
        await page.getByLabel('Bio', { exact: true }).fill('Saved after session revocation');
        await action(page, () => page.getByRole('button', { name: 'Save changes' }).click());
        await expect(page.getByText('Saved.', { exact: true })).toBeVisible();
    } finally { await selected.close(); await survivor.close(); await recalled.close(); }
});

test('session confirmation supports keyboard cancel, clears passwords, validates and protects CSRF', async ({ page }) => {
    await register(page, 'sessionvalidation');
    await page.goto('/settings/security');
    await page.setViewportSize({ width: 390, height: 1000 });
    const trigger = page.getByRole('button', { name: 'Sign out all other sessions', exact: true });
    await trigger.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Confirm session sign out' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Current password to sign out sessions').fill('should-be-cleared');
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await trigger.click();
    await expect(dialog.getByLabel('Current password to sign out sessions')).toHaveValue('');
    await dialog.getByLabel('Current password to sign out sessions').fill('wrong');
    await dialog.getByRole('button', { name: 'Confirm sign out', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'No sessions were signed out.' })).toBeFocused();
    await capture(page, 'validation');
    await trigger.click();
    await expect(dialog.getByLabel('Current password to sign out sessions')).toHaveValue('');
    await page.keyboard.press('Escape');
    expect((await page.context().request.post('/settings/security/sessions/revoke', { data: { current_password: password, session_target: 'all' }, maxRedirects: 0 })).status()).toBe(419);
    await trigger.click();
    await dialog.getByLabel('Current password to sign out sessions').fill(password);
    let observed;
    let requests = 0;
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    await page.exposeFunction('observeSessionSubmission', state => { observed = state; });
    await page.evaluate(() => {
        const form = document.querySelector('form[aria-label="Confirm session sign out"]');
        form.addEventListener('submit', () => queueMicrotask(() => window.observeSessionSubmission({
            busy: form.getAttribute('aria-busy'), disabled: form.querySelector('button[type=submit]').disabled,
        })), { once: true });
    });
    await page.route('**/settings/security/sessions/revoke', async route => { requests++; await gate; await route.continue(); });
    try {
        await dialog.getByRole('button', { name: 'Confirm sign out', exact: true }).click({ noWaitAfter: true });
        await expect.poll(() => observed).toEqual({ busy: 'true', disabled: true });
        await expect.poll(() => requests).toBe(1);
    } finally { release(); }
    await expect(page.getByRole('status').filter({ hasText: 'Other sessions signed out.' })).toBeFocused();
    await expect(page.getByText('This session', { exact: true })).toBeVisible();
});
