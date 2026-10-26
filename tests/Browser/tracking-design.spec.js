import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { action, register, createHabit } from './support.js';

mkdirSync('storage/app/tracking-design-checks', { recursive: true });

async function checkLayout(page, state) {
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const input = await page.getByLabel('Tracking date', { exact: true }).boundingBox();
        expect(input.x).toBeGreaterThanOrEqual(0);
        expect(input.x + input.width).toBeLessThanOrEqual(width);
        for (const name of ['Previous day', 'Today', 'Next day', 'Go to date']) {
            const bounds = await page.getByRole('button', { name, exact: true }).boundingBox();
            expect(bounds.height).toBeGreaterThanOrEqual(44);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
        }
        if (width === 390 || width === 1440) {
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.screenshot({ path: `storage/app/tracking-design-checks/${state}-${width}.png`, fullPage: true });
        }
    }
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations).toEqual([]);
}

test('daily tracking onboarding, explicit date selection and keyboard boundaries', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'trackingempty');
    await page.goto('/tracking');
    const today = new Date().toISOString().slice(0, 10);
    await expect(page.getByRole('heading', { name: 'Nothing to track just yet.' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Create your first habit', exact: true })).toHaveAttribute('href', /\/habits\/create$/);
    await expect(page.getByRole('button', { name: 'Next day', exact: true })).toBeDisabled();
    await expect(page.getByLabel('Tracking date', { exact: true })).toHaveAttribute('max', today);
    await checkLayout(page, 'empty');
    await page.getByLabel('Tracking date', { exact: true }).fill('2024-02-29');
    await expect(page.locator('[data-tracking-date]')).toHaveAttribute('datetime', today);
    await action(page, () => page.getByLabel('Tracking date', { exact: true }).press('Enter'));
    await expect(page.locator('[data-tracking-date]')).toHaveAttribute('datetime', '2024-02-29');
    await expect(page).toHaveURL(/date=2024-02-29/);
    await page.getByRole('button', { name: 'Next day', exact: true }).focus();
    await action(page, () => page.keyboard.press('Enter'));
    await expect(page.locator('[data-tracking-date]')).toHaveAttribute('datetime', '2024-03-01');
    await action(page, () => page.getByRole('button', { name: 'Previous day', exact: true }).click());
    await expect(page.locator('[data-tracking-date]')).toHaveAttribute('datetime', '2024-02-29');
    await page.getByLabel('Tracking date', { exact: true }).fill('1000-01-01');
    await action(page, () => page.getByRole('button', { name: 'Go to date', exact: true }).click());
    await expect(page.getByRole('button', { name: 'Previous day', exact: true })).toBeDisabled();
    await action(page, () => page.getByRole('button', { name: 'Today', exact: true }).click());
    await expect(page.locator('[data-tracking-date]')).toHaveAttribute('datetime', today);
    await page.getByLabel('Tracking date', { exact: true }).fill('9999-12-31');
    expect(await page.getByLabel('Tracking date', { exact: true }).evaluate(el => el.validity.rangeOverflow)).toBe(true);
    await page.getByRole('button', { name: 'Go to date', exact: true }).click();
    await expect(page.locator('[data-tracking-date]')).toHaveAttribute('datetime', today);
    await page.getByRole('link', { name: 'Create your first habit', exact: true }).click();
    await expect(page).toHaveURL(/\/habits\/create$/);
    expect(errors).toEqual([]);
});

test('daily tracking cards preserve all five editors, recorded values and inactive dates', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'trackingfull');
    const today = new Date().toISOString().slice(0, 10);
    const habits = [];
    for (const type of ['boolean', 'count', 'duration', 'checkpoint', 'avoidance']) {
        habits.push({ id: await createHabit(page, type, `Daily ${type}`), type });
    }
    await page.goto('/tracking');
    await expect(page.locator('[data-tracking-habit]')).toHaveCount(5);
    await expect(page.getByText('Not logged · Pending today', { exact: true })).toHaveCount(5);
    for (const { id, type } of habits) {
        await page.getByRole('link', { name: `Record activity for Daily ${type}`, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/habits/${id}/entries/${today}$`));
        if (type === 'checkpoint') {
            await page.getByRole('checkbox', { name: 'Read', exact: true }).check();
        } else {
            await action(page, () => page.getByLabel(['count', 'duration', 'avoidance'].includes(type) ? 'Your assessment' : 'Your outcome', { exact: true }).selectOption(type === 'count' || type === 'duration' ? 'partial' : 'completed'));
            if (type !== 'boolean') await page.locator('#entry-value').fill(type === 'avoidance' ? '0' : '5');
        }
        await page.locator('#entry-note').fill('PRIVATE-TRACKING-CANARY');
        await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
        await page.getByRole('link', { name: 'Daily overview', exact: true }).click();
        await expect(page.locator(`[data-tracking-habit="${id}"]`)).toContainText('Recorded outcome:');
        await expect(page.getByRole('link', { name: `Edit entry for Daily ${type}`, exact: true })).toBeVisible();
        await expect(page.locator('main')).not.toContainText('PRIVATE-TRACKING-CANARY');
    }
    await expect(page.locator(`[data-tracking-habit="${habits[3].id}"]`)).toContainText('1 of 1 checkpoints');
    await expect(page.locator(`[data-tracking-habit="${habits[2].id}"]`)).toContainText('5 minutes');
    await expect(page.locator(`[data-tracking-habit="${habits[4].id}"]`)).toContainText('0');
    await checkLayout(page, 'populated');
    await page.getByRole('link', { name: 'Edit entry for Daily count', exact: true }).click();
    await expect(page.locator('#entry-value')).toHaveValue('5');
    await action(page, () => page.getByLabel('Your assessment', { exact: true }).selectOption('not_applicable'));
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await page.getByRole('link', { name: 'Daily overview', exact: true }).click();
    await expect(page.locator(`[data-tracking-habit="${habits[1].id}"]`)).toContainText('Recorded outcome: Not applicable');
    await page.getByLabel('Tracking date', { exact: true }).fill('1000-01-01');
    await action(page, () => page.getByRole('button', { name: 'Go to date', exact: true }).click());
    await expect(page.getByText('No habits on this page can be recorded', { exact: false })).toBeVisible();
    await expect(page.getByRole('link', { name: /^Record activity for/ })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Create your first habit', exact: true })).toHaveCount(0);
    await checkLayout(page, 'inactive');
    expect(errors).toEqual([]);
});
