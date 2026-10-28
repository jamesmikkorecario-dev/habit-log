import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { action } from './support.js';

const directory = 'storage/app/dashboard-cleanup';
mkdirSync(directory, { recursive: true });

async function login(page, empty = false) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(empty ? 'heatmap-empty@example.test' : 'heatmap-review@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Browser-test-123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/dashboard?year=2024');
}

async function accessible(page, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
    writeFileSync(`${directory}/${name}-accessibility.txt`, await page.locator('main').ariaSnapshot());
    await page.screenshot({ path: `${directory}/${name}.png`, fullPage: !/^(numbers|status)-/.test(name) });
}

test('compact dashboard dialogs retain calculations, counts, status meanings and keyboard focus', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await expect(page.locator('section[aria-label="Overall profile activity"] details')).toHaveCount(0);
    await expect(page.locator('[data-focus-key^="list-"]')).toHaveCount(0);
    const calendarBefore = await page.locator('[data-date]').evaluateAll(cells => cells.map(cell => [cell.dataset.date, cell.dataset.state, cell.className, cell.getAttribute('aria-label')]));
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await accessible(page, `populated-${width}`);
        for (const name of ['How these numbers work', 'Status key']) {
            const trigger = page.getByRole('button', { name, exact: true });
            await trigger.focus();
            await page.keyboard.press('Enter');
            const dialog = page.getByRole('dialog', { name, exact: true });
            await expect(dialog).toBeVisible();
            if (name === 'How these numbers work') {
                await expect(dialog).toContainText('Consistency averages performance, including partial progress.');
                await expect(dialog).toContainText('Streaks use all history');
                await expect(dialog).toContainText('1 habits included');
                for (const count of ['scored days', 'N/A habit-days', 'unlogged habit-days', 'pending', 'unavailable']) await expect(dialog).toContainText(count);
            } else {
                for (const state of ['Not logged', 'Pending', 'N/A', 'Unavailable', 'Inactive / Future']) await expect(dialog.getByText(state, { exact: true })).toBeVisible();
                await expect(dialog).toContainText('Zero is recorded; not logged means a missing entry.');
            }
            // Traverse and wrap both ends; the framework's hidden focus
            // placeholder must never swallow a Tab key.
            const controls = dialog.getByRole('button');
            await expect(controls).toHaveCount(2);
            await expect(controls.first()).toBeFocused();
            for (const [key, target] of [['Tab', 1], ['Tab', 0], ['Shift+Tab', 1], ['Shift+Tab', 0]]) {
                await page.keyboard.press(key);
                await expect(controls.nth(target)).toBeFocused();
            }
            await accessible(page, `${name === 'Status key' ? 'status' : 'numbers'}-${width}`);
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(trigger).toBeFocused();
        }
    }
    expect(await page.locator('[data-date]').evaluateAll(cells => cells.map(cell => [cell.dataset.date, cell.dataset.state, cell.className, cell.getAttribute('aria-label')]))).toEqual(calendarBefore);
    await expect(page.locator('body')).not.toContainText('PRIVATE-HEATMAP-NOTE');
    await expect(page.locator('body')).not.toContainText('PRIVATE-HEATMAP-REASON');
    expect(errors).toEqual([]);
});

test('date browser reaches every kind of day and preserves details, leap dates, year navigation and daily links', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 1000 });
    const input = page.getByLabel('Date to view', { exact: true });
    const samples = [
        ['2024-01-01', 'No eligible data', 'No performance score'],
        ['2024-01-08', 'Recorded performance', '0.0% performance'],
        ['2024-01-13', 'N/A', 'No performance score'],
        ['2024-01-14', 'Not logged', '0.0% performance'],
        ['2024-01-15', 'Data unavailable', 'No performance score'],
        ['2024-02-29', 'Fully successful', '100.0% performance'],
    ];
    for (const [date, status, performance] of samples) {
        await input.fill(date);
        await input.focus();
        await action(page, () => page.keyboard.press('Enter'));
        const heading = page.getByRole('heading', { name: `${date} · ${status}`, exact: true });
        await expect(heading).toBeFocused();
        await expect(heading.locator('..')).toContainText(performance);
        if (['No eligible data', 'Data unavailable'].includes(status)) {
            await expect(page.getByRole('link', { name: 'Open daily overview', exact: true })).toHaveCount(0);
        } else {
            await expect(page.getByRole('link', { name: 'Open daily overview', exact: true })).toHaveAttribute('href', new RegExp(`/tracking\\?date=${date}$`));
        }
        await page.keyboard.press('Escape');
        await expect(input).toBeFocused();
    }
    await input.fill('2024-01-31');
    await action(page, () => page.getByRole('button', { name: 'Next month', exact: true }).click());
    await expect(input).toHaveValue('2024-02-29');
    await expect(page.getByRole('status').filter({ hasText: 'Date to view:' })).toContainText('2024-02-29');
    await input.fill('2024-01-15');
    await action(page, () => page.getByRole('button', { name: 'Previous month', exact: true }).click());
    await expect(input).toHaveValue('2023-12-15');
    await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
    await expect(page.getByLabel('Year', { exact: true })).toHaveValue('2023');
    await expect(page.locator('[data-date]')).toHaveCount(365);
    await page.getByRole('button', { name: 'Close details', exact: true }).click();
    await input.fill('2024-02-29');
    await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
    await expect(page.getByLabel('Year', { exact: true })).toHaveValue('2024');
    await expect(page.locator('[data-date]')).toHaveCount(366);
    await accessible(page, 'selected-leap-390');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await accessible(page, 'selected-leap-1440');
    // Invalid dates must not replace the current selection or open another year.
    await input.fill(`${new Date().getUTCFullYear() + 1}-01-01`);
    await input.evaluate(el => el.form.noValidate = true);
    await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
    await expect(page.getByText(/date field must be a date before or equal to/i)).toBeVisible();
    await expect(page.getByLabel('Year', { exact: true })).toHaveValue('2024');
    await page.getByRole('link', { name: 'Open daily overview', exact: true }).click();
    await expect(page).toHaveURL(/\/tracking\?date=2024-02-29$/);
});

test('empty years remain compact and expose every date including pending and future states', async ({ page }) => {
    await login(page, true);
    const input = page.getByLabel('Date to view', { exact: true });
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect(page.locator('[data-date]')).toHaveCount(366);
        await expect(page.getByRole('button', { name: /No eligible data/ })).toHaveCount(366);
        await expect(page.locator('[data-focus-key^="list-"]')).toHaveCount(0);
        await accessible(page, `empty-${width}`);
    }
    await input.fill('2024-12-31');
    await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
    await expect(page.getByRole('heading', { name: '2024-12-31 · No eligible data', exact: true })).toBeFocused();
    await action(page, () => page.getByRole('button', { name: 'Current year', exact: true }).click());
    const year = new Date().getUTCFullYear();
    await expect(input).toHaveValue(`${year}-01-01`);
    // The populated fixture has an eligible habit and no entry today.
    await page.context().clearCookies();
    await login(page);
    const today = new Date().toISOString().slice(0, 10);
    await input.fill(today);
    await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
    await expect(page.getByRole('heading', { name: `${today} · Pending`, exact: true })).toBeFocused();
    if (today !== `${year}-12-31`) {
        await input.fill(`${year}-12-31`);
        await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
        await expect(page.getByRole('heading', { name: `${year}-12-31 · Future date`, exact: true })).toBeFocused();
        await expect(page.getByRole('link', { name: 'Open daily overview', exact: true })).toHaveCount(0);
    }
});
