import { test, expect } from "./fixtures.js";
import AxeBuilder from "@axe-core/playwright";
import { mkdirSync } from "node:fs";
import { registerUnverified, verificationLink } from "./support.js";

test("registration verification: notice, gates, resend, invalid link, verification and logout", async ({ page, context }) => {
    const errors = [];
    page.on("pageerror", error => errors.push(error.message));
    const { email } = await registerUnverified(page, "verification");
    await expect(page.getByRole("heading", { name: "Verify your email", exact: true })).toBeVisible();
    await expect(page.getByText(email, { exact: true })).toBeVisible();
    for (const path of ["/dashboard", "/tracking", "/journal", "/habits", "/categories", "/people", "/settings/profile", "/settings/security"]) {
        await page.goto(path);
        await expect(page).toHaveURL(/\/email\/verify$/);
        await expect(page.getByRole("button", { name: "Account menu", exact: true })).toHaveCount(0);
    }
    mkdirSync("storage/app/email-verification-checks", { recursive: true });
    for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 960 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect((await page.getByRole("button", { name: "Resend verification email", exact: true }).boundingBox()).height).toBeGreaterThanOrEqual(44);
        const scan = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa"]).analyze();
        expect(scan.violations).toEqual([]);
        if (width === 390 || width === 1440) {
            await page.screenshot({ path: `storage/app/email-verification-checks/notice-${width}.png`, fullPage: true });
        }
    }
    await page.getByRole("link", { name: "Skip to form" }).focus();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("main")).toBeFocused();
    expect((await context.request.post("/email/verification-notification", { headers: { Accept: "application/json" } })).status()).toBe(419);
    await page.getByRole("button", { name: "Resend verification email", exact: true }).click();
    const status = page.getByRole("main").getByRole("status");
    await expect(status).toContainText("A new verification link has been sent");
    await expect(status).toBeFocused();
    const link = await verificationLink(email);
    const invalid = await page.goto(`${link}tampered`);
    expect(invalid.status()).toBe(403);
    await expect(page.getByRole("alert")).toContainText("invalid or has expired");
    await expect(page.getByRole("button", { name: "Resend verification email", exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Log out", exact: true }).click();
    await expect(page).toHaveURL("http://127.0.0.1:8017/");
    await page.goto("/login");
    await page.getByLabel("Email address").fill(email);
    await page.getByLabel("Password", { exact: true }).fill("Browser-test-123!");
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(page).toHaveURL(/\/email\/verify$/);
    await page.goto(link);
    await expect(page).toHaveURL(url => url.pathname === "/dashboard");
    await page.goto(link);
    await expect(page).toHaveURL(url => url.pathname === "/dashboard");
    await page.goto("/email/verify");
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(errors).toEqual([]);
});
