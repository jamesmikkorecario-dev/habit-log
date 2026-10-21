import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { action, register } from './support.js';

mkdirSync('storage/app/categories-design-checks', { recursive: true });

async function inspectLayouts(page, state) {
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(() => scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const heading = await page.locator('main h1').boundingBox();
        expect(heading.x).toBe((width - Math.min(width, 1280)) / 2 + (width < 640 ? 16 : 24));
        const form = await page.getByRole('form', { name: 'Create a category', exact: true }).boundingBox();
        expect(form.width).toBeLessThanOrEqual(576);
        const catalog = await page.getByRole('region', { name: 'Available categories', exact: true }).boundingBox();
        if (width >= 1024) expect(catalog.x).toBeGreaterThan(form.x + form.width);
        else expect(catalog.y).toBeGreaterThanOrEqual(form.y + form.height);
        const cards = page.getByRole('region', { name: 'System categories', exact: true }).locator('[data-category-card]');
        const first = await cards.nth(0).boundingBox();
        const second = await cards.nth(1).boundingBox();
        if (width >= 640) expect(second.y).toBe(first.y);
        else expect(second.y).toBeGreaterThan(first.y);
        const categoryAction = cards.first().getByRole('button');
        await categoryAction.scrollIntoViewIfNeeded();
        await expect(categoryAction).toBeVisible();
        expect((await categoryAction.boundingBox()).height).toBeGreaterThanOrEqual(44);
        if (width === 390) await page.screenshot({ path: `storage/app/categories-design-checks/${state}-action-390.png` });
        await page.evaluate(() => scrollTo(0, 0));
        if (width === 390 || width === 1440) await page.screenshot({ path: `storage/app/categories-design-checks/${state}-${width}.png`, fullPage: true });
    }
}

test('categories design groups the catalog and explains review before creating or selecting', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'categoriesdesign');
    await page.goto('/categories');
    const form = page.getByRole('form', { name: 'Create a category', exact: true });
    await expect(page.getByRole('heading', { name: 'Categories', exact: true })).toBeVisible();
    await expect(page.locator('[data-category-card]')).toHaveCount(10);
    await expect(page.getByText('Make your own categories.', { exact: true })).toBeVisible();
    await expect(page.getByText('No categories available yet.', { exact: true })).toHaveCount(0);
    await inspectLayouts(page, 'system');
    await action(page, () => form.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await expect(form.getByLabel('Category name', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await form.getByLabel('Category name', { exact: true }).fill('Nutrition');
    await action(page, () => form.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    const review = form.getByRole('region', { name: 'Category recommendations', exact: true });
    await expect(review.getByText('Nothing has been created yet.', { exact: false })).toBeVisible();
    await expect(review.getByRole('button', { name: 'Use existing category: Nutrition', exact: true })).toBeVisible();
    await expect(page.locator('[data-category-card]')).toHaveCount(10);
    await form.getByLabel('Category name', { exact: true }).fill('Evening Ritual');
    await expect(review.getByText('Name changed.', { exact: false })).toBeVisible();
    await expect(review.getByRole('button', { name: 'Create category', exact: true })).not.toBeVisible();
    await form.getByLabel('Description (optional)', { exact: true }).fill('0');
    await action(page, () => form.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await expect(review.getByText('No similar categories found.', { exact: false })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 960 });
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: 'storage/app/categories-design-checks/review-390.png', fullPage: true });
    await form.getByRole('button', { name: 'Create category', exact: true }).click();
    await page.waitForURL(/\/habits\/create\?category=\d+$/);
    await expect(page.getByLabel('Category', { exact: true }).locator('option:checked')).toHaveText('Evening Ritual');
    await expect(page.getByText('Category created. You can now use it for a habit.', { exact: true })).toBeVisible();
    const categoryId = await page.getByLabel('Category', { exact: true }).inputValue();
    await page.goto('/categories');
    const personal = page.getByRole('region', { name: 'Your categories', exact: true });
    await expect(personal.locator('[data-category-card]')).toHaveCount(1);
    await expect(personal.getByText('0', { exact: true })).toBeVisible();
    await inspectLayouts(page, 'personal');
    await form.getByLabel('Category name', { exact: true }).fill('EVENING--RITUAL');
    await action(page, () => form.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await expect(review.getByText('This category already exists.', { exact: true })).toBeVisible();
    await expect(review.locator('[wire\\:click="save"]')).toHaveCount(0);
    await expect(personal.locator('[data-category-card]')).toHaveCount(1);
    await personal.getByRole('button', { name: 'Create habit in Evening Ritual', exact: true }).focus();
    await page.keyboard.press('Enter');
    await page.waitForURL(new RegExp(`/habits/create\\?category=${categoryId}$`));
    await expect(page.getByLabel('Category', { exact: true })).toHaveValue(categoryId);
    await page.goto('/categories');
    await form.getByLabel('Category name', { exact: true }).fill('Nutrition');
    await action(page, () => form.getByRole('button', { name: 'Check for similar categories', exact: true }).click());
    await form.getByRole('button', { name: 'Use existing category: Nutrition', exact: true }).click();
    await page.waitForURL(/\/habits\/create\?category=\d+$/);
    await expect(page.getByLabel('Category', { exact: true }).locator('option:checked')).toHaveText('Nutrition');
    expect(errors).toEqual([]);
});

test('category workflow distinguishes matches, preserves drafts and prevents repeat submissions', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'categoryworkflow');
    await page.goto('/categories');
    const form = page.getByRole('form', { name: 'Create a category', exact: true });
    const name = form.getByLabel('Category name', { exact: true });
    const description = form.getByLabel('Description (optional)', { exact: true });
    const check = form.getByRole('button', { name: 'Check for similar categories', exact: true });
    const review = form.getByRole('region', { name: 'Category recommendations', exact: true });
    await description.fill('My personal draft');
    await name.fill('---');
    await action(page, () => check.click());
    await expect(name).toHaveAttribute('aria-invalid', 'true');
    await expect(name).toHaveValue('---');
    await expect(description).toHaveValue('My personal draft');
    await name.fill('Nutrition');
    await page.route('**/livewire-*/update', async route => {
        await new Promise(resolve => setTimeout(resolve, 700));
        await route.continue();
    });
    const checking = action(page, () => check.click());
    await expect(form.getByRole('button', { name: 'Checking…', exact: true })).toBeDisabled();
    await checking;
    await expect(review).toHaveAttribute('aria-live', 'polite');
    await expect(form.locator('[aria-current="step"]')).toHaveText('02 / Review and choose');
    await expect(review.getByText('This category already exists.', { exact: true })).toBeVisible();
    await expect(review.getByText('System category · Exact name match', { exact: true })).toBeVisible();
    await expect(review.getByText('Using a category opens Create Habit', { exact: false })).toBeVisible();
    await expect(review.getByText('Need a separate personal category?', { exact: false })).toBeVisible();
    await expect(description).toHaveValue('My personal draft');
    await check.focus();
    await page.keyboard.press('Tab');
    await expect(review.getByRole('button', { name: 'Use existing category: Nutrition', exact: true })).toBeFocused();
    expect(await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement);
        return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
    })).toBe(true);
    for (const [value, state, heading] of [
        ['Nutrition', 'exact', 'This category already exists.'],
        ['Nutrition routine', 'similar', 'We found similar categories.'],
        ['Evening winddown', 'none', 'No similar categories found.'],
    ]) {
        await name.fill(value);
        await action(page, () => check.click());
        await expect(review.getByRole('heading', { name: heading, exact: true })).toBeVisible();
        for (const width of [375, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            const buttons = review.getByRole('button');
            for (const button of await buttons.all()) {
                await button.scrollIntoViewIfNeeded();
                await expect(button).toBeVisible();
                expect((await button.boundingBox()).height).toBeGreaterThanOrEqual(44);
            }
            if (width === 390 || width === 1440) {
                await page.evaluate(() => scrollTo(0, 0));
                await page.screenshot({ path: `storage/app/category-workflow-checks/${state}-${width}.png`, fullPage: true });
            }
        }
    }
    await expect(review.locator('[wire\\:click^="useCategory"]')).toHaveCount(0);
    await expect(description).toHaveValue('My personal draft');
    await name.fill('Nutrition');
    await expect(form.locator('[aria-current="step"]')).toHaveText('01 / Describe');
    await expect(review.getByRole('button')).not.toBeVisible();
    await action(page, () => check.click());
    let saves = 0;
    page.on('request', request => {
        if (request.method() === 'POST' && request.url().includes('livewire')) {
            const components = request.postDataJSON()?.components ?? [];
            saves += components.flatMap(component => component.calls ?? []).filter(call => call.method === 'save').length;
        }
    });
    await review.getByRole('button', { name: 'Create a new category', exact: true }).click();
    await expect(review.getByRole('button', { name: 'Creating…', exact: true })).toBeDisabled();
    await page.waitForURL(/\/habits\/create\?category=\d+$/);
    expect(saves).toBe(1);
    await expect(page.getByText('Category created. You can now use it for a habit.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Category', { exact: true }).locator('option:checked')).toHaveText('Nutrition');
    await page.goto('/categories');
    await name.fill('NUTRITION!');
    await action(page, () => check.click());
    await expect(review.getByText('Your category · Exact name match', { exact: true })).toBeVisible();
    await expect(review.locator('[wire\\:click="save"]')).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Your categories', exact: true }).locator('[data-category-card]')).toHaveCount(1);
    expect(errors).toEqual([]);
});
