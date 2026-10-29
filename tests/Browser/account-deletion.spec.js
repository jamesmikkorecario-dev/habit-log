import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { register } from './support.js';

const password = 'Browser-test-123!';
const directory = 'storage/app/account-deletion';
mkdirSync(directory, { recursive: true });
async function login(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}
async function capture(page, state) {
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: `${directory}/${state}-${width}.png`, fullPage: state !== 'confirmation' });
    }
}
const dialogFor = page => page.getByRole('dialog', { name: 'Permanently delete account?', exact: true });
const triggerFor = page => page.getByRole('button', { name: 'Delete my account', exact: true });
async function prepare(page) {
    await triggerFor(page).click();
    const dialog = dialogFor(page);
    await dialog.getByLabel('Current password to delete account').fill(password);
    await dialog.getByRole('checkbox').check();
    return dialog;
}

test('account deletion confirms irreversible cleanup and revokes sessions, shared pages and recovery without affecting others', async ({ page, browser }) => {
    const fixture = JSON.parse(readFileSync(`${directory}/fixtures.json`, 'utf8'));
    const other = await browser.newContext();
    const viewerContext = await browser.newContext();
    const remember = await browser.newContext();
    try {
        await login(page, 'delete-owner@example.test');
        const second = await other.newPage();
        await login(second, 'delete-owner@example.test');
        const remembered = (await other.cookies()).filter(c => c.name.startsWith('remember_'));
        await second.goto('/settings/profile');
        const snapshot = await second.locator('[wire\\:snapshot]').first().getAttribute('wire:snapshot');
        const csrf = await second.locator('input[name=_token]').first().inputValue();
        const update = await second.evaluate(() => document.querySelector('script[data-update-uri]').getAttribute('data-update-uri'));
        const viewer = await viewerContext.newPage();
        await login(viewer, 'delete-foreign@example.test');
        await viewer.goto('/people/deletionowner?year=2024');
        await expect(viewer.getByRole('link', { name: 'Deletion active', exact: true })).toBeVisible();
        const sharedHabit = await viewerContext.newPage();
        await sharedHabit.goto(`/people/deletionowner/habits/${fixture.ownerHabit}?year=2024`);
        await expect(sharedHabit.getByRole('heading', { name: 'Deletion active', exact: true })).toBeVisible();
        await page.goto('/settings/security');
        await capture(page, 'security');
        const trigger = triggerFor(page);
        await trigger.focus();
        await page.keyboard.press('Enter');
        const dialog = dialogFor(page);
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(dialog.getByRole('button', { name: 'Permanently delete account', exact: true })).toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
        await capture(page, 'confirmation');
        await dialog.getByLabel('Current password to delete account').fill('clear-me');
        await dialog.getByRole('checkbox').check();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
        await trigger.click();
        await expect(dialog.getByLabel('Current password to delete account')).toHaveValue('');
        await expect(dialog.getByRole('checkbox')).not.toBeChecked();
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(trigger).toBeFocused();
        await prepare(page);
        let submitted = 0;
        let pendingState;
        await page.exposeFunction('observeDeletionSubmission', state => { pendingState = state; });
        await dialog.locator('form').evaluate(form => {
            form.addEventListener('submit', () => queueMicrotask(() => window.observeDeletionSubmission({
                busy: form.getAttribute('aria-busy'), disabled: form.querySelector('button[type=submit]').disabled,
            })), { once: true });
        });
        let release;
        const gate = new Promise(resolve => { release = resolve; });
        await page.route('**/settings/security/account', async route => { submitted++; await gate; await route.continue(); });
        try {
            await dialog.getByRole('button', { name: 'Permanently delete account', exact: true }).click({ noWaitAfter: true });
            // Read state at submission: native navigation may detach the old
            // accessibility tree while the outgoing request is intercepted.
            await expect.poll(() => pendingState).toEqual({ busy: 'true', disabled: true });
            await expect.poll(() => submitted).toBe(1);
        } finally { release(); }
        await expect(page).toHaveURL(/\/account-deleted$/);
        await expect(page.getByRole('heading', { name: 'Account deleted', exact: true })).toBeFocused();
        await capture(page, 'deleted');
        expect((await page.context().cookies()).filter(c => c.name.startsWith('remember_'))).toHaveLength(0);
        const stale = await other.request.post(update, { headers: { Accept: 'application/json', 'X-Livewire': 'true' }, data: {
            _token: csrf, components: [{ snapshot, updates: { bio: 'STALE-DELETED-OVERWRITE' }, calls: [{ path: '', method: 'save', params: [] }] }],
        } });
        expect([401, 419]).toContain(stale.status());
        await second.reload();
        await expect(second).toHaveURL(/\/login$/);
        await remember.addCookies(remembered);
        expect((await remember.request.get('/dashboard', { maxRedirects: 0 })).status()).toBe(302);
        await viewer.getByRole('button', { name: 'Next year', exact: true }).click();
        await expect(viewer).toHaveURL(/\/people$/);
        await sharedHabit.getByRole('button', { name: 'Next year', exact: true }).click();
        await expect(sharedHabit).toHaveURL(/\/people$/);
        expect((await viewerContext.request.get('/people/deletionowner')).status()).toBe(404);
        expect((await viewerContext.request.get(`/people/deletionowner/habits/${fixture.ownerHabit}`)).status()).toBe(404);
        await viewer.goto(`/habits/${fixture.foreignHabit}`);
        await expect(viewer.getByRole('heading', { name: 'Deletion active', exact: true })).toBeVisible();
        await viewer.goto('/journal?date=2024-01-02');
        await expect(viewer.getByLabel('Journal text', { exact: true })).toHaveValue('DELETE-PRIVATE-JOURNAL');
        await viewer.goto(fixture.emailLink);
        await expect(viewer.getByRole('alert')).toContainText('no longer available');
        await page.goBack();
        await expect(page).toHaveURL(/\/login$/);
        await page.getByLabel('Email address').fill('delete-owner@example.test');
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('body')).toContainText('These credentials do not match our records.');
        await page.goto(`/reset-password/${fixture.resetToken}?email=delete-owner%40example.test`);
        await page.getByLabel('Password', { exact: true }).fill('Replacement-password-456!');
        await page.getByLabel('Confirm password', { exact: true }).fill('Replacement-password-456!');
        await page.getByRole('button', { name: 'Reset password', exact: true }).click();
        await expect(page.locator('body')).toContainText('This password reset token is invalid.');
    } finally { await other.close(); await viewerContext.close(); await remember.close(); }
});

test('account deletion rejects wrong passwords, missing acknowledgement, forged owner and missing CSRF', async ({ page }) => {
    await register(page, 'deletevalidation');
    await page.goto('/settings/security');
    const dialog = await prepare(page);
    await dialog.getByLabel('Current password to delete account').fill('wrong');
    await dialog.getByRole('button', { name: 'Permanently delete account', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Your account was not deleted.' })).toBeFocused();
    await capture(page, 'validation');
    await triggerFor(page).click();
    await expect(dialog.getByLabel('Current password to delete account')).toHaveValue('');
    await dialog.getByLabel('Current password to delete account').fill(password);
    await dialog.locator('form').evaluate(form => form.noValidate = true);
    await dialog.getByRole('button', { name: 'Permanently delete account', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Confirm that account deletion is permanent');
    const token = await page.locator('input[name=_token]').first().inputValue();
    expect((await page.context().request.delete('/settings/security/account', { form: { _token: token, account_id: '999999999', current_password: password, acknowledge_deletion: '1' }, maxRedirects: 0 })).status()).toBe(403);
    expect((await page.context().request.delete('/settings/security/account', { form: { current_password: password, acknowledge_deletion: '1' }, maxRedirects: 0 })).status()).toBe(419);
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
});
