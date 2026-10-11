import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const labels = {
    dashboard: 'Welcome back, Header fixture.', tracking: 'Daily tracking',
    journal: 'Private journal', habits: 'Your habits', 'habits/create': 'Create a habit',
    'habits/edit': 'Edit habit', categories: 'Categories', people: 'People',
    'settings/profile': 'Profile Settings',
};
const label = (page) => page.locator('[data-terminal-path]');

async function observe(page) {
    await page.addInitScript(() => {
        window.headerEvents = [];
        window.headerGeometry = [];
        const seen = new WeakMap();
        const read = () => document.querySelectorAll('[data-terminal-path]').forEach((el) => {
            const text = el.querySelector('[data-terminal-text]').textContent;
            const state = el.dataset.terminalState;
            const key = `${state}:${text}`;
            if (seen.get(el) === key) return;
            seen.set(el, key);
            window.headerEvents.push({ path: el.dataset.terminalPath, text, state, time: performance.now() });
            const rect = el.querySelector('.terminal-path-visual').getBoundingClientRect();
            const heading = document.querySelector('h1').getBoundingClientRect();
            window.headerGeometry.push({ width: rect.width, height: rect.height, headingY: heading.y });
        });
        new MutationObserver(read).observe(document, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['data-terminal-state'] });
        window.documentIdentity = Math.random();
    });
}

for (const [path, heading] of Object.entries(labels)) {
    test(`initial markup and accessible mapping: ${path}`, async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const response = await page.goto(`/__headers/${path}`);
        expect(await response.text()).toContain(`data-terminal-text>~/${path}</span>`);
        await expect(label(page)).toHaveAttribute('data-terminal-path', `~/${path}`);
        await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading);
        await expect(label(page).locator('.sr-only')).toHaveText(`~/${path}`);
        await expect(label(page).locator('[aria-hidden="true"]')).toHaveCount(1);
        expect(await label(page).ariaSnapshot()).toBe(`- paragraph: ~/${path}`);
        expect(await label(page).locator('[aria-live], [tabindex], a, button')).toHaveCount(0);
        expect(await label(page).locator('.terminal-path-overlay .terminal-path-cursor').evaluate(el => getComputedStyle(el).animationName)).toBe('none');
    });
}

test('ordered 50ms typing, finite blink, stable geometry and no reactive restarts', async ({ page }) => {
    await observe(page);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/__headers/settings/profile');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'blinking');
    const cursor = label(page).locator('.terminal-path-overlay .terminal-path-cursor');
    expect(await cursor.evaluate(el => ({ name: getComputedStyle(el).animationName, iterations: getComputedStyle(el).animationIterationCount })))
        .toEqual({ name: 'terminal-cursor-blink', iterations: '3' });
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    const data = await page.evaluate(() => ({ events: headerEvents, geometry: headerGeometry }));
    const typed = data.events.filter(event => event.state === 'typing' || event.state === 'blinking');
    const path = '~/settings/profile';
    expect(typed.map(event => event.text)).toEqual(Array.from({ length: path.length + 1 }, (_, index) => path.slice(0, index)));
    const intervals = typed.slice(1).map((event, index) => event.time - typed[index].time);
    expect(intervals.every(interval => interval >= 35 && interval < 150)).toBe(true);
    const blink = data.events.find(event => event.state === 'blinking');
    const complete = data.events.find(event => event.state === 'complete');
    expect(complete.time - blink.time).toBeGreaterThanOrEqual(1750);
    // Ignore the pre-CSS parser frame; every animation frame must match.
    const geometry = data.geometry.slice(data.events.findIndex(event => event.state === 'typing'));
    expect(new Set(geometry.map(item => JSON.stringify(item))).size).toBe(1);
    const count = await page.evaluate(() => headerEvents.length);
    await page.getByRole('button', { name: 'Update counter' }).click();
    await expect(page.locator('[data-counter]')).toHaveText('1');
    await page.getByRole('button', { name: 'Validate', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('required');
    await page.getByLabel('Fixture name').fill('ab');
    await page.getByRole('button', { name: 'Validate', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('at least 3');
    await page.getByLabel('Fixture name').fill('A valid input');
    await page.getByRole('button', { name: 'Validate', exact: true }).click();
    await expect(page.getByRole('alert')).toHaveCount(0);
    expect(await page.evaluate(() => headerEvents.length)).toBe(count);
    expect(await cursor.evaluate(el => getComputedStyle(el).animationName)).toBe('none');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Profile Settings');
    expect(errors).toEqual([]);
});

test('one animation per Livewire navigation, Back and Forward, including interrupted typing', async ({ page }) => {
    await observe(page);
    await page.goto('/__headers/dashboard');
    const documentIdentity = await page.evaluate(() => window.documentIdentity);
    const starts = () => page.evaluate(() => headerEvents.filter(event => event.state === 'typing' && event.text === '').length);
    await expect.poll(starts).toBe(1);
    await page.getByRole('link', { name: 'Settings', exact: true }).click();
    await expect(label(page)).toHaveAttribute('data-terminal-path', '~/settings/profile');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    await expect.poll(starts).toBe(2);
    await page.goBack();
    await expect(label(page)).toHaveAttribute('data-terminal-path', '~/dashboard');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    await expect.poll(starts).toBe(3);
    await page.goForward();
    await expect(label(page)).toHaveAttribute('data-terminal-path', '~/settings/profile');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    await expect.poll(starts).toBe(4);
    expect(await page.evaluate(() => window.documentIdentity)).toBe(documentIdentity);
});

test('motion preference changes finish immediately; responsive, light/dark and axe checks', async ({ page }) => {
    await observe(page);
    await page.goto('/__headers/settings/profile');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'typing');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    await expect(label(page).locator('[data-terminal-text]')).toHaveText('~/settings/profile');
    for (const width of [320, 390, 768, 1280, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const dimensions = await page.evaluate(() => {
            const label = document.querySelector('[data-terminal-path]');
            const box = label.querySelector('.terminal-path-visual').getBoundingClientRect();
            return { fits: box.right <= innerWidth && box.left >= 0, overflow: document.documentElement.scrollWidth > innerWidth };
        });
        expect(dimensions).toEqual({ fits: true, overflow: false });
    }
    await page.evaluate(() => document.querySelector('[data-terminal-path]').style.fontSize = '24px');
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.evaluate(() => document.querySelector('[data-terminal-path]').style.fontSize = '');
    for (const dark of [true, false]) {
        await page.evaluate(dark => {
            document.documentElement.classList.toggle('dark', dark);
            document.body.style.background = dark ? '#0a0a0a' : '#fff';
            document.body.style.color = dark ? '#fafafa' : '#171717';
        }, dark);
        const result = await new AxeBuilder({ page }).include('[data-terminal-path]').analyze();
        expect(result.violations).toEqual([]);
    }
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    await page.getByRole('link', { name: 'Dashboard', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(label(page)).toHaveAttribute('data-terminal-path', '~/dashboard');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'typing');
});

test('without JavaScript the full path and cursor remain visible', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('http://127.0.0.1:8029/__headers/dashboard');
    await expect(label(page).locator('[data-terminal-text]')).toBeVisible();
    await expect(label(page).locator('[data-terminal-text]')).toHaveText('~/dashboard');
    await expect(label(page).locator('.terminal-path-overlay .terminal-path-cursor')).toBeVisible();
    await context.close();
});

test('an in-flight Livewire morph preserves typing and the original node', async ({ page }) => {
    await observe(page);
    await page.goto('/__headers/settings/profile');
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'typing');
    await label(page).evaluate(el => { window.originalHeader = el; });
    await page.getByRole('button', { name: 'Update counter' }).click();
    await expect(page.locator('[data-counter]')).toHaveText('1');
    expect(await label(page).evaluate(el => el === window.originalHeader)).toBe(true);
    await expect(label(page)).toHaveAttribute('data-terminal-state', 'complete');
    const events = await page.evaluate(() => headerEvents.filter(event => event.state === 'typing' || event.state === 'blinking'));
    const path = '~/settings/profile';
    expect(events.map(event => event.text)).toEqual(Array.from({ length: path.length + 1 }, (_, index) => path.slice(0, index)));
    expect(await label(page).evaluate(el => el.getAnimations({ subtree: true }).length)).toBe(0);
    await page.screenshot({ path: 'storage/app/header-checks/desktop.png' });
    await page.setViewportSize({ width: 320, height: 700 });
    await page.screenshot({ path: 'storage/app/header-checks/mobile.png' });
});
