import { test, expect } from './fixtures.js';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { action, createHabit, register } from './support.js';

const directory = 'storage/app/heatmap-refinement';
mkdirSync(directory, { recursive: true });

async function signIn(page, empty = false) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(empty ? 'heatmap-empty@example.test' : 'heatmap-review@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Browser-test-123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('dashboard calendar fills available width with square aligned cells and a mobile minimum', async ({ page }) => {
    const output = 'storage/app/heatmap-width';
    mkdirSync(output, { recursive: true });
    await signIn(page);
    const measurements = [];
    // A full calendar needs partial boundary weeks too: ordinary years have
    // 53 columns; a leap year starting Sunday (2012) needs 54, never just 52.
    for (const year of [2023, 2024, 2012]) {
        await page.goto(`/dashboard?year=${year}`);
        for (const width of [375, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 1600 });
            const scroll = page.locator('[data-calendar-scroll]');
            await scroll.evaluate(el => el.scrollLeft = 0);
            const geometry = await scroll.evaluate(el => {
                const rect = node => {
                    const { x, y, width, height } = node.getBoundingClientRect();
                    return { x, y, width, height };
                };
                const css = getComputedStyle(el);
                const grid = el.querySelector('.calendar-days');
                return {
                    available: el.clientWidth - parseFloat(css.paddingLeft) - parseFloat(css.paddingRight),
                    columns: getComputedStyle(grid).gridTemplateColumns.split(' ').length,
                    grid: rect(grid),
                    gap: parseFloat(getComputedStyle(grid).columnGap),
                    scrollWidth: el.scrollWidth, clientWidth: el.clientWidth,
                    pageOverflow: document.documentElement.scrollWidth > innerWidth,
                    days: [...grid.querySelectorAll('[data-date]')].map(day => ({ ...rect(day), date: day.dataset.date })),
                    months: [...el.querySelectorAll('[data-month]')].map(month => ({ ...rect(month), date: `${month.dataset.month}-01` })),
                    weekdays: [...el.closest('.activity-calendar').querySelector('.calendar-weekdays').children].map(rect),
                };
            });
            const offset = (new Date(`${year}-01-01T00:00:00Z`).getUTCDay() + 6) % 7;
            const total = (Date.UTC(year + 1, 0, 1) - Date.UTC(year, 0, 1)) / 86400000;
            const columns = Math.ceil((offset + total) / 7);
            const size = Math.max(16, (geometry.available - (columns - 1) * 4) / columns);
            expect(geometry.columns).toBe(columns);
            expect(geometry.days).toHaveLength(total);
            expect(geometry.gap).toBe(4);
            expect(geometry.pageOverflow).toBe(false);
            expect(Math.abs(geometry.grid.width - (columns * size + (columns - 1) * 4))).toBeLessThan(1);
            for (const [index, day] of geometry.days.entries()) {
                expect(Math.abs(day.width - size)).toBeLessThan(0.05);
                expect(Math.abs(day.width - day.height)).toBeLessThan(0.02);
                const row = (offset + index) % 7;
                const column = Math.floor((offset + index) / 7);
                expect(Math.abs(day.y - geometry.weekdays[row].y)).toBeLessThan(0.05);
                expect(Math.abs(day.x - geometry.grid.x - column * (size + 4))).toBeLessThan(1);
            }
            for (const month of geometry.months) {
                expect(Math.abs(month.x - geometry.days.find(day => day.date === month.date).x)).toBeLessThan(0.05);
            }
            if (width === 1440) {
                expect(size).toBeGreaterThan(16);
                expect(Math.abs(geometry.grid.width - geometry.available)).toBeLessThan(1);
                expect(geometry.scrollWidth).toBe(geometry.clientWidth);
            } else {
                expect(geometry.scrollWidth).toBeGreaterThan(geometry.clientWidth);
                expect(size).toBe(16);
            }
            await scroll.evaluate(el => el.scrollLeft = el.scrollWidth);
            const last = await page.locator(`[data-date="${year}-12-31"]`).boundingBox();
            const region = await scroll.boundingBox();
            expect(last.x).toBeGreaterThan(region.x);
            expect(last.x + last.width).toBeLessThanOrEqual(region.x + region.width);
            await scroll.evaluate(el => el.scrollLeft = 0);
            measurements.push({ year, viewport: width, columns, cell: geometry.days[0].width, grid: geometry.grid.width, available: geometry.available, overflow: geometry.scrollWidth - geometry.clientWidth });
            if (year === 2024) {
                if (width === 1440 || width === 390) {
                    await page.locator('section[aria-label="Activity history"]').screenshot({ path: `${output}/after-populated-${width}.png` });
                }
                expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
            }
        }
    }
    // Resize the container without changing the viewport: sizing follows the
    // actual available space, including a transition back to internal scrolling.
    await page.locator('.dashboard-activity-history').evaluate(el => el.style.width = '900px');
    await expect.poll(() => page.locator('[data-date]').first().evaluate(el => el.getBoundingClientRect().width)).toBe(16);
    expect(await page.locator('[data-calendar-scroll]').evaluate(el => el.scrollWidth > el.clientWidth)).toBe(true);
    await page.locator('.dashboard-activity-history').evaluate(el => el.style.removeProperty('width'));
    await expect.poll(() => page.locator('[data-date]').first().evaluate(el => el.getBoundingClientRect().width)).toBeGreaterThan(16);
    writeFileSync(`${output}/geometry.json`, JSON.stringify(measurements, null, 2));
});

test('heatmap review captures populated and empty desktop and mobile calendars', async ({ browser }) => {
    for (const empty of [false, true]) {
        const context = await browser.newContext();
        const review = await context.newPage();
        try {
            await signIn(review, empty);
            await review.goto('/dashboard?year=2024');
            await expect(review.locator('[data-date]')).toHaveCount(366);
            expect(await review.locator('[data-date]').allTextContents()).toEqual(Array(366).fill(''));
            for (const width of [1440, 390]) {
                await review.setViewportSize({ width, height: 1600 });
                const history = review.locator('section[aria-label="Activity history"]');
                await history.scrollIntoViewIfNeeded();
                await review.locator('[data-calendar-scroll]').evaluate(el => el.scrollLeft = 0);
                await history.screenshot({ path: `${directory}/after-${empty ? 'empty' : 'populated'}-${width}.png` });
                expect(await review.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                expect((await new AxeBuilder({ page: review }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
            }
        } finally {
            await context.close();
        }
    }
});

test('dashboard previews distinguish scores and special states without exposing private text', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await signIn(page);
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto('/dashboard?year=2024');
    const tooltip = page.getByRole('tooltip');
    const samples = [
        ['2024-01-01', 'inactive', /No eligible data · No performance score/],
        ['2024-01-08', 'recorded', /Recorded performance · 0.0% performance/],
        ['2024-01-09', 'recorded', /20.0% performance/],
        ['2024-01-10', 'recorded', /40.0% performance/],
        ['2024-01-11', 'recorded', /70.0% performance/],
        ['2024-01-12', 'recorded', /Fully successful · 100.0% performance/],
        ['2024-01-13', 'na', /N\/A · No performance score/],
        ['2024-01-14', 'unlogged', /Not logged · 0.0% performance/],
        ['2024-01-15', 'unavailable', /Data unavailable · No performance score/],
    ];
    let updates = 0;
    page.on('request', request => { if (request.method() === 'POST' && request.url().includes('/livewire')) updates++; });
    const treatments = [];
    for (const [date, state, outcome] of samples) {
        const cell = page.locator(`[data-date="${date}"]`);
        await expect(cell).toHaveAttribute('data-state', state);
        await cell.hover();
        await expect(tooltip).toBeVisible();
        await expect(tooltip).toContainText(date);
        await expect(tooltip).toContainText(outcome);
        if (date === '2024-01-10') {
            await page.screenshot({ path: `${directory}/preview-1440.png` });
        }
        await expect(cell).toHaveAttribute('aria-describedby', 'calendar-preview-2024');
        treatments.push(await cell.evaluate(el => {
            const css = getComputedStyle(el);
            return `${css.borderStyle}|${css.borderWidth}|${css.backgroundImage}|${css.backgroundColor}`;
        }));
        // The pointer can enter the preview without losing its content.
        await tooltip.hover();
        await expect(tooltip).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(tooltip).toBeHidden();
    }
    expect(new Set(treatments).size).toBe(samples.length);
    expect(updates).toBe(0); // Previews use only the authorized rendered report.
    const content = await page.content();
    expect(content).not.toContain('PRIVATE-HEATMAP-NOTE');
    expect(content).not.toContain('PRIVATE-HEATMAP-REASON');
    await page.getByText('Calendar key', { exact: true }).click();
    await expect(page.locator('.calendar-state-key')).toHaveAttribute('open', '');
    await expect(page.locator('.calendar-state-key')).toContainText('Zero is recorded; not logged means a missing entry.');
    await page.locator('section[aria-label="Activity history"]').screenshot({ path: `${directory}/calendar-key-1440.png` });
    await page.emulateMedia({ forcedColors: 'active' });
    const patterns = await page.locator('[data-date="2024-01-13"], [data-date="2024-01-15"]').evaluateAll(cells => cells.map(cell => getComputedStyle(cell).backgroundImage));
    expect(patterns.every(pattern => pattern.includes('gradient'))).toBe(true);
    expect(patterns[0]).not.toEqual(patterns[1]);
    await page.emulateMedia({ forcedColors: 'none' });
    expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
    expect(errors).toEqual([]);
});

test('dashboard preview explains a provisional score while another habit is pending', async ({ page }) => {
    await register(page, 'provisional');
    const habit = await createHabit(page, 'boolean', 'Recorded today');
    await createHabit(page, 'boolean', 'Still pending');
    const today = new Date().toISOString().slice(0, 10);
    await page.goto(`/habits/${habit}/entries/${today}`);
    await action(page, () => page.getByLabel('Your outcome', { exact: true }).selectOption('completed'));
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await page.goto('/dashboard');
    const cell = page.locator(`[data-date="${today}"]`);
    await cell.focus();
    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toContainText('Recorded performance · 100.0% performance · Provisional');
    await expect(tooltip).toContainText('1 included');
    await expect(tooltip).toContainText('1 pending');
    await expect(cell).toHaveClass(/heat-band-4/);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('[data-calendar-scroll]').focus();
    await cell.scrollIntoViewIfNeeded();
    await cell.focus();
    await expect(tooltip).toBeVisible();
    await expect.poll(async () => {
        const bounds = await tooltip.boundingBox();
        return bounds !== null && bounds.x >= 0 && bounds.x + bounds.width <= 390;
    }).toBe(true);
    await page.screenshot({ path: `${directory}/preview-390.png` });
});

test('dashboard focus previews, details, year boundaries and day list remain accessible', async ({ page }) => {
    await signIn(page);
    await page.goto('/dashboard?year=2024');
    const tooltip = page.getByRole('tooltip');
    const first = page.locator('[data-date="2024-01-01"]');
    await first.focus();
    await expect(tooltip).toContainText('2024-01-01');
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-date="2024-01-08"]')).toBeFocused();
    await expect(tooltip).toContainText('0.0% performance');
    await page.keyboard.press('Escape');
    await expect(tooltip).toBeHidden();
    await expect(page.locator('[data-date="2024-01-08"]')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(tooltip).toContainText('20.0% performance');
    await action(page, () => page.keyboard.press('Space'));
    await expect(tooltip).toBeHidden();
    await expect(page.getByRole('heading', { name: '2024-01-09 · Recorded performance' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-date="2024-01-09"]')).toBeFocused();
    await expect(tooltip).toBeVisible();
    await page.keyboard.press('End');
    await expect(page.locator('[data-date="2024-12-31"]')).toBeFocused();
    await page.keyboard.press('Home');
    await expect(first).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(tooltip).toBeHidden();

    await action(page, () => page.getByRole('button', { name: 'Current year', exact: true }).click());
    const today = new Date().toISOString().slice(0, 10);
    const year = Number(today.slice(0, 4));
    await expect(page.getByLabel('Year', { exact: true })).toHaveValue(String(year));
    await expect(page.getByRole('button', { name: 'Next year', exact: true })).toBeDisabled();
    const pending = page.locator(`[data-date="${today}"]`);
    await expect(pending).toHaveAttribute('data-state', 'pending');
    await pending.focus();
    await expect(tooltip).toContainText('Pending · No performance score');
    await expect(tooltip).toContainText('1 pending');
    const future = page.locator('[data-date][data-state="future"]').last();
    if (await future.count()) {
        await future.focus();
        await expect(tooltip).toContainText('Future date · No performance score');
        await action(page, () => page.keyboard.press('Enter'));
        await expect(page.getByRole('link', { name: 'Open daily overview', exact: true })).toHaveCount(0);
        await page.getByRole('button', { name: 'Close details' }).click();
    }
    await page.getByLabel('Year', { exact: true }).fill(String(year + 1));
    // Exercise the server boundary, not just the native numeric max check.
    await page.getByLabel('Year', { exact: true }).evaluate(input => input.form.noValidate = true);
    await action(page, () => page.getByRole('button', { name: 'Go to year', exact: true }).click());
    await expect(page.locator('[data-calendar-scroll]')).toHaveAttribute('aria-label', `${year} activity calendar`);
    await expect(page.getByText(/year input field must not be greater than/i).first()).toBeVisible();
    await page.getByLabel('Year', { exact: true }).fill('2024');
    await action(page, () => page.getByRole('button', { name: 'Go to year', exact: true }).click());
    await expect(tooltip).toBeHidden();
    await expect.poll(() => page.locator('[data-calendar-scroll]').evaluate(el => el.scrollLeft)).toBe(0);
    await page.getByText('Accessible day list', { exact: true }).click();
    await action(page, () => page.getByRole('button', { name: 'Next days', exact: true }).click());
    await expect(page.getByText('Page 2 of 19')).toBeVisible();
    const row = page.locator('[data-focus-key^="list-"]').first();
    await action(page, () => row.click());
    await page.getByRole('button', { name: 'Close details' }).click();
    await expect(row).toBeFocused();
});

test('dashboard calendar supports touch details and horizontal scrolling at 390px', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, reducedMotion: 'reduce' });
    const page = await context.newPage();
    try {
        await signIn(page);
        await page.goto('/dashboard?year=2024');
        const cell = page.locator('[data-date="2024-01-10"]');
        await action(page, () => cell.tap());
        await expect(page.getByRole('heading', { name: '2024-01-10 · Recorded performance' })).toBeFocused();
        await page.getByRole('button', { name: 'Close details' }).tap();
        await expect(cell).toBeFocused();
        await page.keyboard.press('Escape');
        const scroll = page.locator('[data-calendar-scroll]');
        expect(await scroll.evaluate(el => el.scrollWidth > el.clientWidth)).toBe(true);
        await scroll.evaluate(el => el.scrollLeft = el.scrollWidth);
        await page.locator('[data-date="2024-12-31"]').tap();
        await expect(page.getByRole('heading', { name: /2024-12-31/ })).toBeFocused();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
    } finally {
        await context.close();
    }
});
