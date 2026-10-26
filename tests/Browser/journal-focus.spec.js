import { test, expect } from './fixtures.js';
import { action, register } from './support.js';

for (const dismissal of ['Escape', 'Cancel', 'Close modal']) {
    test(`journal ${dismissal} restores focus after a delayed cancellation request`, async ({ page }) => {
        await register(page, 'journalfocus');
        await page.goto('/journal');
        await page.locator('#journal-content').fill('Keep this journal');
        await page.locator('#journal-mood').selectOption('3');
        await action(page, () => page.getByRole('button', { name: 'Save journal', exact: true }).click());
        const trigger = page.getByRole('button', { name: 'Delete journal', exact: true });
        const dialog = page.getByRole('dialog');
        await action(page, () => trigger.click());
        await expect(dialog).toBeVisible();

        let release;
        const gate = new Promise(resolve => { release = resolve; });
        let intercepted;
        const requestHeld = new Promise(resolve => { intercepted = resolve; });
        await page.route('**/livewire*/**', async route => {
            const calls = route.request().postDataJSON()?.components?.flatMap(component => component.calls) ?? [];
            if (calls.some(call => call.method === 'cancelDeletion')) {
                intercepted();
                await gate;
            }
            await route.continue();
        });

        try {
            await action(page, async () => {
                if (dismissal === 'Escape') await page.keyboard.press('Escape');
                else await dialog.getByRole('button', { name: dismissal, exact: true }).click();
                await requestHeld;
                await expect(trigger).toBeDisabled();
                release();
            });
            await expect(dialog).not.toBeVisible();
            await expect(trigger).toBeEnabled();
            await expect(trigger).toBeFocused();
            await page.reload();
            await expect(page.locator('#journal-content')).toHaveValue('Keep this journal');
            await expect(page.locator('#journal-mood')).toHaveValue('3');
            await action(page, () => trigger.click());
            await action(page, () => dialog.getByRole('button', { name: 'Permanently delete journal', exact: true }).click());
            await expect(dialog).not.toBeVisible();
            await expect(page.locator('#journal-heading')).toBeFocused();
            await expect(page.locator('#journal-content')).toHaveValue('');
            await expect(page.locator('#journal-mood')).toHaveValue('');
        } finally {
            release();
            await page.unrouteAll({ behavior: 'wait' });
        }
    });
}
