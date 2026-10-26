import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { register, action } from './support.js';

const directory = 'storage/app/account-password-checks';
const oldPassword = 'Browser-test-123!';
const newPassword = 'Changed-password-456!';
mkdirSync(directory, { recursive: true });

async function csrf(page) {
    return page.locator('input[name="_token"]').last().inputValue();
}

async function signIn(page, email, password = oldPassword, remember = false) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    if (remember) await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/(dashboard|settings\/security)$/);
}

async function fill(page, current = oldPassword, next = newPassword, confirmation = next) {
    await page.getByLabel('Current password', { exact: true }).fill(current);
    await page.getByLabel('New password', { exact: true }).fill(next);
    await page.getByLabel('Confirm new password', { exact: true }).fill(confirmation);
}

async function capture(page, state) {
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(() => scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        expect((await page.getByRole('button', { name: 'Change password', exact: true }).boundingBox()).height).toBeGreaterThanOrEqual(44);
        await page.screenshot({ path: `${directory}/${state}-${width}.png`, fullPage: true });
    }
}

test('password change retains this browser and revokes other sessions, remembered cookies and stale writes', async ({ page, browser }) => {
    const user = await register(page, 'pwchange');
    await page.getByLabel('Bio', { exact: true }).fill('Private saved bio');
    await action(page, () => page.getByRole('button', { name: 'Save changes' }).click());
    const other = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    const remembered = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    const oldSession = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    try {
        const otherPage = await other.newPage();
        await signIn(otherPage, user.email, oldPassword, true);
        await otherPage.goto('/settings/profile');
        const cookies = await other.cookies();
        const snapshot = await otherPage.locator('[wire\\:snapshot]').first().getAttribute('wire:snapshot');
        const oldCsrf = await csrf(otherPage);
        const updateUrl = await otherPage.evaluate(() => document.querySelector('script[data-update-uri]').getAttribute('data-update-uri'));
        await page.getByRole('navigation', { name: 'Settings sections' }).getByRole('link', { name: 'Security' }).click();
        await expect(page).toHaveURL(/\/settings\/security$/);
        const previousCsrf = await csrf(page);
        await oldSession.addCookies(await page.context().cookies());
        await fill(page);
        await page.getByRole('button', { name: 'Change password', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Password changed.' })).toBeVisible();
        expect((await csrf(page)) !== previousCsrf).toBe(true);
        await capture(page, 'success');
        for (const method of ['$refresh', 'save']) {
            const response = await other.request.post(updateUrl, { headers: { Accept: 'application/json', 'X-Livewire': 'true' }, data: {
                _token: oldCsrf, components: [{ snapshot, updates: { bio: 'Unauthorized overwrite' }, calls: [{ path: '', method, params: [] }] }],
            } });
            expect([401, 419]).toContain(response.status());
            expect(await response.text()).not.toContain('Private saved bio');
        }
        const stalePassword = await other.request.put('/user/password', { headers: { Accept: 'application/json' }, data: {
            _token: oldCsrf, current_password: oldPassword, password: 'Rejected-password-789!', password_confirmation: 'Rejected-password-789!',
        } });
        expect([401, 419]).toContain(stalePassword.status());
        expect((await other.request.get('/settings/security', { maxRedirects: 0 })).status()).toBe(302);
        await remembered.addCookies(cookies.filter(cookie => cookie.name.startsWith('remember_')));
        expect((await remembered.request.get('/settings/security', { maxRedirects: 0 })).status()).toBe(302);
        expect((await oldSession.request.get('/settings/security', { maxRedirects: 0 })).status()).toBe(302);
        await page.getByRole('navigation', { name: 'Settings sections' }).getByRole('link', { name: 'Profile' }).click();
        await expect(page.getByLabel('Bio', { exact: true })).toHaveValue('Private saved bio');
        await page.getByLabel('Bio', { exact: true }).fill('Saved after changing password');
        await action(page, () => page.getByRole('button', { name: 'Save changes' }).click());
        await expect(page.getByText('Saved.', { exact: true })).toBeVisible();
        // The old login fails; the new login succeeds in the revoked context.
        await otherPage.goto('/login');
        await otherPage.getByLabel('Email address').fill(user.email);
        await otherPage.getByLabel('Password', { exact: true }).fill(oldPassword);
        await otherPage.getByRole('button', { name: 'Log in', exact: true }).click();
        await expect(otherPage).toHaveURL(/\/login$/);
        await expect(otherPage.locator('[aria-invalid=true]').first()).toBeVisible();
        await signIn(otherPage, user.email, newPassword);
        await otherPage.goto('/settings/profile');
        await expect(otherPage.getByLabel('Bio', { exact: true })).toHaveValue('Saved after changing password');
    } finally {
        await other.close(); await remembered.close(); await oldSession.close();
    }
});

test('password change validates, clears secrets, focuses errors, enforces CSRF and limits attempts', async ({ page, context }) => {
    await register(page, 'pwinvalid');
    await page.goto('/settings/security');
    await capture(page, 'form');
    await fill(page, 'Incorrect-password!');
    await page.getByRole('button', { name: 'Change password', exact: true }).click();
    const summary = page.getByRole('alert').filter({ hasText: 'Your password has not changed.' });
    await expect(summary).toBeFocused();
    await expect(page.getByLabel('Current password', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    for (const name of ['Current password', 'New password', 'Confirm new password']) {
        await expect(page.getByLabel(name, { exact: true })).toHaveValue('');
    }
    await capture(page, 'wrong-current-password');
    await fill(page, oldPassword, 'short', 'mismatch');
    await page.getByRole('button', { name: 'Change password', exact: true }).click();
    await expect(summary).toBeFocused();
    await expect(page.getByLabel('New password', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await capture(page, 'validation');
    expect((await context.request.put('/user/password', { headers: { Accept: 'application/json' }, data: { current_password: oldPassword, password: newPassword, password_confirmation: newPassword } })).status()).toBe(419);
    for (let i = 0; i < 3; i++) {
        expect((await context.request.put('/user/password', { headers: { Accept: 'application/json' }, data: {
            _token: await csrf(page), current_password: 'wrong', password: newPassword, password_confirmation: newPassword,
        } })).status()).toBe(422);
    }
    const limited = await context.request.put('/user/password', { headers: { Accept: 'application/json' }, data: {
        _token: await csrf(page), current_password: oldPassword, password: newPassword, password_confirmation: newPassword,
    } });
    expect(limited.status()).toBe(429);
    expect(limited.headers()['retry-after']).toBeTruthy();
    expect((await context.request.get('/settings/profile')).status()).toBe(200);
});

test('password settings supports mobile keyboard submission and prevents duplicate submits', async ({ page }) => {
    await register(page, 'pwkeyboard');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('navigation', { name: 'Settings sections' }).getByRole('link', { name: 'Security' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/settings\/security$/);
    await page.getByLabel('Current password', { exact: true }).focus();
    await page.keyboard.type(oldPassword);
    await page.keyboard.press('Tab'); // password visibility control
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('New password', { exact: true })).toBeFocused();
    await page.keyboard.type(newPassword);
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Confirm new password', { exact: true })).toBeFocused();
    await page.keyboard.type(newPassword);
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    const submit = page.getByRole('button', { name: 'Change password', exact: true });
    await expect(submit).toBeFocused();
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    let requests = 0;
    await page.route('**/user/password', async route => { requests++; await gate; await route.continue(); });
    try {
        await page.keyboard.press('Enter');
        await expect.poll(() => page.evaluate(() => {
            const form = document.querySelector('form[aria-label="Change password"]');
            return form.getAttribute('aria-busy') === 'true' && form.querySelector('button[type=submit]').disabled;
        })).toBe(true);
        await expect.poll(() => requests).toBe(1);
    } finally { release(); }
    await expect(page.getByRole('status').filter({ hasText: 'Password changed.' })).toBeFocused();
    for (const width of [320, 375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
});
