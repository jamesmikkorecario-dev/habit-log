import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { action, register, createHabit } from './support.js';

mkdirSync('storage/app/habits-design-checks', { recursive: true });

async function reviewLayouts(page, name) {
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(() => window.scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const heading = await page.locator('main h1').boundingBox();
        expect(heading.x).toBe((width - Math.min(width, 1280)) / 2 + (width < 640 ? 16 : 24));
        if (await page.locator('[data-habit-configuration]').count()) {
            const basics = await page.locator('section[aria-labelledby="habit-basics"]').boundingBox();
            const tracking = await page.locator('section[aria-labelledby="habit-tracking"]').boundingBox();
            const dates = await page.locator('section[aria-labelledby="habit-dates-privacy"]').boundingBox();
            const actions = await page.locator('[data-habit-actions]').boundingBox();
            expect(tracking.x).toBe(basics.x);
            expect(tracking.y).toBeGreaterThanOrEqual(basics.y + basics.height);
            if (width >= 1024) {
                expect(dates.x).toBeGreaterThan(basics.x + basics.width);
                expect(dates.y).toBe(basics.y);
            } else {
                expect(dates.x).toBe(basics.x);
                expect(dates.y).toBeGreaterThanOrEqual(tracking.y + tracking.height);
            }
            expect(actions.y).toBeGreaterThanOrEqual(Math.max(tracking.y + tracking.height, dates.y + dates.height));
            for (const input of await page.locator('main input[type="number"]').all()) {
                expect((await input.boundingBox()).width).toBeLessThanOrEqual(384);
            }
        }
        if (width === 390 || width === 1440) {
            await page.screenshot({ path: `storage/app/habits-design-checks/${name}-${width}.png`, fullPage: true });
        }
    }
}

test('habits design distinguishes empty views and keeps category and list state in the URL', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'habitsempty');
    await page.goto('/habits');
    await expect(page.getByRole('heading', { name: 'Your first habit starts here.', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Create your first habit', exact: true })).toHaveAttribute('href', /\/habits\/create$/);
    await reviewLayouts(page, 'empty');
    for (const [tab, text] of [['Archived', 'No archived habits yet.'], ['Trash', 'Your trash is empty.']]) {
        await action(page, () => page.getByRole('button', { name: tab, exact: true }).click());
        await expect(page.getByRole('button', { name: tab, exact: true })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('heading', { name: text, exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Create your first habit', exact: true })).toHaveCount(0);
        await expect(page).toHaveURL(new RegExp(`tab=${tab.toLowerCase()}`));
    }
    await action(page, () => page.getByLabel('Filter by category').selectOption({ index: 1 }));
    await expect(page.getByRole('heading', { name: 'No habits found in this category.', exact: true })).toBeVisible();
    await expect(page).toHaveURL(/category=\d+/);
    await page.reload();
    await expect(page.getByRole('button', { name: 'Trash', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await action(page, () => page.getByRole('button', { name: 'Show all categories', exact: true }).click());
    await expect(page.getByLabel('Filter by category')).toHaveValue('');
    await expect(page.getByRole('heading', { name: 'Your trash is empty.', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Create a habit', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Create a habit', exact: true })).toBeVisible();
    await action(page, () => page.getByLabel('Tracking type', { exact: true }).selectOption('checkpoint'));
    await action(page, () => page.getByRole('button', { name: 'Add checkpoint', exact: true }).click());
    await reviewLayouts(page, 'create');
    expect(errors).toEqual([]);
});

test('habits design retains all five configurations, edit validation, and responsive populated rows', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'habitsdesign');
    const ids = {};
    for (const type of ['boolean', 'count', 'duration', 'checkpoint', 'avoidance']) {
        ids[type] = await createHabit(page, type, `Practice ${type}`);
        await page.goto(`/habits/${ids[type]}/edit`);
        await expect(page.getByLabel('Tracking type', { exact: true })).toHaveValue(type);
        if (['count', 'duration', 'avoidance'].includes(type)) {
            await page.getByLabel(type === 'avoidance' ? 'Maximum (optional)' : 'Goal (optional)', { exact: true }).fill(type === 'avoidance' ? '0' : '30');
            if (type !== 'duration') await page.getByLabel('Unit (optional)', { exact: true }).fill(type === 'count' ? 'pages' : 'times');
        }
        if (type === 'checkpoint') {
            await action(page, () => page.getByRole('button', { name: 'Add checkpoint', exact: true }).click());
            await page.getByLabel('Checkpoint 2', { exact: true }).fill('Reflect');
            await action(page, () => page.getByRole('button', { name: 'Move checkpoint 2 up', exact: true }).click());
            await expect(page.getByLabel('Checkpoint 1', { exact: true })).toHaveValue('Reflect');
            await expect(page.getByLabel('Checkpoint 1', { exact: true })).toBeFocused();
        }
        await reviewLayouts(page, `edit-${type}`);
        await page.getByRole('button', { name: 'Save habit', exact: true }).click();
        await page.waitForURL(new RegExp(`/habits/${ids[type]}$`));
    }
    await page.goto(`/habits/${ids.count}/edit`);
    await page.getByLabel('Habit name', { exact: true }).fill('');
    await page.getByLabel('Description (optional)', { exact: true }).fill('Keep this draft');
    await action(page, () => page.getByRole('button', { name: 'Save habit', exact: true }).click());
    await expect(page.getByLabel('Habit name', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Description (optional)', { exact: true })).toHaveValue('Keep this draft');
    await expect(page.getByLabel('Goal (optional)', { exact: true })).toHaveValue('30');
    await page.getByLabel('Habit name', { exact: true }).fill('Read'.repeat(30));
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${ids.count}$`));
    await page.goto('/habits');
    await expect(page.locator('[data-habit-card]')).toHaveCount(5);
    for (const text of ['0 times', '30 pages', '30 minutes', '2 checkpoints', 'Yes or no']) {
        await expect(page.locator('[data-habit-card]').getByText(text, { exact: true })).toBeVisible();
    }
    await reviewLayouts(page, 'populated');
    for (const name of ['Active', 'Archived', 'Trash']) {
        const bounds = await page.getByRole('button', { name, exact: true }).boundingBox();
        expect(bounds.height).toBeGreaterThanOrEqual(44);
    }
    const filterBounds = await page.getByLabel('Filter by category').boundingBox();
    expect(filterBounds.width).toBeLessThanOrEqual(224);
    await action(page, () => page.getByLabel('Filter by category').selectOption({ index: 2 }));
    await expect(page.getByRole('heading', { name: 'No habits found in this category.', exact: true })).toBeVisible();
    await expect(page.getByText('Your first habit starts here.', { exact: true })).toHaveCount(0);
    await action(page, () => page.getByRole('button', { name: 'Show all categories', exact: true }).click());
    await expect(page.locator('[data-habit-card]')).toHaveCount(5);
    expect(errors).toEqual([]);
});
