import { test, expect } from '@playwright/test';
import { register, createHabit } from './support.js';

for (const editor of ['activity', 'journal']) {
    test(`${editor} draft survives cancelled Back and allows confirmed Back`, async ({ page }) => {
        await register(page, 'history');
        let origin;
        let field;
        if (editor === 'activity') {
            const id = await createHabit(page);
            origin = `/habits/${id}`;
            await page.getByRole('link', { name: 'Record today', exact: true }).click();
            await expect(page).toHaveURL(/\/entries\/\d{4}-\d{2}-\d{2}$/);
            field = page.locator('#entry-note');
        } else {
            await page.goto('/dashboard');
            origin = '/dashboard';
            await page.getByRole('link', { name: 'Journal', exact: true }).click();
            await expect(page).toHaveURL(/\/journal$/);
            field = page.locator('#journal-content');
        }
        const editorUrl = page.url();
        await field.fill('Unsaved history draft');
        await Promise.all([
            page.waitForEvent('dialog', { timeout: 3000 }).then(dialog => dialog.dismiss()),
            page.evaluate(() => history.back()),
        ]);
        await expect(page).toHaveURL(editorUrl);
        await expect(field).toHaveValue('Unsaved history draft');
        await Promise.all([
            page.waitForEvent('dialog', { timeout: 3000 }).then(dialog => dialog.accept()),
            page.evaluate(() => history.back()),
        ]);
        await expect(page).toHaveURL(`http://127.0.0.1:8017${origin}`);
    });
}
