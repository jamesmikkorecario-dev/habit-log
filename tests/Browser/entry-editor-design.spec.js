import { test, expect } from './fixtures.js';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { register, createHabit, action } from './support.js';

mkdirSync('storage/app/entry-editor-checks', { recursive: true });
const today = () => new Date().toISOString().slice(0, 10);
const offset = (days) => {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
};

async function layouts(page, state) {
    for (const width of [320, 375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const heading = await page.locator('main h1').boundingBox();
        expect(heading.x).toBe((width - Math.min(width, 1280)) / 2 + (width < 640 ? 16 : 24));
        const editor = await page.locator('[data-entry-writing]').boundingBox();
        const dates = await page.locator('[data-entry-date-navigation]').boundingBox();
        expect(editor.width).toBeLessThanOrEqual(768);
        if (width >= 1024) {
            expect(editor.x).toBeGreaterThan(dates.x + dates.width);
            expect(editor.y).toBe(dates.y);
        } else {
            expect(editor.y).toBeGreaterThanOrEqual(dates.y + dates.height);
        }
        for (const input of await page.locator('main input, main select, main textarea').all()) {
            if (!await input.isVisible()) continue;
            const box = await input.boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(16);
            expect(box.x + box.width).toBeLessThanOrEqual(width - 16);
        }
        for (const button of await page.locator('main button').all()) {
            if (await button.isVisible()) expect((await button.boundingBox()).height).toBeGreaterThanOrEqual(44);
        }
        if (width === 390 || width === 1440) await page.screenshot({ path: `storage/app/entry-editor-checks/${state}-${width}.png`, fullPage: true });
    }
}

test('entry editor design covers all five types, empty defaults, conditional privacy fields and responsive layouts', async ({ page }) => {
    test.setTimeout(90_000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'entrytypes');
    for (const type of ['boolean', 'count', 'duration', 'checkpoint', 'avoidance']) {
        const id = await createHabit(page, type, 'Habit'.repeat(20));
        if (type === 'checkpoint') {
            await page.goto(`/habits/${id}/edit`);
            await page.getByLabel('Checkpoint 1', { exact: true }).fill('Checkpoint'.repeat(8));
            await page.getByRole('button', { name: 'Save habit', exact: true }).click();
            await page.waitForURL(new RegExp(`/habits/${id}$`));
        }
        await page.getByRole('link', { name: 'Record today', exact: true }).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Record activity');
        await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-path', '~/tracking/edit');
        await expect(page.locator('[data-terminal-path]')).toHaveAttribute('data-terminal-state', 'complete');
        await expect(page.locator('[data-entry-state]')).toContainText('No saved activity for this date.');
        const outcome = page.getByLabel(type === 'checkpoint' ? 'Applicability' : (['count', 'duration', 'avoidance'].includes(type) ? 'Your assessment' : 'Your outcome'), { exact: true });
        await expect(outcome).toHaveValue(type === 'checkpoint' ? 'missed' : '');
        if (type === 'boolean') await expect(outcome.locator('option[value="partial"]')).toHaveCount(0);
        if (type === 'checkpoint') {
            await expect(page.getByRole('checkbox', { name: 'Checkpoint'.repeat(8), exact: true })).not.toBeChecked();
            // The entire label row must provide a touch-sized target.
            expect(await page.locator('[data-entry-checkpoint] [data-flux-label]').first().evaluate(el => el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
        }
        await layouts(page, type);
        await action(page, () => outcome.selectOption('not_applicable'));
        await expect(page.getByLabel('Private N/A reason (optional)', { exact: true })).toBeVisible();
        await expect(page.locator('#entry-value')).toHaveCount(0);
        await expect(page.locator('#entry-reason')).toHaveAttribute('maxlength', '500');
        await expect(page.locator('#entry-note')).toHaveAttribute('maxlength', '2000');
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(scan.violations).toEqual([]);
        await layouts(page, `${type}-na`);
        // Discard only this synthetic unsaved N/A selection before the next fixture.
        await Promise.all([
            page.waitForEvent('dialog').then(dialog => dialog.accept()),
            page.getByRole('button', { name: 'Reload entry', exact: true }).click(),
        ]);
        await expect(outcome).toHaveValue(type === 'checkpoint' ? 'missed' : '');
    }
    expect(errors).toEqual([]);
});

test('entry editor design preserves validation, confirmed save, loading, reload and guarded date navigation', async ({ page }) => {
    await register(page, 'entrydates');
    const id = await createHabit(page, 'count', 'Read regularly');
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel('Start date', { exact: true }).fill(offset(-10));
    await page.getByLabel('Goal (optional)', { exact: true }).fill('10');
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    await page.getByRole('link', { name: 'Record today', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}/entries/${today()}$`));
    const editorUrl = page.url();
    await page.locator('#entry-note').fill('Private unsaved draft');
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await expect(page.getByText('Review the highlighted fields. Your changes have not been saved.', { exact: true })).toBeFocused();
    await expect(page.locator('#entry-note')).toHaveValue('Private unsaved draft');
    await expect(page.getByText('Activity saved.', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Unsaved changes', { exact: true })).toBeVisible();
    await action(page, () => page.getByLabel('Your assessment', { exact: true }).selectOption('partial'));
    await page.locator('#entry-value').fill('5');
    let release;
    const held = new Promise(resolve => { release = resolve; });
    await page.route('**/livewire*/update', async route => { await held; await route.continue(); }, { times: 1 });
    await page.getByRole('button', { name: 'Save activity', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Saving…', exact: true })).toBeDisabled();
    await expect(page.locator('#entry-note')).toBeDisabled();
    release();
    await expect(page.getByText('Activity saved.', { exact: true })).toBeVisible();
    await expect(page.locator('[data-entry-state]')).toContainText('Editing saved activity.');
    await page.locator('#entry-note').fill('Keep this new draft');
    await expect(page.getByText('Activity saved.', { exact: true })).toBeHidden();
    await Promise.all([
        page.waitForEvent('dialog').then(dialog => dialog.dismiss()),
        page.getByRole('button', { name: 'Reload entry', exact: true }).click(),
    ]);
    await expect(page.locator('#entry-note')).toHaveValue('Keep this new draft');
    await page.getByLabel('Entry date', { exact: true }).fill(offset(-1));
    await Promise.all([
        page.waitForEvent('dialog').then(dialog => dialog.dismiss()),
        page.getByRole('button', { name: 'Go to date', exact: true }).click(),
    ]);
    await expect(page).toHaveURL(editorUrl);
    await expect(page.locator('#entry-note')).toHaveValue('Keep this new draft');
    await Promise.all([
        page.waitForEvent('dialog').then(dialog => dialog.accept()),
        page.getByRole('button', { name: 'Go to date', exact: true }).click(),
    ]);
    await expect(page).toHaveURL(new RegExp(`/entries/${offset(-1)}$`));
    await expect(page.locator('[data-entry-state]')).toContainText('No saved activity for this date.');
    await expect(page.getByRole('link', { name: 'Daily overview', exact: true })).toHaveAttribute('href', `http://127.0.0.1:8017/tracking?date=${offset(-1)}`);
    await expect(page.getByRole('link', { name: 'Entry history', exact: true })).toHaveAttribute('href', `http://127.0.0.1:8017/habits/${id}/entries`);
    await page.goto(editorUrl);
    await expect(page.locator('#entry-note')).toHaveValue('Private unsaved draft');
    await expect(page.locator('#entry-value')).toHaveValue('5');
    await page.goto(`/habits/${id}/entries/${offset(1)}`);
    await expect(page.getByText(/Future dates cannot be recorded/)).toBeVisible();
    await expect(page.locator('#entry-note')).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Save activity', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reload entry', exact: true })).toBeEnabled();
    await page.goto(`/habits/${id}/entries/${offset(-20)}`);
    await expect(page.getByText(/Outside this habit’s active dates/)).toBeVisible();
    await expect(page.locator('[data-entry-form]')).toHaveCount(0);
    await layouts(page, 'unavailable');
});
