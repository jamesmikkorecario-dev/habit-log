import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { register, action } from './support.js';

const directory = 'storage/app/settings-guard';
mkdirSync(directory, { recursive: true });
const dialog = page => page.getByRole('dialog', { name: 'Leave unsaved settings?' });
const stay = page => dialog(page).getByRole('button', { name: 'Stay and keep editing' });
const discard = page => dialog(page).getByRole('button', { name: 'Discard changes and leave' });
const bio = page => page.getByLabel('Bio', { exact: true });
const save = page => page.getByRole('button', { name: 'Save changes', exact: true });
const dashboard = page => page.getByRole('link', { name: 'habit.log', exact: true });
async function screenshot(page, name) {
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: `${directory}/${name}-${width}.png` });
    }
}
async function signIn(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('Browser-test-123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/(?:dashboard)?$/);
}

test('settings drafts compare values, preserve edits on Stay and Escape, and discard through native and Livewire links', async ({ page }) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await register(page, 'guardvalues');
    await bio(page).fill('temporary'); await bio(page).fill('');
    const privacy = page.getByRole('checkbox', { name: 'Private account', exact: true });
    await privacy.uncheck(); await privacy.check();
    await page.getByLabel('Timezone', { exact: true }).selectOption('Asia/Manila');
    await page.getByLabel('Timezone', { exact: true }).selectOption('UTC');
    await dashboard(page).click(); await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('link', { name: 'Settings', exact: true }).first().click();
    await bio(page).fill('Keep this draft');
    const security = page.getByRole('navigation', { name: 'Settings sections' }).getByRole('link', { name: 'Security' });
    await security.click(); await expect(stay(page)).toBeFocused();
    await screenshot(page, 'confirmation');
    await page.keyboard.press('Tab'); await expect(discard(page)).toBeFocused();
    await page.keyboard.press('Escape'); await expect(dialog(page)).not.toBeVisible();
    await expect(security).toBeFocused(); await expect(bio(page)).toHaveValue('Keep this draft');
    await dashboard(page).click(); await stay(page).click();
    await expect(bio(page)).toHaveValue('Keep this draft');
    await dashboard(page).click(); await discard(page).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goBack(); await expect(page).toHaveURL(/\/settings\/profile$/);
    await expect(bio(page)).toHaveValue('');
    await bio(page).fill('Native link draft'); await security.click(); await discard(page).click();
    await expect(page).toHaveURL(/\/settings\/security$/);
    expect(errors).toEqual([]);
});

test('settings Back and Forward cancel restore history and accepted traversal has one warning', async ({ page }) => {
    await register(page, 'guardhistory');
    await dashboard(page).click(); await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('link', { name: 'Settings', exact: true }).first().click();
    await expect(page).toHaveURL(/\/settings\/profile$/);
    await bio(page).fill('History draft');
    await page.evaluate(() => history.back()); await expect(stay(page)).toBeFocused();
    await stay(page).click(); await expect(page).toHaveURL(/\/settings\/profile$/);
    await expect(bio(page)).toHaveValue('History draft');
    await page.evaluate(() => history.back()); await discard(page).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goForward(); await expect(page).toHaveURL(/\/settings\/profile$/);
    await expect(bio(page)).toHaveValue('');
    await dashboard(page).click(); await expect(page).toHaveURL(/\/dashboard$/); await page.goBack();
    await expect(page).toHaveURL(/\/settings\/profile$/);
    await bio(page).fill('Forward draft');
    await page.evaluate(() => history.forward()); await stay(page).click();
    await expect(page).toHaveURL(/\/settings\/profile$/); await expect(bio(page)).toHaveValue('Forward draft');
    await page.evaluate(() => history.forward()); await discard(page).click();
    await expect(page).toHaveURL(/\/dashboard$/);
});

test('settings successful normalized saves reset baseline, failed saves retain it, and editing is disabled in flight', async ({ page }) => {
    await register(page, 'guardsave');
    const display = page.getByLabel('Display name', { exact: true });
    await display.fill('  Normalized name  '); await action(page, () => save(page).click());
    await expect(display).toHaveValue('Normalized name');
    await dashboard(page).click(); await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/settings/profile');
    await bio(page).fill('x'.repeat(161)); await action(page, () => save(page).click());
    await dashboard(page).click(); await stay(page).click(); await expect(bio(page)).toHaveValue('x'.repeat(161));
    await dashboard(page).click(); await discard(page).click(); await expect(page).toHaveURL(/\/dashboard$/);
    await page.goBack(); await expect(page).toHaveURL(/\/settings\/profile$/); await expect(bio(page)).toHaveValue('');
    await bio(page).fill('saved');
    let release; const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/livewire-*/update', async route => { await gate; await route.continue(); });
    const saving = action(page, () => save(page).click());
    try {
        await expect(bio(page)).toBeDisabled();
        await expect(page.locator('fieldset')).toHaveAttribute('disabled', 'true');
        await expect(page.locator('fieldset').locator('..')).toHaveAttribute('inert', 'true');
    } finally { release(); }
    await saving; await page.unroute('**/livewire-*/update');
    await bio(page).fill('later draft'); await dashboard(page).click(); await stay(page).click();
    await bio(page).fill('saved'); await dashboard(page).click(); await expect(page).toHaveURL(/\/dashboard$/);
});

test('settings native reload and external navigation protect drafts with browser prompts', async ({ page }) => {
    await register(page, 'guardreload'); await bio(page).fill('Reload draft');
    page.once('dialog', async prompt => { expect(prompt.type()).toBe('beforeunload'); await prompt.dismiss(); });
    await page.evaluate(() => location.reload()); await expect(bio(page)).toHaveValue('Reload draft');
    // Wait for the first prompt to settle before issuing the second reload.
    await page.getByLabel('Display name', { exact: true }).focus();
    page.once('dialog', async prompt => { expect(prompt.type()).toBe('beforeunload'); await prompt.accept(); });
    await page.reload(); await expect(bio(page)).toHaveValue('');
    await bio(page).fill('External navigation draft');
    await page.route('https://example.test/leave', route => route.fulfill({ contentType: 'text/html', body: '<h1>External destination</h1>' }));
    await page.evaluate(() => {
        const link = document.createElement('a');
        link.href = 'https://example.test/leave'; link.textContent = 'External destination';
        document.body.append(link);
    });
    page.once('dialog', async prompt => { expect(prompt.type()).toBe('beforeunload'); await prompt.dismiss(); });
    await page.getByRole('link', { name: 'External destination' }).click();
    await expect(bio(page)).toHaveValue('External navigation draft');
    page.once('dialog', async prompt => { expect(prompt.type()).toBe('beforeunload'); await prompt.accept(); });
    await page.getByRole('link', { name: 'External destination' }).click();
    await expect(page).toHaveURL('https://example.test/leave');
});

test('security guards multiple forms without copying secrets, and cleared modal passwords stop being dirty', async ({ page }) => {
    await register(page, 'guardsecurity'); await page.goto('/settings/security');
    const password = page.getByRole('form', { name: 'Change password', exact: true });
    const email = page.getByRole('form', { name: 'Send verification email', exact: true });
    await password.getByLabel('New password', { exact: true }).fill('Synthetic-secret-456!');
    await email.getByLabel('New email address').fill('guard-new@example.test');
    await email.getByLabel('Current password for email change').fill('wrong-password');
    await email.getByRole('button', { name: 'Send verification email', exact: true }).click();
    await expect(stay(page)).toBeFocused(); await expect(dialog(page)).not.toContainText('Synthetic-secret');
    await stay(page).click(); await expect(password.getByLabel('New password', { exact: true })).toHaveValue('Synthetic-secret-456!');
    expect(await page.evaluate(() => JSON.stringify([history.state, { ...localStorage }, { ...sessionStorage }]).includes('Synthetic-secret'))).toBe(false);
    await email.getByRole('button', { name: 'Send verification email', exact: true }).click(); await discard(page).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Your email has not changed.' })).toContainText('password');
    await expect(email.getByLabel('Current password for email change')).toHaveValue('');
    await expect(password.getByLabel('New password', { exact: true })).toHaveValue('');
    await dashboard(page).click(); await stay(page).click(); // Failed email remains unsaved.
    await email.getByLabel('New email address').fill('');
    await page.getByRole('button', { name: 'Sign out all other sessions', exact: true }).click();
    const revoke = page.getByRole('dialog', { name: 'Confirm session sign out', exact: true });
    await revoke.getByLabel('Current password to sign out sessions').fill('temporary');
    await page.keyboard.press('Escape');
    await dashboard(page).click(); await expect(page).toHaveURL(/\/dashboard$/);
});

test('dirty profile cannot obstruct revoked-session handling and logout discards sensitive drafts', async ({ page, browser }) => {
    const user = await register(page, 'guardrevoke');
    const other = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    try {
        const remote = await other.newPage(); await signIn(remote, user.email);
        await remote.goto('/settings/security');
        await remote.getByRole('button', { name: 'Sign out all other sessions', exact: true }).click();
        const revoke = remote.getByRole('dialog', { name: 'Confirm session sign out', exact: true });
        await revoke.getByLabel('Current password to sign out sessions').fill('Browser-test-123!');
        await revoke.getByRole('button', { name: 'Confirm sign out', exact: true }).click();
        await expect(remote.getByRole('status').filter({ hasText: 'Other sessions signed out.' })).toContainText('signed out');
        await bio(page).fill('Revoked draft'); await save(page).click();
        await expect(page).toHaveURL(/\/login$/); await expect(dialog(page)).toHaveCount(0);
        await signIn(page, user.email); await page.goto('/settings/security');
        await page.getByLabel('New password', { exact: true }).fill('Never-store-this-123!');
        await page.getByRole('button', { name: 'Account menu' }).click();
        await page.getByRole('menuitem', { name: 'Log out' }).click();
        await expect(page).toHaveURL(/\/$/); await expect(dialog(page)).toHaveCount(0);
    } finally { await other.close(); }
});


test('profile network failure keeps drafts dirty and another account cannot inherit them', async ({ page, browser }) => {
    await register(page, 'guardnetwork');
    await bio(page).fill('Network draft');
    await page.route('**/livewire-*/update', route => route.abort('failed'));
    const failed = page.waitForEvent('requestfailed', request => request.url().includes('livewire'));
    await save(page).click(); await failed;
    await expect(bio(page)).toBeEnabled();
    await expect(bio(page)).toBeEditable();
    await expect(save(page)).toBeEnabled();
    await dashboard(page).click(); await stay(page).click(); await expect(bio(page)).toHaveValue('Network draft');
    await page.unroute('**/livewire-*/update');
    const other = await browser.newContext({ baseURL: 'http://127.0.0.1:8017' });
    try {
        const setup = await other.newPage(); const replacement = await register(setup, 'guardswitch');
        const token = await page.locator('input[name=_token]').first().inputValue();
        await page.context().request.post('/logout', { form: { _token: token } });
        const switcher = await page.context().newPage(); await signIn(switcher, replacement.email);
        await save(page).click();
        await expect(page.getByLabel('Username', { exact: true })).toHaveValue(replacement.username);
        await expect(bio(page)).toHaveValue(''); await expect(dialog(page)).not.toBeVisible();
        await switcher.close();
    } finally { await other.close(); }
});

test('failed Livewire navigation does not disable protection on the remaining Settings page', async ({ page }) => {
    await register(page, 'guardnavfailure'); await bio(page).fill('Retained draft');
    await page.route('**/dashboard', route => route.abort('failed'));
    const failed = page.waitForEvent('requestfailed', request => request.url().endsWith('/dashboard'));
    await dashboard(page).click(); await discard(page).click(); await failed;
    await expect(bio(page)).toHaveValue('Retained draft');
    await bio(page).fill('Edited after failed navigation');
    await page.unroute('**/dashboard');
    await dashboard(page).click(); await stay(page).click();
    await expect(bio(page)).toHaveValue('Edited after failed navigation');
    await dashboard(page).click(); await discard(page).click();
    await expect(page).toHaveURL(/\/dashboard$/);
});
