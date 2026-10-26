import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { register, action } from './support.js';

const directory = 'storage/app/email-change';
const password = 'Browser-test-123!';
mkdirSync(directory, { recursive: true });
const fixtures = () => JSON.parse(readFileSync(`${directory}/fixtures.json`, 'utf8'));
const csrf = page => page.locator('input[name="_token"]').first().inputValue();
async function signIn(page, email, remember = false) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    if (remember) await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}
async function latestLink() {
    let link;
    await expect.poll(() => {
        const log = readFileSync('storage/app/phase7-checks/browser-server.log', 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
        link = [...log.matchAll(/http:\/\/127\.0\.0\.1:8017\/settings\/security\/email\/verify\?[^\s<"\)\]]+/g)].at(-1)?.[0].replace(/&amp;/g, '&');
        return !!link;
    }).toBe(true);
    return link;
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
async function requestChange(page, email) {
    await page.goto('/settings/security');
    await page.getByLabel('New email address', { exact: true }).fill(email);
    await page.getByLabel('Current password for email change', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Send verification email', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Verification email sent.' })).toBeFocused();
    return latestLink();
}

test('email confirmation retains the confirming browser and revokes old sessions, cookies and identity', async ({ page, browser }) => {
    const user = await register(page, 'emailowner');
    const next = `new-${user.email}`;
    await page.getByLabel('Bio', { exact: true }).fill('Preserved private bio');
    await action(page, () => page.getByRole('button', { name: 'Save changes' }).click());
    const other = await browser.newContext();
    const remember = await browser.newContext();
    const foreign = await browser.newContext();
    try {
        const confirming = await other.newPage();
        await signIn(confirming, user.email, true);
        const rememberedCookies = await other.cookies();
        const url = await requestChange(page, next);
        await capture(page, 'pending');
        const staleCsrf = await csrf(page);
        await page.goto('/settings/profile');
        const snapshot = await page.locator('[wire\\:snapshot]').first().getAttribute('wire:snapshot');
        const update = await page.evaluate(() => document.querySelector('script[data-update-uri]').getAttribute('data-update-uri'));
        const wrong = await foreign.newPage();
        await register(wrong, 'emailforeign');
        await wrong.goto(url);
        await expect(wrong.getByRole('alert')).toContainText('no longer available');
        await expect(wrong.getByText(next, { exact: false })).toHaveCount(0);
        await confirming.goto(url);
        await expect(confirming.getByText(`New login email: ${next}`)).toBeVisible();
        await capture(confirming, 'confirmation');
        await confirming.getByLabel('Current password to confirm email').fill('wrong');
        await confirming.getByRole('button', { name: 'Confirm email change', exact: true }).click();
        await expect(confirming.getByRole('alert').filter({ hasText: 'Your email has not changed.' })).toBeFocused();
        await expect(confirming.getByLabel('Current password to confirm email')).toHaveValue('');
        const previousCsrf = await csrf(confirming);
        expect((await other.request.post(url, { data: { current_password: password }, maxRedirects: 0 })).status()).toBe(419);
        await confirming.getByLabel('Current password to confirm email').fill(password);
        await confirming.getByRole('button', { name: 'Confirm email change', exact: true }).click();
        await expect(confirming.getByRole('status').filter({ hasText: 'Email changed.' })).toBeFocused();
        expect(await csrf(confirming)).not.toBe(previousCsrf);
        await capture(confirming, 'success');
        const stale = await page.context().request.post(update, { headers: { Accept: 'application/json', 'X-Livewire': 'true' }, data: {
            _token: staleCsrf, components: [{ snapshot, updates: { bio: 'Rejected overwrite' }, calls: [{ path: '', method: 'save', params: [] }] }],
        } });
        expect([401, 419]).toContain(stale.status());
        await remember.addCookies(rememberedCookies.filter(cookie => cookie.name.startsWith('remember_')));
        expect((await remember.request.get('/settings/security', { maxRedirects: 0 })).status()).toBe(302);
        await confirming.goto('/settings/profile');
        await expect(confirming.getByLabel('Bio', { exact: true })).toHaveValue('Preserved private bio');
        await confirming.getByLabel('Bio', { exact: true }).fill('Saved after email confirmation');
        await action(confirming, () => confirming.getByRole('button', { name: 'Save changes' }).click());
        await expect(confirming.getByText('Saved.', { exact: true })).toBeVisible();
        await confirming.goto(url);
        await expect(confirming.getByRole('alert')).toContainText('no longer available');
        await page.goto('/login');
        await page.getByLabel('Email address').fill(user.email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('[aria-invalid=true]').first()).toBeVisible();
        await signIn(page, next);
    } finally {
        await other.close(); await remember.close(); await foreign.close();
    }
});

test('email pending lifecycle supports stale links, resend rotation, cancellation and expired requests', async ({ page, browser }) => {
    await signIn(page, 'email-lifecycle@example.test');
    await page.goto(fixtures().replaced);
    await expect(page.getByRole('alert')).toContainText('no longer available');
    await page.goto('/settings/security');
    await page.getByLabel('Current password to resend').fill(password);
    await page.getByRole('button', { name: 'Resend verification email', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Verification email sent.' })).toBeVisible();
    const resent = await latestLink();
    await page.goto(fixtures().lifecycle);
    await expect(page.getByRole('alert')).toContainText('no longer available');
    await page.goto(resent);
    await expect(page.getByLabel('Current password to confirm email')).toBeVisible();
    await page.goto('/settings/security');
    await page.getByRole('button', { name: 'Cancel email change', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Email change cancelled.' })).toBeFocused();
    await page.goto(resent);
    await expect(page.getByRole('alert')).toContainText('no longer available');
    const context = await browser.newContext();
    try {
        const expired = await context.newPage();
        await signIn(expired, 'email-expired@example.test');
        await expired.goto(fixtures().expired);
        await expect(expired.getByRole('alert')).toContainText('no longer available');
        await capture(expired, 'expired-link');
        await expired.goto('/settings/security');
        await expect(expired.getByRole('heading', { name: 'Expired email change' })).toBeVisible();
        await expired.getByLabel('Current password to resend').fill(password);
        await expired.getByRole('button', { name: 'Resend verification email', exact: true }).click();
        await expect(expired.getByRole('status').filter({ hasText: 'Verification email sent.' })).toBeVisible();
        const refreshed = await latestLink();
        await expired.goto(refreshed);
        await expired.getByLabel('Current password to confirm email').fill(password);
        await expired.getByRole('button', { name: 'Confirm email change', exact: true }).click();
        await expect(expired.getByRole('status').filter({ hasText: 'Email changed.' })).toBeFocused();
    } finally { await context.close(); }
});

test('email forms support mobile keyboard, private validation, CSRF and account-bound cooldown', async ({ page, browser }) => {
    const user = await register(page, 'emailmobile');
    await page.goto('/settings/security');
    await page.setViewportSize({ width: 390, height: 844 });
    await capture(page, 'form');
    expect((await page.context().request.post('/settings/security/email', { data: { new_email: 'forged@example.test', current_password: password, generation: 'none' }, maxRedirects: 0 })).status()).toBe(419);
    await page.getByLabel('New email address').focus();
    await page.keyboard.type(`next-${user.email}`);
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Current password for email change')).toBeFocused();
    await page.keyboard.type(password);
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('button', { name: 'Send verification email', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Verification email sent.' })).toBeFocused();
    const url = await latestLink();
    const context = await browser.newContext();
    try {
        const other = await context.newPage();
        // The old address still works, and cooldown persists across sessions.
        await signIn(other, user.email);
        await other.goto('/settings/security');
        const generation = await other.locator('form[aria-label="Resend verification email"] input[name=generation]').inputValue();
        expect((await context.request.post('/settings/security/email/resend', { data: { _token: await csrf(other), current_password: password, generation }, maxRedirects: 0 })).status()).toBe(429);
    } finally { await context.close(); }
    // A logged-out owner returns to the signed confirmation page after login.
    await page.context().clearCookies();
    await page.goto(url);
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Email address').fill(user.email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page.getByLabel('Current password to confirm email')).toBeVisible();
    await page.getByLabel('Current password to confirm email').fill(password);
    await page.getByRole('button', { name: 'Confirm email change', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Email changed.' })).toBeFocused();
});

test('email validation clears passwords and marks only the submitted form', async ({ page, browser }) => {
    await register(page, 'emailinvalid');
    await page.goto('/settings/security');
    await page.getByLabel('New email address').fill('valid-format@example.test');
    await page.getByLabel('Current password for email change').fill('wrong');
    await page.getByRole('button', { name: 'Send verification email', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Your email has not changed.' })).toBeFocused();
    await expect(page.getByLabel('Current password for email change')).toHaveValue('');
    await expect(page.getByLabel('Current password for email change')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('New email address')).toHaveValue('valid-format@example.test');
    await expect(page.getByLabel('Current password', { exact: true })).not.toHaveAttribute('aria-invalid', 'true');
    await capture(page, 'validation');
    const context = await browser.newContext();
    try {
        const pending = await context.newPage();
        await signIn(pending, 'email-validation@example.test');
        await pending.goto('/settings/security');
        await pending.getByLabel('Current password to resend').fill('wrong');
        await pending.getByRole('button', { name: 'Resend verification email', exact: true }).click();
        await expect(pending.getByRole('alert').filter({ hasText: 'Your email has not changed.' })).toBeFocused();
        await expect(pending.getByLabel('Current password to resend')).toHaveAttribute('aria-invalid', 'true');
        await expect(pending.getByLabel('Current password for email change')).not.toHaveAttribute('aria-invalid', 'true');
        await expect(pending.getByRole('heading', { name: 'Pending email change', exact: true })).toBeVisible();
    } finally { await context.close(); }
});
