import { test, expect } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { action, register } from './support.js';

test('habit form navigation keeps keyboard focus and anchors below the sticky header', async ({ page }) => {
    await register(page, 'formfocus');
    await page.goto('/habits/create');
    const samples = [];
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(() => scrollTo(0, 0));
        const heading = await page.locator('main h1').boundingBox();
        const nav = await page.locator('body > div > nav').boundingBox();
        expect(heading.y).toBeGreaterThan(nav.y + nav.height);
        const form = await page.locator('[data-habit-configuration]').boundingBox();
        const padding = width < 640 ? 16 : 24;
        expect(form.width).toBe(Math.min(width, 1280) - padding * 2);
        expect(form.x).toBe((width - Math.min(width, 1280)) / 2 + padding);
        expect(heading.x).toBe(form.x);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.getByLabel('Description (optional)', { exact: true }).focus();
        await page.getByLabel('Habit name', { exact: true }).evaluate(el => window.scrollBy(0, el.getBoundingClientRect().top - 20));
        await page.keyboard.press('Shift+Tab');
        await expect(page.getByLabel('Habit name', { exact: true })).toBeFocused();
        await expect.poll(() => page.evaluate(() => document.activeElement.getBoundingClientRect().top - document.querySelector('nav').getBoundingClientRect().bottom)).toBeGreaterThan(16);
        samples.push(await page.evaluate(() => ({ width: innerWidth, focusTop: document.activeElement.getBoundingClientRect().top, navBottom: document.querySelector('nav').getBoundingClientRect().bottom })));
        await page.locator('#habit-dates-privacy').evaluate(el => el.scrollIntoView({ block: 'start' }));
        expect((await page.locator('#habit-dates-privacy').boundingBox()).y).toBeGreaterThan(nav.height);
        await page.evaluate(() => scrollTo(0, 0));
        if (width === 390 || width === 1440) await page.screenshot({ path: `storage/app/habit-form-checks/create-${width}.png`, fullPage: true });
    }
    await page.setViewportSize({ width: 390, height: 900 });
    await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Tracking', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Tracking', exact: true })).not.toBeVisible();
    writeFileSync('storage/app/habit-form-checks/navigation-after.json', JSON.stringify(samples, null, 2));
});

test('habit form category dialog preserves drafts through validation, cancel, selection and save', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'formcategory');
    await page.goto('/habits/create');
    await page.getByLabel('Habit name', { exact: true }).fill('Drink water');
    await page.getByLabel('Description (optional)', { exact: true }).fill('Keep this entire habit draft');
    await action(page, () => page.getByLabel('Tracking type', { exact: true }).selectOption('count'));
    await page.getByLabel('Goal (optional)', { exact: true }).fill('8');
    await page.getByLabel('Unit (optional)', { exact: true }).fill('glasses');
    await page.getByLabel('Start date', { exact: true }).fill('2026-01-01');
    await page.getByLabel('End date (optional)', { exact: true }).fill('2026-12-31');
    await page.getByLabel('Visibility preference', { exact: true }).selectOption('followers');
    await expect(page.getByText('Changing type clears', { exact: false })).toHaveCount(0);
    const trigger = page.getByRole('button', { name: 'Create a category', exact: true });
    await action(page, () => trigger.click());
    const dialog = page.getByRole('dialog', { name: 'Create a category', exact: true });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Category name', { exact: true }).fill('---');
    await action(page, () => dialog.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await expect(dialog.getByLabel('Category name', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await expect(page.getByLabel('Habit name', { exact: true })).toHaveValue('Drink water');
    await action(page, () => trigger.click());
    await dialog.getByLabel('Category name', { exact: true }).fill('Water breaks');
    await action(page, () => dialog.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await expect(dialog.getByRole('region', { name: 'Category recommendations' })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 900 });
    await page.screenshot({ path: 'storage/app/habit-form-checks/category-390.png' });
    await action(page, () => dialog.getByRole('button', { name: 'Create my category', exact: true }).click());
    await expect(dialog).not.toBeVisible();
    await expect(page.getByLabel('Category', { exact: true }).locator('option:checked')).toHaveText('Water breaks');
    for (const [label, value] of [['Habit name', 'Drink water'], ['Description (optional)', 'Keep this entire habit draft'], ['Goal (optional)', '8'], ['Unit (optional)', 'glasses'], ['Start date', '2026-01-01'], ['End date (optional)', '2026-12-31'], ['Visibility preference', 'followers']]) {
        await expect(page.getByLabel(label, { exact: true })).toHaveValue(value);
    }
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(/\/habits\/\d+$/);
    const id = page.url().split('/').pop();
    await page.goto(`/habits/${id}/edit`);
    await expect(page.getByText('Changing type clears', { exact: false })).toBeVisible();
    await page.getByLabel('Habit name', { exact: true }).fill('Water with meals');
    await action(page, () => trigger.click());
    await dialog.getByLabel('Category name', { exact: true }).fill('Water breaks');
    await action(page, () => dialog.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await action(page, () => dialog.getByRole('button', { name: 'Use Water breaks', exact: true }).click());
    await expect(dialog).not.toBeVisible();
    await expect(page.getByLabel('Habit name', { exact: true })).toHaveValue('Water with meals');
    await page.getByText('Learn about visibility', { exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Sharing includes historical activity.', { exact: false })).toBeVisible();
    await page.getByLabel('Habit name', { exact: true }).fill('');
    await action(page, () => page.getByRole('button', { name: 'Save habit', exact: true }).click());
    await expect(page.getByRole('alert').filter({ hasText: 'Please review the highlighted fields.' })).toBeFocused();
    expect((await page.getByRole('alert').filter({ hasText: 'Please review the highlighted fields.' }).boundingBox()).y).toBeGreaterThan(57);
    await expect(page.getByLabel('Description (optional)', { exact: true })).toHaveValue('Keep this entire habit draft');
    await page.getByLabel('Habit name', { exact: true }).fill('Water with meals');
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    expect(errors).toEqual([]);
});

test('habit form unsaved draft survives cancelled navigation and browser Back', async ({ page }) => {
    await register(page, 'formdraft');
    await page.goto('/habits');
    await page.getByRole('link', { name: 'Create a habit', exact: true }).click();
    await page.getByLabel('Habit name', { exact: true }).fill('Unfinished habit');
    await Promise.all([
        page.waitForEvent('dialog').then(dialog => dialog.dismiss()),
        page.getByRole('link', { name: 'Cancel', exact: true }).click(),
    ]);
    await expect(page).toHaveURL(/\/habits\/create$/);
    await expect(page.getByLabel('Habit name', { exact: true })).toHaveValue('Unfinished habit');
    await Promise.all([
        page.waitForEvent('dialog').then(dialog => dialog.dismiss()),
        page.evaluate(() => history.back()),
    ]);
    await expect(page).toHaveURL(/\/habits\/create$/);
    await expect(page.getByLabel('Habit name', { exact: true })).toHaveValue('Unfinished habit');
    await Promise.all([
        page.waitForEvent('dialog').then(dialog => dialog.accept()),
        page.evaluate(() => history.back()),
    ]);
    await expect(page).toHaveURL(/\/habits$/);
});
