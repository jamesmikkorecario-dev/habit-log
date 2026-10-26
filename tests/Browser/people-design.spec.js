import { test, expect } from './fixtures.js';
import { mkdirSync } from 'node:fs';
import { action, register } from './support.js';

mkdirSync('storage/app/people-design-checks', { recursive: true });

async function layouts(page, state) {
    for (const width of [375, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        await page.evaluate(() => scrollTo(0, 0));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const heading = await page.getByRole('heading', { name: 'People', exact: true }).boundingBox();
        expect(heading.x).toBe((width - Math.min(width, 1280)) / 2 + (width < 640 ? 16 : 24));
        expect((await page.getByRole('form', { name: 'Find someone', exact: true }).boundingBox()).width).toBeLessThanOrEqual(576);
        const controls = page.getByRole('group', { name: 'Your relationships', exact: true }).getByRole('button');
        await expect(controls).toHaveCount(4);
        for (const button of await controls.all()) {
            await expect(button).toBeVisible();
            expect((await button.boundingBox()).height).toBeGreaterThanOrEqual(44);
        }
        for (const button of await page.locator('[data-connection-card]').getByRole('button').all()) {
            await button.scrollIntoViewIfNeeded();
            expect((await button.boundingBox()).height).toBeGreaterThanOrEqual(44);
        }
        if (width === 390 || width === 1440) {
            await page.evaluate(() => scrollTo(0, 0));
            await page.screenshot({ path: `storage/app/people-design-checks/${state}-${width}.png`, fullPage: true });
        }
    }
}

test('people design supports username onboarding and exact lookup without a username', async ({ page, browser }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const otherContext = await browser.newContext();
    const other = await otherContext.newPage();
    const target = await register(other, 'peoplelookup');
    await page.goto('/register');
    await page.getByLabel('Name', { exact: true }).fill('New member');
    await page.getByLabel('Email address').fill(`people_new_${Date.now()}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Browser-test-123!');
    await page.getByLabel('Confirm password', { exact: true }).fill('Browser-test-123!');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await page.waitForURL(/\/(dashboard|people)$/);
    await page.goto('/people');
    await expect(page.getByRole('heading', { name: 'Set up your username', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'My profile', exact: true })).toHaveCount(0);
    await layouts(page, 'onboarding');
    const input = page.getByLabel('Exact username', { exact: true });
    const find = page.getByRole('button', { name: 'Find profile', exact: true });
    await action(page, () => find.click());
    await expect(input).toHaveAttribute('aria-invalid', 'true');
    await input.fill('---');
    await action(page, () => find.click());
    await expect(input).toHaveValue('---');
    await input.fill('missing_username');
    await action(page, () => find.click());
    await expect(page.getByText('No profile found for that username.', { exact: true })).toBeVisible();
    await input.fill(target.username.toUpperCase());
    await page.route('**/livewire-*/update', async route => {
        await new Promise(resolve => setTimeout(resolve, 500));
        await route.continue();
    });
    const refreshing = action(page, () => page.getByRole('button', { name: 'Refresh list', exact: true }).click());
    await expect(page.getByRole('button', { name: 'Refreshing…', exact: true })).toBeDisabled();
    await refreshing;
    await expect(input).toHaveValue(target.username.toUpperCase());
    await input.focus();
    await page.keyboard.press('Tab');
    await expect(find).toBeFocused();
    expect(await find.evaluate(el => getComputedStyle(el).outlineStyle !== 'none' || getComputedStyle(el).boxShadow !== 'none')).toBe(true);
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Finding…', exact: true })).toBeDisabled();
    await page.waitForURL(`**/people/${target.username}`);
    const card = page.getByRole('region', { name: 'Profile and connection', exact: true });
    await expect(card.getByRole('heading', { name: target.username, exact: true })).toBeVisible();
    await expect(card.getByRole('link', { name: 'Choose a username to follow people', exact: true })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Request to follow', exact: true })).toHaveCount(0);
    await page.goto('/people');
    await page.getByRole('link', { name: 'Go to Profile Settings', exact: true }).click();
    await page.waitForURL('**/settings/profile');
    await page.getByLabel('Username', { exact: true }).fill(`newmember${Date.now().toString().slice(-8)}`);
    await action(page, () => page.getByRole('button', { name: 'Save changes', exact: true }).click());
    await page.goto('/people');
    await expect(page.getByRole('heading', { name: 'Set up your username', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'My profile', exact: true })).toBeVisible();
    for (const [label, message] of [['Following', "You're not following anyone yet."], ['Followers', 'No followers yet.'], ['Incoming requests', 'No incoming requests.'], ['Sent requests', 'No pending requests.']]) {
        const tab = page.getByRole('button', { name: label, exact: true });
        await tab.focus();
        await action(page, () => page.keyboard.press('Enter'));
        await expect(tab).toBeFocused();
        await expect(tab).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('heading', { name: message, exact: true })).toBeVisible();
    }
    expect(errors).toEqual([]);
    await otherContext.close();
});

test('people design presents populated requests and connections with existing transitions', async ({ page, browser }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const otherContext = await browser.newContext();
    const other = await otherContext.newPage();
    other.on('pageerror', error => errors.push(error.message));
    const viewer = await register(page, 'peopledesign');
    const target = await register(other, 'connection');
    await other.getByLabel('Display name', { exact: true }).fill('A thoughtful friend with a long shared display name');
    await action(other, () => other.getByRole('button', { name: 'Save changes', exact: true }).click());
    const request = async (p, username) => {
        await p.goto(`/people/${username}`);
        await action(p, () => p.getByRole('button', { name: 'Request to follow', exact: true }).click());
    };
    await request(page, target.username);
    await expect(page.getByText('Follow request pending.', { exact: true }).first()).toBeVisible();
    await page.setViewportSize({ width: 375, height: 960 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'storage/app/people-design-checks/private-profile-375.png', fullPage: true });
    await request(other, viewer.username);
    await page.goto('/people');
    const tab = async label => action(page, () => page.getByRole('button', { name: label, exact: true }).click());
    await tab('Incoming requests');
    await expect(page.getByText('Wants to follow you · Awaiting your approval', { exact: true })).toBeVisible();
    await layouts(page, 'incoming');
    await tab('Sent requests');
    await expect(page.getByText('Request sent · Awaiting approval', { exact: true })).toBeVisible();
    await layouts(page, 'sent');
    await tab('Incoming requests');
    await action(page, () => page.getByRole('button', { name: 'Approve', exact: true }).click());
    await expect(page.getByText('Follow request approved.', { exact: true })).toBeVisible();
    await tab('Followers');
    await expect(page.getByText('Follows you', { exact: true })).toBeVisible();
    await layouts(page, 'followers');
    await other.goto('/people');
    await action(other, () => other.getByRole('button', { name: 'Incoming requests', exact: true }).click());
    await action(other, () => other.getByRole('button', { name: 'Approve', exact: true }).click());
    await tab('Following');
    await layouts(page, 'following');
    const unfollow = page.getByRole('button', { name: 'Unfollow', exact: true });
    await action(page, () => unfollow.click());
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(unfollow).toBeFocused();
    await action(page, () => unfollow.click());
    await action(page, () => page.getByRole('dialog').getByRole('button', { name: 'Confirm', exact: true }).click());
    await expect(page.getByRole('heading', { name: 'People', exact: true })).toBeFocused();
    await expect(page.getByText("You're not following anyone yet.", { exact: true })).toBeVisible();
    await tab('Followers');
    await action(page, () => page.getByRole('button', { name: 'Remove follower', exact: true }).click());
    await action(page, () => page.getByRole('dialog').getByRole('button', { name: 'Confirm', exact: true }).click());
    await expect(page.getByText('No followers yet.', { exact: true })).toBeVisible();
    await request(page, target.username);
    await request(other, viewer.username);
    await page.goto('/people');
    await tab('Incoming requests');
    await action(page, () => page.getByRole('button', { name: 'Reject', exact: true }).click());
    await expect(page.getByText('No incoming requests.', { exact: true })).toBeVisible();
    await tab('Sent requests');
    await action(page, () => page.getByRole('button', { name: 'Cancel request', exact: true }).click());
    await expect(page.getByText('No pending requests.', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
    await otherContext.close();
});
