import { test, expect } from "@playwright/test";
import { action, register, createHabit } from "./support.js";

test("known numeric score, historical setup, stale activity and heatmap navigation", async ({
    page,
    context,
}) => {
    await register(page, "scores");
    const id = await createHabit(page, "count", "Measured practice");
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel("Goal (optional)", { exact: true }).fill("10");
    await page.getByRole("button", { name: "Save habit", exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    const today = new Date().toISOString().slice(0, 10);
    await page.getByRole("link", { name: "Record today", exact: true }).click();
    await action(page, () =>
        page
            .getByLabel("Your assessment", { exact: true })
            .selectOption("completed"),
    );
    await page.locator("#entry-value").fill("5");
    await action(page, () =>
        page
            .getByRole("button", { name: "Save activity", exact: true })
            .click(),
    );
    const stale = await context.newPage();
    await stale.goto(`/habits/${id}/entries/${today}`);
    await page.locator("#entry-note").fill("New saved note");
    await action(page, () =>
        page
            .getByRole("button", { name: "Save activity", exact: true })
            .click(),
    );
    await stale.locator("#entry-note").fill("Stale activity draft");
    await action(stale, () =>
        stale
            .getByRole("button", { name: "Save activity", exact: true })
            .click(),
    );
    await expect(
        stale.getByText(
            "This day or its settings changed. Reload the entry before saving again.",
            { exact: true },
        ),
    ).toBeVisible();
    await expect(stale.locator("#entry-note")).toHaveValue(
        "Stale activity draft",
    );
    await stale.close();
    await page.goto(`/habits/${id}/edit`);
    await page.getByLabel("Goal (optional)", { exact: true }).fill("20");
    await page.getByRole("button", { name: "Save habit", exact: true }).click();
    await page.waitForURL(new RegExp(`/habits/${id}$`));
    const cell = page.locator(`[data-date="${today}"]`);
    await expect(cell).toHaveAttribute("aria-label", /50\.0% performance/);
    await cell.focus();
    await action(page, () => page.keyboard.press("Enter"));
    await expect(
        page.getByText("50.0% performance", { exact: true }),
    ).toBeVisible();
    await expect(page.getByText(/Historical goal\/limit: 10/)).toBeVisible();
    await page
        .getByRole("button", { name: "Close details", exact: true })
        .click();
    await expect(cell).toBeFocused();
    const year = Number(today.slice(0, 4));
    await action(page, () =>
        page
            .getByRole("button", { name: "Previous year", exact: true })
            .click(),
    );
    await expect(page.getByLabel("Year", { exact: true })).toHaveValue(
        String(year - 1),
    );
    await action(page, () =>
        page.getByRole("button", { name: "Current year", exact: true }).click(),
    );
    await expect(cell).toHaveAttribute("aria-label", /50\.0% performance/);
    await page.getByText("Accessible day list", { exact: true }).click();
    await action(page, () =>
        page.getByRole("button", { name: "Next days", exact: true }).click(),
    );
    await expect(page.getByText("Page 2 of 19", { exact: true })).toBeVisible();
    const row = page.locator('[data-focus-key^="list-"]').first();
    await action(page, () => row.click());
    await page
        .getByRole("button", { name: "Close details", exact: true })
        .click();
    await expect(row).toBeFocused();
});

test("touch emulation can open date details and use narrow navigation", async ({
    browser,
}, testInfo) => {
    const context = await browser.newContext({
        baseURL: "http://127.0.0.1:8017",
        viewport: { width: 375, height: 812 },
        hasTouch: true,
        reducedMotion: "reduce",
    });
    const page = await context.newPage();
    await register(page, "touch");
    const id = await createHabit(page);
    await page.goto(`/habits/${id}`);
    const cell = page.locator(
        `[data-date="${new Date().toISOString().slice(0, 10)}"]`,
    );
    await action(page, () => cell.tap());
    await expect(
        page.getByRole("button", { name: "Close details", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Close details", exact: true })
        .tap();
    await page
        .getByRole("button", { name: "Toggle navigation", exact: true })
        .tap();
    await page.getByRole("link", { name: "People", exact: true }).tap();
    await expect(page).toHaveURL(/\/people$/);
    await expect(
        page.getByRole("button", { name: "Toggle navigation", exact: true }),
    ).toHaveAttribute("aria-expanded", "false");
    await page.screenshot({
        path: "storage/app/phase7-checks/phase7-mobile.png",
        fullPage: true,
    });
    await testInfo.attach("browser-environment", {
        body: JSON.stringify({
            browser: browser.version(),
            touch: "emulated",
            viewport: "375x812",
        }),
        contentType: "application/json",
    });
    await context.close();
});
