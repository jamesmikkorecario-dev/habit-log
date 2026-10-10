import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { register, createHabit } from './support.js';

mkdirSync('storage/app/container-checks', { recursive: true });

test('authenticated pages share outer alignment and keep inner forms bounded', async ({ page }) => {
    test.setTimeout(120_000);
    await register(page, 'containers');
    const habit = await createHabit(page, 'count', 'Container check');
    const paths = ['/dashboard', '/tracking', '/journal', '/habits', '/habits/create', `/habits/${habit}/edit`, '/categories', '/people', '/settings/profile'];
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        for (const path of paths) {
            await page.goto(path);
            const dimensions = await page.locator('main').evaluate(main => {
                const bounds = main.getBoundingClientRect();
                const styles = getComputedStyle(main);
                const nav = document.querySelector('body > div > nav > div').getBoundingClientRect();
                const heading = main.querySelector('h1').getBoundingClientRect();
                return {
                    left: bounds.left, width: bounds.width,
                    paddingLeft: parseFloat(styles.paddingLeft), paddingRight: parseFloat(styles.paddingRight),
                    navLeft: nav.left, navWidth: nav.width, headingLeft: heading.left,
                    pageWidth: document.documentElement.scrollWidth,
                };
            });
            expect(dimensions.width, path).toBe(Math.min(width, 1280));
            expect(dimensions.left, path).toBe((width - Math.min(width, 1280)) / 2);
            expect(dimensions.paddingLeft, path).toBe(width < 640 ? 16 : 24);
            expect(dimensions.paddingRight, path).toBe(dimensions.paddingLeft);
            expect(dimensions.navLeft, path).toBe(dimensions.left);
            expect(dimensions.navWidth, path).toBe(dimensions.width);
            expect(dimensions.headingLeft, path).toBe(dimensions.left + dimensions.paddingLeft);
            expect(dimensions.pageWidth, path).toBeLessThanOrEqual(width);
            if (width === 1440 && ['/dashboard', '/tracking', '/journal', '/settings/profile'].includes(path)) {
                await page.screenshot({ path: `storage/app/container-checks/${path.replaceAll('/', '-')}-1440.png`, fullPage: true });
            }
        }
    }
    // Wide outer layouts must not turn compact forms into full-width controls.
    for (const [path, selector, maximum] of [
        ['/journal', '[data-journal-writing]', 768],
        ['/settings/profile', 'input[wire\\:model="username"]', 576],
        ['/categories', 'main form', 576],
        ['/people', 'main form', 576],
        ['/tracking', 'input[type="date"]', 224],
        [`/habits/${habit}/edit`, 'input[type="number"]', 384],
        [`/habits/${habit}/entries/${new Date().toISOString().slice(0, 10)}`, '[data-entry-editor]', 672],
    ]) {
        await page.goto(path);
        const bounds = await page.locator(selector).first().boundingBox();
        expect(bounds.width, path).toBeLessThanOrEqual(maximum);
        expect(bounds.x, path).toBeGreaterThanOrEqual(104);
        expect(bounds.x + bounds.width, path).toBeLessThanOrEqual(1336);
    }
    await page.setViewportSize({ width: 768, height: 960 });
    await page.goto('/tracking');
    await page.getByRole('button', { name: 'Toggle navigation', exact: true }).click();
    const menu = page.getByRole('link', { name: 'Dashboard', exact: true });
    await expect(menu).toBeVisible();
    const navBounds = await menu.boundingBox();
    expect(navBounds.x).toBe(24);
    await menu.click();
    await expect(page).toHaveURL(/\/dashboard$/);
});
