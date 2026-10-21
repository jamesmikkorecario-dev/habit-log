import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { register, createHabit, action } from './support.js';

const artifacts = 'storage/app/count-editor-checks';
mkdirSync(artifacts, { recursive: true });
const goalGuidance = 'Performance is calculated from count ÷ goal; assessment is stored separately.';
const freeGuidance = 'Assessment determines performance: Completed 100%, Partial 50%, Missed 0%.';

async function capture(page, state) {
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(async () => { await document.fonts.ready; window.scrollTo(0, 0); });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: `${artifacts}/${state}-${width}.png`, fullPage: true });
    }
}

test('Count polish preserves dated units, assessment, field errors and numeric performance', async ({ page }) => {
    test.setTimeout(90_000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await register(page, 'countpolish');
    const today = new Date().toISOString().slice(0, 10);
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    const id = await createHabit(page, 'count', 'Read 20 pages');
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel('Start date', { exact: true }).fill(yesterday);
    await page.getByLabel('Goal (optional)', { exact: true }).fill('20');
    await page.getByLabel('Unit (optional)', { exact: true }).fill('pages');
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    const entryUrl = `/habits/${id}/entries/${today}`;
    await page.goto(entryUrl);
    const assessment = page.getByLabel('Your assessment', { exact: true });
    const count = page.getByLabel('Actual count (pages)', { exact: true });
    await expect(assessment).toHaveValue('');
    await expect(count).toHaveValue('');
    await expect(page.getByText(goalGuidance, { exact: true })).toBeVisible();
    await capture(page, 'count-goal');
    await page.locator('#entry-note').fill('Private Count regression draft');
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await expect(page.getByText('Choose an assessment.', { exact: true })).toBeVisible();
    await expect(page.getByText('Enter the actual count.', { exact: true })).toBeVisible();
    await expect(page.locator('main')).not.toContainText('form.status');
    await expect(page.locator('main')).not.toContainText('form.value');
    await expect(assessment.locator('xpath=ancestor::*[@data-flux-field][1]')).toContainText('Choose an assessment.');
    await expect(count).toHaveAttribute('aria-describedby', /entry-value-error/);
    const inputBox = await count.boundingBox();
    const errorBox = await page.locator('#entry-value-error').boundingBox();
    expect(errorBox.y).toBeGreaterThanOrEqual(inputBox.y + inputBox.height);
    expect(errorBox.y - inputBox.y - inputBox.height).toBeLessThanOrEqual(16);
    await expect(page.locator('#entry-note')).toHaveValue('Private Count regression draft');
    await capture(page, 'count-errors');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(scan.violations).toEqual([]);
    await action(page, () => assessment.selectOption('partial'));
    for (const value of ['-1', '1.5', '1000000000']) {
        await count.fill(value);
        expect(await count.evaluate(el => el.checkValidity())).toBe(false);
    }
    // A differing assessment must never override a goal-based score.
    for (const [value, status, score] of [['0', 'completed', '0.0'], ['10', 'partial', '50.0'], ['20', 'completed', '100.0'], ['25', 'missed', '100.0']]) {
        await count.fill(value);
        await action(page, () => assessment.selectOption(status));
        await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
        await page.goto(entryUrl);
        await expect(count).toHaveValue(value);
        await expect(assessment).toHaveValue(status);
        await page.goto(`/habits/${id}`);
        await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label', new RegExp(`${score}% performance`));
        await page.goto(entryUrl);
    }
    await capture(page, 'count-saved');
    await action(page, () => assessment.selectOption('not_applicable'));
    await expect(count).toHaveCount(0);
    await page.locator('#entry-reason').fill('Private N/A regression reason');
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await page.reload();
    await expect(assessment).toHaveValue('not_applicable');
    await expect(page.locator('#entry-reason')).toHaveValue('Private N/A regression reason');
    await capture(page, 'count-na');
    await action(page, () => assessment.selectOption('completed'));
    await expect(count).toHaveValue('25');
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel('Goal (optional)', { exact: true }).fill('');
    await page.getByLabel('Unit (optional)', { exact: true }).fill('chapters');
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    await page.goto(`/habits/${id}/entries/${yesterday}`);
    await expect(count).toBeVisible();
    await expect(page.getByText('Goal: 20 pages', { exact: true })).toBeVisible();
    await expect(page.getByText(goalGuidance, { exact: true })).toBeVisible();
    await capture(page, 'count-historical');

    const free = await createHabit(page, 'count', 'Read at my own pace');
    await page.goto(`/habits/${free}/entries/${today}`);
    await expect(page.getByLabel('Actual count', { exact: true })).toBeVisible();
    await expect(page.getByText(freeGuidance, { exact: true })).toBeVisible();
    await capture(page, 'count-no-goal');
    await page.goto(`/habits/${free}/edit`);
    await page.getByLabel('Unit (optional)', { exact: true }).fill('W'.repeat(30));
    await page.getByRole('button', { name: 'Save habit', exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${free}$`));
    await page.goto(`/habits/${free}/entries/${today}`);
    await expect(page.getByLabel(`Actual count (${'W'.repeat(30)})`, { exact: true })).toBeVisible();
    await capture(page, 'count-long-unit');
    await action(page, () => assessment.selectOption('partial'));
    await page.locator('#entry-value').fill('10');
    await action(page, () => page.getByRole('button', { name: 'Save activity', exact: true }).click());
    await page.goto(`/habits/${free}`);
    await expect(page.locator(`[data-date="${today}"]`)).toHaveAttribute('aria-label', /50.0% performance/);
    expect(errors).toEqual([]);
});

test('Count polish keeps the approved Yes-No labels and layout', async ({ page }) => {
    await register(page, 'countboolean');
    const id = await createHabit(page, 'boolean', 'Read today');
    await page.goto(`/habits/${id}/entries/${new Date().toISOString().slice(0, 10)}`);
    await expect(page.getByLabel('Your outcome', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Your assessment', { exact: true })).toHaveCount(0);
    await expect(page.locator('#entry-value')).toHaveCount(0);
    await expect(page.locator('option[value="partial"]')).toHaveCount(0);
    await capture(page, 'boolean-reference');
});
