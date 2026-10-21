import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { action, register } from './support.js';

mkdirSync('storage/app/settings-design-checks', { recursive: true });

test('profile settings design groups preferences, preserves drafts and confirms saved changes', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const user = await register(page, 'settingsdesign');
    await page.goto('/settings/profile');
    const form = page.getByRole('form', { name: 'Profile settings', exact: true });
    const username = form.getByLabel('Username', { exact: true });
    const display = form.getByLabel('Display name', { exact: true });
    const bio = form.getByLabel('Bio', { exact: true });
    const timezone = form.getByLabel('Timezone', { exact: true });
    const theme = form.getByLabel('Theme', { exact: true });
    const privacy = form.getByRole('checkbox', { name: 'Private account', exact: true });
    const save = form.getByRole('button', { name: 'Save changes', exact: true });
    const success = form.getByRole('status');
    const information = form.getByRole('region', { name: 'Profile information', exact: true });
    const privacyCard = form.getByRole('region', { name: 'Privacy', exact: true });
    const preferences = form.getByRole('region', { name: 'Preferences', exact: true });
    await expect(timezone).toHaveValue('UTC');
    await expect(privacy).toBeChecked();
    await expect(theme.locator('option')).toHaveCount(1);
    await expect(theme.locator('option:checked')).toHaveText('Terminal Green');
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(() => scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const heading = await page.getByRole('heading', { name: 'Profile Settings', exact: true }).boundingBox();
        const bounds = await form.boundingBox();
        expect(bounds.x).toBe((width - Math.min(width, 1280)) / 2 + (width < 640 ? 16 : 24));
        expect(heading.x).toBe(bounds.x);
        expect(bounds.width).toBe(Math.min(width, 1280) - (width < 640 ? 32 : 48));
        const main = await information.boundingBox();
        const side = await privacyCard.boundingBox();
        const prefs = await preferences.boundingBox();
        if (width >= 1024) {
            expect(side.x).toBeGreaterThan(main.x + main.width);
            expect(side.y).toBe(main.y);
        } else expect(side.y).toBeGreaterThan(main.y + main.height);
        expect(prefs.y).toBeGreaterThan(side.y + side.height);
        expect((await username.boundingBox()).width).toBeLessThanOrEqual(576);
        const select = await timezone.boundingBox();
        expect(select.x + select.width).toBeLessThanOrEqual(prefs.x + prefs.width);
        await save.scrollIntoViewIfNeeded();
        expect((await save.boundingBox()).height).toBeGreaterThanOrEqual(44);
        await display.focus();
        await username.evaluate(el => scrollBy(0, el.getBoundingClientRect().top - 20));
        await page.keyboard.press('Shift+Tab');
        await expect(username).toBeFocused();
        expect(await username.evaluate(el => el.getBoundingClientRect().top - document.querySelector('nav').getBoundingClientRect().bottom)).toBeGreaterThan(16);
        if (width === 390 || width === 1440) {
            await page.evaluate(() => scrollTo(0, 0));
            await page.screenshot({ path: `storage/app/settings-design-checks/profile-${width}.png`, fullPage: true });
        }
    }
    const disclosure = form.getByText('Learn about privacy', { exact: true });
    await disclosure.focus();
    await page.keyboard.press('Enter');
    await expect(form.getByText('Pending requests stay pending', { exact: false })).toBeVisible();
    await expect(form.getByText('Journal, mood, habit notes, and N/A reasons stay private.', { exact: false })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 960 });
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: 'storage/app/settings-design-checks/privacy-390.png', fullPage: true });
    await privacy.focus();
    await page.keyboard.press('Space');
    await expect(privacy).not.toBeChecked();
    await expect(form.getByText('Turning this off lets signed-in users see your public habits and their history.', { exact: true })).toBeVisible();
    await display.fill('Keep my display name');
    await bio.fill('Draft biography 🌱');
    await timezone.selectOption('Asia/Manila');
    await username.fill('InvalidName');
    await action(page, () => save.click());
    await expect(username).toHaveAttribute('aria-invalid', 'true');
    await expect(form.getByRole('alert').filter({ hasText: 'Please review the highlighted fields.' })).toBeFocused();
    await expect(display).toHaveValue('Keep my display name');
    await expect(bio).toHaveValue('Draft biography 🌱');
    await expect(timezone).toHaveValue('Asia/Manila');
    await expect(privacy).not.toBeChecked();
    await expect(success.getByText('Saved.', { exact: true })).not.toBeVisible();
    await username.fill(user.username);
    await bio.fill('🌱'.repeat(161));
    await expect(form.locator('#bio-count')).toHaveText('161 / 160 characters');
    await action(page, () => save.click());
    await expect(bio).toHaveAttribute('aria-invalid', 'true');
    await expect(bio).toHaveValue('🌱'.repeat(161));
    await bio.fill('🌱'.repeat(160));
    await expect(form.locator('#bio-count')).toHaveText('160 / 160 characters');
    await page.route('**/livewire-*/update', async route => {
        await new Promise(resolve => setTimeout(resolve, 600));
        await route.continue();
    });
    let saves = 0;
    page.on('request', request => {
        if (request.method() === 'POST' && request.url().includes('livewire')) {
            saves += (request.postDataJSON()?.components ?? []).flatMap(component => component.calls ?? []).filter(call => call.method === 'save').length;
        }
    });
    const saving = action(page, () => save.click());
    await expect(form.getByRole('button', { name: 'Saving…', exact: true })).toBeDisabled();
    await expect(success.getByText('Saved.', { exact: true })).not.toBeVisible();
    await saving;
    expect(saves).toBe(1);
    await expect(success.getByText('Saved.', { exact: true })).toBeVisible();
    await page.reload();
    await expect(timezone).toHaveValue('Asia/Manila');
    await expect(timezone.locator('option:checked')).toHaveText('Asia/Manila');
    await expect(theme).toHaveValue('terminal-green');
    await expect(bio).toHaveValue('🌱'.repeat(160));
    await expect(display).toHaveValue('Keep my display name');
    await expect(privacy).not.toBeChecked();
    await display.fill('');
    await bio.fill('0');
    await action(page, () => save.click());
    await expect(success.getByText('Saved.', { exact: true })).toBeVisible();
    await bio.fill('Edited after saving');
    await expect(success.getByText('Saved.', { exact: true })).not.toBeVisible();
    // Settings has no navigation guard: an explicit reload retains only saved values.
    await page.reload();
    await expect(bio).toHaveValue('0');
    await expect(display).toHaveValue('');
    await page.goto(`/people/${user.username}`);
    await expect(page.getByRole('heading', { name: user.username, exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});
