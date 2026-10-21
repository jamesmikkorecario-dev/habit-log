import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";
import { register } from "./support.js";

test("landing navigation, keyboard access, responsive layout and accessibility", async ({ page }) => {
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.emulateMedia({ colorScheme: "light", reducedMotion: "reduce" });
    await page.goto("/");
    await expect(page.locator("html")).toHaveClass("dark");
    await page.keyboard.press("Tab");
    await expect(page.getByRole("link", { name: "Skip to content" })).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("main")).toBeFocused();

    for (const width of [320, 375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
        await expect(page.getByRole("link", { name: "Get Started", exact: true })).toBeVisible();
    }
    await page.setViewportSize({ width: 1280, height: 960 });
    await page.evaluate(() => document.documentElement.style.fontSize = "200%");
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    await page.evaluate(() => document.documentElement.style.fontSize = "");
    const accessibility = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa"])
        .analyze();
    expect(accessibility.violations).toEqual([]);

    await page.getByRole("link", { name: "Features", exact: true }).click();
    await expect(page).toHaveURL(/#features$/);
    await page.getByRole("link", { name: "How it works", exact: true }).click();
    await expect(page).toHaveURL(/#how-it-works$/);
    await page.getByRole("link", { name: "Back to top" }).click();
    await expect(page).toHaveURL(/#top$/);

    for (const name of ["Register", "Get Started", "Create your account", "Log in"]) {
        await page.goto("/");
        await page.getByRole("link", { name, exact: true }).click();
        await expect(page).toHaveURL(name === "Log in" ? /\/login$/ : /\/register$/);
        await expect(page.getByLabel("Email address")).toBeVisible();
    }

    await register(page, "landing");
    await page.goto("/");
    await expect(page.getByRole("link", { name: "Get Started", exact: true })).toHaveCount(0);
    await page.getByRole("link", { name: "Dashboard", exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(errors).toEqual([]);
});
