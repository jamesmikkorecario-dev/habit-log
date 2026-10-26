import { test, expect } from './fixtures.js';
import { mkdirSync } from 'node:fs';
import { action, register } from './support.js';

mkdirSync('storage/app/journal-design-checks', { recursive: true });

test('journal design renders responsively and counts Unicode drafts without losing saved text', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await register(page, 'journaldesign');
    await page.goto('/journal');
    await expect(page.getByRole('heading', { name: 'Private journal', exact: true })).toBeVisible();
    await expect(page.getByText('No entry for this day yet.', { exact: false })).toBeVisible();
    const content = page.locator('#journal-content');
    const counter = page.locator('#journal-count');
    await expect(counter).toHaveText('0 / 10,000 characters');
    await expect(page.locator('#journal-mood option')).toHaveCount(6);
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const editor = await page.locator('[data-journal-writing]').boundingBox();
        expect(editor.width).toBeLessThanOrEqual(768);
        const button = await page.getByRole('button', { name: 'Save journal', exact: true }).boundingBox();
        expect(button.height).toBeGreaterThanOrEqual(44);
        if (width === 390 || width === 1440) {
            await page.screenshot({ path: `storage/app/journal-design-checks/empty-${width}.png`, fullPage: true });
        }
    }
    // The server trims Unicode whitespace and counts code points, not UTF-16 units.
    await content.fill(' \u0085😀字e\u0301\nline\u00a0 ');
    await expect(counter).toHaveText('9 / 10,000 characters');
    await page.locator('#journal-mood').selectOption('4');
    await action(page, () => page.getByRole('button', { name: 'Save journal', exact: true }).click());
    await expect(page.getByText('Journal saved.', { exact: true })).toBeVisible();
    await expect(content).toHaveValue('😀字e\u0301\nline');
    await content.fill('Discardable draft');
    page.once('dialog', dialog => dialog.dismiss());
    await page.getByRole('button', { name: 'Reload journal', exact: true }).click();
    await expect(content).toHaveValue('Discardable draft');
    page.once('dialog', dialog => dialog.accept());
    await action(page, () => page.getByRole('button', { name: 'Reload journal', exact: true }).click());
    await expect(content).toHaveValue('😀字e\u0301\nline');
    await expect(counter).toHaveText('9 / 10,000 characters');
    // Simulate a paste/input event; native maxlength would incorrectly halve this limit.
    await content.evaluate(el => {
        el.value = '😀'.repeat(10000);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(counter).toHaveText('10,000 / 10,000 characters');
    await action(page, () => page.getByRole('button', { name: 'Save journal', exact: true }).click());
    await expect(page.getByText('Journal saved.', { exact: true })).toBeVisible();
    await expect(content).toHaveValue('😀'.repeat(10000));
    await content.fill('😀'.repeat(10001));
    await action(page, () => page.getByRole('button', { name: 'Save journal', exact: true }).click());
    await expect(content).toHaveAttribute('aria-invalid', 'true');
    await expect(counter).toHaveText('10,001 / 10,000 characters');
    await expect(content).toHaveValue('😀'.repeat(10001));
    await content.fill('A quiet moment to reflect.');
    await page.locator('#journal-mood').selectOption('');
    await action(page, () => page.getByRole('button', { name: 'Save journal', exact: true }).click());
    await page.reload();
    await expect(content).toHaveValue('A quiet moment to reflect.');
    await expect(page.locator('#journal-mood')).toHaveValue('');
    await page.screenshot({ path: 'storage/app/journal-design-checks/saved-desktop.png', fullPage: true });
    expect(errors).toEqual([]);
});
