import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { register, createHabit, action } from './support.js';
import { mkdirSync, writeFileSync } from 'node:fs';

mkdirSync('storage/app/dashboard-checks', { recursive: true });

async function checkCalendar(page, year, state) {
    const calendar = page.locator('[data-calendar-scroll]');
    const measurements = [];
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        await calendar.evaluate(el => el.scrollLeft = 0);
        const result = await calendar.evaluate(el => {
            const box = el.getBoundingClientRect();
            const dates = [...el.querySelectorAll('[data-date]')];
            const days = dates.map(day => ({ date: day.dataset.date, x: day.getBoundingClientRect().left, y: day.getBoundingClientRect().top, state: day.dataset.state, band: day.className, label: day.getAttribute('aria-label') }));
            const months = [...el.querySelectorAll('[data-month]')].map(month => ({ month: month.dataset.month, x: month.getBoundingClientRect().left }));
            const labels = [...el.closest('.activity-calendar').querySelector('.calendar-weekdays').children].map(label => label.getBoundingClientRect().top);
            return { viewport: innerWidth, scrollLeft: el.scrollLeft, width: el.clientWidth, contentWidth: el.scrollWidth, left: box.left, right: box.right, pageOverflow: document.documentElement.scrollWidth > innerWidth, days, months, labels };
        });
        expect(result.scrollLeft).toBe(0);
        expect(result.pageOverflow).toBe(false);
        expect(result.days[0].date).toBe(`${year}-01-01`);
        expect(result.days.at(-1).date).toBe(`${year}-12-31`);
        expect(result.days.length).toBe(year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 366 : 365);
        expect(result.months.length).toBe(12);
        expect(result.days[0].x).toBeGreaterThan(result.left);
        for (const month of result.months) {
            expect(Math.abs(month.x - result.days.find(d => d.date === `${month.month}-01`).x)).toBeLessThan(1);
        }
        for (const day of result.days) {
            const row = (new Date(`${day.date}T00:00:00Z`).getUTCDay() + 6) % 7;
            expect(Math.abs(day.y - result.labels[row])).toBeLessThan(1);
        }
        await calendar.evaluate(el => el.scrollLeft = el.scrollWidth);
        const last = await page.locator(`[data-date="${year}-12-31"]`).boundingBox();
        expect(last.x + last.width).toBeLessThanOrEqual(result.right);
        expect(last.x).toBeGreaterThan(result.left);
        await calendar.evaluate(el => el.scrollLeft = 0);
        // The shared page cap may require internal scrolling even on desktop.
        // January visibility and December reachability above apply at every width.
        measurements.push({ ...result, days: result.days.map(({ date, state, band, label }) => ({ date, state, band, label })) });
        if (width === 390 || width === 1440) {
            await page.screenshot({ path: `storage/app/dashboard-checks/${state}-${width}.png`, fullPage: true });
        }
    }
    // Resizing and scrolling cannot change score labels or intensity classes.
    for (const result of measurements) expect(result.days).toEqual(measurements[0].days);
    writeFileSync(`storage/app/dashboard-checks/${state}-geometry.json`, JSON.stringify(measurements, null, 2));
}

test('dashboard empty onboarding and complete calendar geometry', async ({ page }) => {
    test.setTimeout(120_000);
    await register(page, 'emptydash');
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { name: 'Your journey starts here.' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Create your first habit', exact: true })).toHaveAttribute('href', /\/habits\/create$/);
    await page.screenshot({ path: 'storage/app/dashboard-checks/empty-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: 'storage/app/dashboard-checks/empty-mobile.png', fullPage: true });
    await expect(page.getByText('Explore your progress and activity history', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Progress overview', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Activity history', exact: true })).toBeVisible();
    await expect(page.locator('article[aria-label="30-day consistency"]')).toContainText('—');
    await expect(page.locator('article[aria-label="30-day consistency"]')).toContainText('Create your first habit to get started.');
    await expect(page.locator('article[aria-label="Current streak"]')).toContainText('0 days');
    await expect(page.locator('[data-activity-context]')).toHaveText('Create your first habit to get started.');
    for (const label of ['How these numbers work', 'Status key']) {
        const trigger = page.getByRole('button', { name: label, exact: true });
        await trigger.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('dialog', { name: label, exact: true })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(trigger).toBeFocused();
    }
    const calendar = page.locator('[data-calendar-scroll]');
    await expect.poll(() => calendar.evaluate(el => el.scrollLeft)).toBe(0);
    await checkCalendar(page, Number(new Date().getUTCFullYear()), 'empty-calendar');
    await page.getByLabel('Year', { exact: true }).fill('2012');
    await action(page, () => page.getByRole('button', { name: 'Go to year', exact: true }).click());
    await checkCalendar(page, 2012, 'empty-54-weeks');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations).toEqual([]);
});

test('dashboard distinguishes first check-in, inactive periods, zero and not applicable', async ({ page }) => {
    await register(page, 'emptyreasons');
    const today = new Date().toISOString().slice(0, 10);
    const habit = await createHabit(page, 'boolean', 'A small step');
    await page.goto(`/habits/${habit}/edit`);
    await page.getByLabel('Start date', { exact: true }).fill(today);
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/habits/${habit}$`));
    await page.goto('/dashboard');
    const recent = page.locator('article[aria-label="30-day consistency"]');
    const yearly = page.locator('article[aria-label="Yearly consistency"]');
    await expect(recent).toContainText('—');
    await expect(recent).toContainText('Your progress will appear after your first check-in.');
    await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('data-state', 'pending');
    await action(page, () => page.getByRole('button', { name: 'Previous year', exact: true }).click());
    await expect(yearly).toContainText('No eligible activity for this period.');
    await expect(recent).toContainText('first check-in');
    await action(page, () => page.getByRole('button', { name: 'Current year', exact: true }).click());
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: 'storage/app/dashboard-checks/first-check-in-390.png', fullPage: true });
    for (const [outcome, expected] of [['missed', '0.0%'], ['not_applicable', 'Not applicable for this period.']]) {
        await page.goto(`/habits/${habit}/entries/${today}`);
        await action(page, () => page.getByLabel('Your outcome', { exact: true }).selectOption(outcome));
        await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
        await page.goto('/dashboard');
        await expect(recent).toContainText(expected);
        await expect(yearly).toContainText(expected);
        await expect(page.locator('article[aria-label="Current streak"]')).toContainText('0 days');
    }
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations).toEqual([]);
});

test('dashboard populated daily habits, leap-year alignment and accessible navigation', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'dailydash');
    const today = new Date().toISOString().slice(0, 10);
    const count = await createHabit(page, 'count', 'Read 10 pages');
    await page.goto(`/habits/${count}/edit`);
    await page.getByLabel('Goal (optional)', { exact: true }).fill('10');
    await page.getByLabel('Start date', { exact: true }).fill('2024-01-01');
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/habits/${count}$`));
    for (const date of ['2024-02-29', today]) {
        await page.goto(`/habits/${count}/entries/${date}`);
        await action(page, () => page.getByLabel('Your assessment', { exact: true }).selectOption('partial'));
        await page.locator('#entry-value').fill('5');
        await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    }
    await createHabit(page, 'boolean', 'Step outside');
    await createHabit(page, 'duration', 'Move your body');
    await createHabit(page, 'checkpoint', 'Morning routine');
    await createHabit(page, 'avoidance', 'Less caffeine');
    await page.goto('/dashboard');
    await expect(page.locator('[data-today-habit]')).toHaveCount(5);
    await expect(page.getByRole('heading', { name: 'Progress overview', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Activity history', exact: true })).toBeVisible();
    await expect(page.locator('[data-calendar-scroll]').locator('xpath=ancestor::details')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Edit check-in for Read 10 pages' })).toHaveAttribute('href', new RegExp(`/habits/${count}/entries/${today}$`));
    await expect(page.locator('[data-today-habit]').first()).toContainText('Goal 10');
    await expect.poll(() => page.locator('[data-calendar-scroll]').evaluate(el => el.scrollLeft)).toBe(0);
    await checkCalendar(page, Number(today.slice(0, 4)), 'populated');
    await page.getByLabel('Year', { exact: true }).fill('2024');
    await action(page, () => page.getByRole('button', { name: 'Go to year', exact: true }).click());
    await expect.poll(() => page.locator('[data-calendar-scroll]').evaluate(el => el.scrollLeft)).toBe(0);
    await checkCalendar(page, 2024, 'leap');
    const leap = page.locator('[data-date="2024-02-29"]');
    await expect(leap).toHaveAttribute('aria-label', /50\.0% performance/);
    await expect(leap).toHaveClass(/heat-band-2/);
    await page.locator('[data-date="2024-01-01"]').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('[data-date="2024-01-08"]')).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('[data-date="2024-01-09"]')).toBeFocused();
    await page.keyboard.press('End');
    await expect(page.locator('[data-date="2024-12-31"]')).toBeFocused();
    await page.keyboard.press('Home');
    await expect(page.locator('[data-date="2024-01-01"]')).toBeFocused();
    await leap.focus();
    await action(page, () => page.keyboard.press('Enter'));
    await expect(page.getByText('50.0% performance', { exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(leap).toBeFocused();
    await action(page, () => page.getByRole('button', { name: 'Next year', exact: true }).click());
    await expect(page.getByLabel('Year', { exact: true })).toHaveValue('2025');
    await action(page, () => page.getByRole('button', { name: 'Previous year', exact: true }).click());
    await expect(page.getByLabel('Year', { exact: true })).toHaveValue('2024');
    await action(page, () => page.getByRole('button', { name: 'Current year', exact: true }).click());
    await expect(page.getByRole('button', { name: 'Next year', exact: true })).toBeDisabled();
    await page.getByLabel('Date to view', { exact: true }).fill(today);
    await action(page, () => page.getByRole('button', { name: 'Show date', exact: true }).click());
    await page.getByRole('button', { name: 'Close details', exact: true }).click();
    await expect(page.getByLabel('Date to view', { exact: true })).toBeFocused();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.getByRole('link', { name: 'Edit check-in for Read 10 pages' }).click();
    await expect(page.locator('#entry-value')).toHaveValue('5');
    await page.goto('/dashboard');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
    await page.getByRole('link', { name: 'Tracking', exact: true }).click();
    await expect(page).toHaveURL(/\/tracking$/);
    expect(errors).toEqual([]);
});
