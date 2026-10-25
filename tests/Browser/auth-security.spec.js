import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { register, action } from './support.js';

const directory = 'storage/app/auth-security-fixes';
const message = 'If an account matches that email, a password reset link will be sent. Please wait a minute before trying again.';
mkdirSync(directory, { recursive: true });

async function csrf(page) {
    return page.locator('input[name="_token"]').first().inputValue();
}

async function signIn(page, email, password = 'Browser-test-123!', remember = false) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    if (remember) await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

async function screenshots(page, name) {
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(() => scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await expect(page.getByRole('main')).toBeVisible();
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        expect((await page.locator('button[type=submit]').boundingBox()).height).toBeGreaterThanOrEqual(44);
        await page.screenshot({ path: `${directory}/${name}-${width}.png`, fullPage: true });
    }
}

async function resetLink(email) {
    let link;
    await expect.poll(() => {
        const mail = readFileSync('storage/app/phase7-checks/browser-server.log', 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
        link = [...mail.matchAll(/http:\/\/127\.0\.0\.1:8017\/reset-password\/[a-zA-Z0-9]+\?email=[^\s<"\)\]]+/g)]
            .map(m => m[0].replace(/&amp;/g, '&')).find(url => decodeURIComponent(url).includes(email));
        return !!link;
    }).toBe(true);
    return link;
}

test('password reset revokes other browsers, remembered login and stale Livewire reads and writes', async ({ page, browser }) => {
    const user = await register(page, 'revoke');
    await page.getByLabel('Bio', { exact: true }).fill('Saved before recovery');
    await action(page, () => page.getByRole('button', { name: 'Save changes' }).click());
    const second = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    const recovery = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    const remembered = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    try {
        const otherDevice = await second.newPage();
        await signIn(otherDevice, user.email, 'Browser-test-123!', true);
        await otherDevice.goto('/settings/profile');
        const oldCookies = await second.cookies();
        const snapshot = await otherDevice.locator('[wire\\:snapshot]').first().getAttribute('wire:snapshot');
        const oldCsrf = await csrf(otherDevice);
        const updateUrl = await otherDevice.evaluate(() => document.querySelector('script[data-update-uri]').getAttribute('data-update-uri'));
        const recoverPage = await recovery.newPage();
        await recoverPage.goto('/forgot-password');
        await recoverPage.getByLabel('Email address').fill(user.email);
        await recoverPage.getByRole('button', { name: 'Email password reset link' }).click();
        await expect(recoverPage.getByRole('status').filter({ hasText: message })).toHaveText(message);
        const url = await resetLink(user.email);
        await recoverPage.goto(url);
        await recoverPage.getByLabel('Password', { exact: true }).fill('short');
        await recoverPage.getByLabel('Confirm password', { exact: true }).fill('different');
        await recoverPage.getByRole('button', { name: 'Reset password', exact: true }).click();
        await expect(recoverPage.locator('[aria-invalid=true]').first()).toBeVisible();
        // Failed reset must not revoke existing sessions.
        expect((await second.request.get('/settings/profile', { maxRedirects: 0 })).status()).toBe(200);
        await screenshots(recoverPage, 'reset-validation');
        await recoverPage.getByLabel('Password', { exact: true }).fill('Recovered-password-456!');
        await recoverPage.getByLabel('Confirm password', { exact: true }).fill('Recovered-password-456!');
        await recoverPage.getByRole('button', { name: 'Reset password', exact: true }).click();
        await expect(recoverPage).toHaveURL(/\/login$/);
        for (const method of ['$refresh', 'save']) {
            const response = await second.request.post(updateUrl, {
                headers: { Accept: 'application/json', 'X-Livewire': 'true' },
                data: { _token: oldCsrf, components: [{ snapshot, updates: { bio: 'Unauthorized overwrite' }, calls: [{ path: '', method, params: [] }] }] },
            });
            expect([401, 419]).toContain(response.status());
            expect(await response.text()).not.toContain('Saved before recovery');
        }
        expect((await page.context().request.get('/settings/profile', { maxRedirects: 0 })).status()).toBe(302);
        expect((await second.request.get('/settings/profile', { maxRedirects: 0 })).status()).toBe(302);
        await remembered.addCookies(oldCookies.filter(cookie => cookie.name.startsWith('remember_')));
        expect((await remembered.request.get('/settings/profile', { maxRedirects: 0 })).status()).toBe(302);
        await signIn(recoverPage, user.email, 'Recovered-password-456!');
        await recoverPage.goto('/settings/profile');
        await expect(recoverPage.getByLabel('Bio', { exact: true })).toHaveValue('Saved before recovery');
        await action(recoverPage, () => recoverPage.getByRole('button', { name: 'Save changes' }).click());
        await expect(recoverPage.getByText('Saved.', { exact: true })).toBeVisible();
        // A fresh login is usable, including normal CSRF protection.
        expect((await recovery.request.post('/logout', { headers: { Accept: 'application/json' } })).status()).toBe(419);
    } finally {
        await second.close(); await recovery.close(); await remembered.close();
    }
});

test('recovery hides membership and resend throttling while retaining email and accessible feedback', async ({ page, browser }) => {
    const user = await register(page, 'enumeration');
    const guest = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    try {
        const recoverPage = await guest.newPage();
        await recoverPage.goto('/forgot-password');
        await screenshots(recoverPage, 'forgot-password');
        await recoverPage.getByLabel('Email address').fill('invalid-email');
        await recoverPage.locator('form').evaluate(form => form.noValidate = true);
        await recoverPage.getByRole('button', { name: 'Email password reset link' }).click();
        await expect(recoverPage.locator('[aria-invalid=true]')).toBeVisible();
        await expect(recoverPage.getByLabel('Email address')).toHaveValue('invalid-email');
        await screenshots(recoverPage, 'forgot-validation');
        const results = [];
        for (const email of [user.email, 'missing-recovery@example.test', user.email]) {
            await recoverPage.getByLabel('Email address').fill(email);
            await recoverPage.getByRole('button', { name: 'Email password reset link' }).click();
            await expect(recoverPage.getByRole('status').filter({ hasText: message })).toHaveText(message);
            await expect(recoverPage.getByLabel('Email address')).toHaveValue(email);
            await expect(recoverPage.locator('[aria-invalid=true]')).toHaveCount(0);
            const response = await guest.request.post('/forgot-password', { headers: { Accept: 'application/json' }, data: { _token: await csrf(recoverPage), email } });
            expect(response.status()).toBe(200); results.push(await response.json());
        }
        expect(results[0]).toEqual(results[1]); expect(results[1]).toEqual(results[2]);
        await screenshots(recoverPage, 'forgot-success');
        await recoverPage.getByRole('link', { name: 'log in', exact: true }).focus();
        await recoverPage.keyboard.press('Enter');
        await expect(recoverPage).toHaveURL(/\/login$/);
        await recoverPage.goto('/reset-password/invalid?email=missing-recovery@example.test');
        await screenshots(recoverPage, 'reset-password');
        await recoverPage.getByRole('link', { name: 'Return to log in' }).focus();
        await recoverPage.keyboard.press('Enter');
        await expect(recoverPage).toHaveURL(/\/login$/);
    } finally { await guest.close(); }
});

test('password confirmation renders, rejects wrong passwords, confirms and limits retries', async ({ page, context }) => {
    await register(page, 'confirm');
    await page.goto('/user/confirm-password');
    await screenshots(page, 'confirm-password');
    await page.getByLabel('Password', { exact: true }).fill('Wrong-password');
    await page.getByRole('button', { name: 'Confirm password', exact: true }).click();
    await expect(page.getByText('The provided password was incorrect.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    await screenshots(page, 'confirm-error');
    await page.getByLabel('Password', { exact: true }).fill('Browser-test-123!');
    await page.getByRole('button', { name: 'Confirm password', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(await (await context.request.get('/user/confirmed-password-status')).json()).toEqual({ confirmed: true });
    await page.goto('/user/confirm-password');
    for (let i = 0; i < 3; i++) {
        const response = await context.request.post('/user/confirm-password', { headers: { Accept: 'application/json' }, data: { _token: await csrf(page), password: 'wrong' } });
        expect(response.status()).toBe(422);
    }
    const limited = await context.request.post('/user/confirm-password', { headers: { Accept: 'application/json' }, data: { _token: await csrf(page), password: 'Browser-test-123!' } });
    expect(limited.status()).toBe(429);
    expect(limited.headers()['retry-after']).toBeTruthy();
});
