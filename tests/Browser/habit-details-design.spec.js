import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { action, createHabit, register } from './support.js';

mkdirSync('storage/app/habit-details-checks', { recursive: true });
const today = () => new Date().toISOString().slice(0, 10);
const dayOffset = (offset) => {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() + offset);
    return date.toISOString().slice(0, 10);
};

async function saveConfiguration(page, id, edit) {
    await page.goto(`/habits/${id}/edit`);
    await edit();
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
}

async function layouts(page, name) {
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const heading = await page.locator('main h1').boundingBox();
        expect(heading.x).toBe((width - Math.min(width, 1280)) / 2 + (width < 640 ? 16 : 24));
        const information = await page.getByRole('region', { name: 'Habit information', exact: true }).boundingBox();
        const setup = await page.locator('[data-habit-detail-information] > div').boundingBox();
        if (width >= 1024) {
            expect(information.x).toBeGreaterThan(setup.x + setup.width);
            expect(information.y).toBe(setup.y);
        } else {
            expect(information.y).toBeGreaterThanOrEqual(setup.y + setup.height);
        }
        for (const button of await page.locator('[data-habit-detail-actions] a').all()) {
            const box = await button.boundingBox();
            expect(box.height).toBeGreaterThanOrEqual(44);
            expect(box.x).toBeGreaterThanOrEqual(16);
            expect(box.x + box.width).toBeLessThanOrEqual(width - 16);
        }
        const calendar = page.locator('[data-calendar-scroll]');
        await calendar.evaluate(el => el.scrollLeft = el.scrollWidth);
        const calendarBox = await calendar.boundingBox();
        const december = await page.locator('[data-date$="-12-31"]').boundingBox();
        expect(december.x + december.width).toBeLessThanOrEqual(calendarBox.x + calendarBox.width);
        expect(december.x).toBeGreaterThanOrEqual(calendarBox.x);
        const cell = await page.locator('[data-date]').first().boundingBox();
        expect(cell.width).toBeGreaterThanOrEqual(22);
        await calendar.evaluate(el => el.scrollLeft = 0);
        if (width === 390 || width === 1440) {
            await page.screenshot({ path: `storage/app/habit-details-checks/${name}-${width}.png`, fullPage: true });
        }
    }
}

test('habit details separates current and upcoming setup while preserving analytics and navigation', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'details');
    const name = 'A'.repeat(100);
    const id = await createHabit(page, 'count', name);
    await saveConfiguration(page, id, async () => {
        await page.getByLabel('Description (optional)', { exact: true }).fill(`A readable description.\n${'LongUnbrokenDescription'.repeat(30)}`);
        await page.getByLabel('Goal (optional)', { exact: true }).fill('10');
        await page.getByLabel('Unit (optional)', { exact: true }).fill('pages');
    });
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(name);
    await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-path', '~/habits/details');
    await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-state', 'complete');
    await expect(page.getByRole('region', { name: 'Current tracking setup', exact: true })).toContainText('10 pages');
    await expect(page.getByRole('heading', { name: 'Upcoming tracking change', exact: true })).toHaveCount(0);
    for (const [label, href] of [['Edit habit', `/habits/${id}/edit`], ['Record today', `/habits/${id}/entries/${today()}`], ['Entry history', `/habits/${id}/entries`], ['Back to habits', '/habits']]) {
        await expect(page.getByRole('link', { name: label, exact: true })).toHaveCount(1);
        await expect(page.getByRole('link', { name: label, exact: true })).toHaveAttribute('href', `http://127.0.0.1:8017${href}`);
    }
    await page.getByRole('link', { name: 'Record today', exact: true }).click();
    await action(page, () => page.getByLabel('Your outcome', { exact: true }).selectOption('partial'));
    await page.locator('#entry-value').fill('5');
    await page.locator('#entry-note').fill('PRIVATE DETAIL NOTE');
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await saveConfiguration(page, id, () => page.getByLabel('Goal (optional)', { exact: true }).fill('20'));
    await expect(page.getByRole('region', { name: 'Current tracking setup', exact: true })).toContainText('10 pages');
    await expect(page.getByRole('region', { name: 'Upcoming tracking change', exact: true })).toContainText(`Takes effect on ${dayOffset(1)}`);
    await expect(page.getByRole('region', { name: 'Upcoming tracking change', exact: true })).toContainText('20 pages');
    await expect(page.getByText('Latest requested configuration', { exact: true })).toHaveCount(0);
    await expect(page.locator('main')).not.toContainText('PRIVATE DETAIL NOTE');
    await expect(page.locator(`[data-date="${today()}"]`)).toHaveAttribute('aria-label', /50\.0% performance/);
    for (const label of ['Current streak', 'Longest streak', '30-day consistency', 'Yearly consistency', 'Performance intensity']) {
        await expect(page.getByText(label, { exact: true }).first()).toBeVisible();
    }
    await layouts(page, 'pending');
    await page.getByText('How these numbers work', { exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText(/Consistency averages performance, including partial progress/)).toBeVisible();
    await page.getByRole('button', { name: 'Previous year', exact: true }).focus();
    await action(page, () => page.keyboard.press('Enter'));
    await expect(page.locator('[data-date]').first()).toHaveAttribute('data-date', `${new Date().getUTCFullYear() - 1}-01-01`);
    await page.getByLabel('Year', { exact: true }).fill('2024');
    await action(page, () => page.getByRole('button', { name: 'Go to year', exact: true }).click());
    await expect(page.locator('[data-date]')).toHaveCount(366);
    await expect(page.locator('[data-date="2024-02-29"]')).toBeAttached();
    const day = page.locator('[data-date="2024-01-01"]');
    await day.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('[data-date="2024-01-02"]')).toBeFocused();
    await action(page, () => page.keyboard.press('Enter'));
    await expect(page.getByRole('button', { name: 'Close details', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-date="2024-01-02"]')).toBeFocused();
    const accessible = page.locator('summary').filter({ hasText: 'Accessible day list' });
    await accessible.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Page 1 of 19', { exact: true })).toBeVisible();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.getByRole('link', { name: 'Back to habits', exact: true }).click();
    await page.getByRole('link', { name, exact: true }).click();
    await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-state', 'typing');
    await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-state', 'complete');
    await action(page, () => page.getByRole('button', { name: 'Previous year', exact: true }).click());
    await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-state', 'complete');
    await page.getByRole('link', { name: 'Entry history', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}/entries$`));
    expect(errors).toEqual([]);
});

test('habit details renders all five types and distinguishes scheduled and ended habits', async ({ page }) => {
    await register(page, 'detailtypes');
    for (const type of ['boolean', 'count', 'duration', 'checkpoint', 'avoidance']) {
        const id = await createHabit(page, type, `Details ${type}`);
        const setup = page.getByRole('region', { name: 'Current tracking setup', exact: true });
        await expect(setup).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Upcoming tracking change', exact: true })).toHaveCount(0);
        if (type === 'checkpoint') await expect(setup.getByRole('listitem')).toHaveText(['Read']);
        if (['count', 'duration', 'avoidance'].includes(type)) await expect(setup).toContainText('No numeric goal');
        if (type === 'duration') await expect(setup).toContainText('minutes');
        if (type === 'boolean') await expect(setup).not.toContainText('No numeric goal');
        if (type === 'checkpoint') {
            await saveConfiguration(page, id, async () => {
                await page.getByLabel('Checkpoint 1', { exact: true }).fill('Checkpoint'.repeat(8));
                await page.getByLabel('Start date', { exact: true }).fill(dayOffset(3));
            });
            await expect(page.getByRole('region', { name: 'Scheduled tracking setup', exact: true })).toContainText('Checkpoint'.repeat(8));
            await expect(page.getByRole('heading', { name: 'Current tracking setup', exact: true })).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Record today', exact: true })).toHaveCount(0);
            await expect(page.locator('[data-recording-unavailable]')).toContainText('Outside this habit’s active dates.');
            await layouts(page, 'scheduled');
        }
        if (type === 'duration') {
            await saveConfiguration(page, id, async () => {
                await page.getByLabel('Start date', { exact: true }).fill(dayOffset(-10));
                await page.getByLabel('End date (optional)', { exact: true }).fill(dayOffset(-1));
            });
            await expect(setup).toContainText(dayOffset(-1));
            await expect(page.getByRole('link', { name: 'Record today', exact: true })).toHaveCount(0);
            await expect(page.locator('[data-recording-unavailable]')).toBeVisible();
        }
    }
});


test('habit details preserves an initial zero avoidance maximum', async ({ page }) => {
    await register(page, 'detailzero');
    await page.goto('/habits/create');
    await page.getByLabel('Habit name', { exact: true }).fill('Zero maximum');
    await page.getByLabel('Category', { exact: true }).selectOption({ index: 1 });
    await action(page, () => page.getByLabel('Tracking type', { exact: true }).selectOption('avoidance'));
    await page.getByLabel('Maximum (optional)', { exact: true }).fill('0');
    await page.getByLabel('Unit (optional)', { exact: true }).fill('times');
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(/\/habits\/\d+$/);
    const setup = page.getByRole('region', { name: 'Current tracking setup', exact: true });
    await expect(setup).toContainText('0 times');
    await expect(setup).toContainText('A zero maximum means abstinence.');
    await expect(page.getByRole('heading', { name: 'Upcoming tracking change', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Saved settings differ', exact: true })).toHaveCount(0);
});
