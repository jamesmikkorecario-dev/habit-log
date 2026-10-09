import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

for (const screen of ["login", "register"]) {
    test(`auth design ${screen}: responsive layout, keyboard and accessibility`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: "light", reducedMotion: "reduce" });
        await page.goto(`/${screen}`);
        await expect(page.locator("html")).toHaveClass("dark");
        const firstInput = page.getByLabel(screen === "login" ? "Email address" : "Name", { exact: true });
        await expect(firstInput).toBeFocused();
        await page.keyboard.press("Tab");
        await expect(page.getByLabel(screen === "login" ? "Password" : "Email address", { exact: true })).toBeFocused();
        const toggle = page.getByRole("button", { name: "Toggle password visibility" }).first();
        await toggle.focus();
        await page.keyboard.press("Enter");
        await expect(page.getByLabel("Password", { exact: true })).toHaveAttribute("type", "text");
        await page.keyboard.press("Space");
        await expect(page.getByLabel("Password", { exact: true })).toHaveAttribute("type", "password");
        if (screen === "register") {
            await page.getByRole("button", { name: "Toggle password visibility" }).last().click();
            await expect(page.getByLabel("Confirm password", { exact: true })).toHaveAttribute("type", "text");
        }
        await page.getByRole("link", { name: "Skip to form" }).focus();
        await page.keyboard.press("Enter");
        await expect(page.getByRole("main")).toBeFocused();
        for (const width of [320, 375, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
            await expect(page.getByRole("button", { name: screen === "login" ? "Log in" : "Create account", exact: true })).toBeVisible();
        }
        await page.setViewportSize({ width: 1280, height: 960 });
        await page.evaluate(() => document.documentElement.style.fontSize = "200%");
        expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
        await page.evaluate(() => document.documentElement.style.fontSize = "");
        const scan = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa"]).analyze();
        expect(scan.violations).toEqual([]);
        await page.getByRole("link", { name: screen === "login" ? "Sign up" : "Log in", exact: true }).click();
        await expect(page).toHaveURL(screen === "login" ? /\/register$/ : /\/login$/);
        await page.getByRole("link", { name: "Back to home", exact: true }).click();
        await expect(page.getByRole("heading", { level: 1 })).toContainText("Build habits.");
    });
}

test("auth design preserves validation, old input, submission feedback and real authentication", async ({ page }) => {
    const errors = [];
    page.on("pageerror", error => errors.push(error.message));
    const email = `authdesign${Date.now()}@example.test`;
    await page.goto("/register");
    await page.getByLabel("Name", { exact: true }).fill("Auth Design Test");
    await page.getByLabel("Email address").fill(email);
    await page.getByLabel("Password", { exact: true }).fill("Browser-test-123!");
    await page.getByLabel("Confirm password", { exact: true }).fill("Mismatch-123!");
    await page.getByRole("button", { name: "Create account", exact: true }).click();
    await expect(page.getByRole("alert").filter({ hasText: "Please check" })).toBeVisible();
    await expect(page.getByText("The password field confirmation does not match.", { exact: true })).toBeVisible();
    await expect(page.getByLabel("Name", { exact: true })).toHaveValue("Auth Design Test");
    await expect(page.getByLabel("Email address")).toHaveValue(email);
    await expect(page.getByLabel("Password", { exact: true })).toHaveValue("");
    await expect(page.getByLabel("Confirm password", { exact: true })).toHaveValue("");
    const scan = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa"]).analyze();
    expect(scan.violations).toEqual([]);

    await page.getByLabel("Password", { exact: true }).fill("Browser-test-123!");
    await page.getByLabel("Confirm password", { exact: true }).fill("Browser-test-123!");
    // Observe the submit event without navigating, then verify restoration and
    // submit normally. Playwright waits for navigation on a held native POST.
    await page.evaluate(() => document.querySelector("form").addEventListener(
        "submit", event => event.preventDefault(), { once: true },
    ));
    await page.getByRole("button", { name: "Create account", exact: true }).click();
    const submitting = page.getByRole("button", { name: "Creating account…", exact: true });
    await expect(submitting).toBeDisabled();
    await expect(page.locator("form")).toHaveAttribute("aria-busy", "true");
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent("pageshow", { persisted: true })));
    await expect(page.getByRole("button", { name: "Create account", exact: true })).toBeEnabled();
    await page.getByRole("button", { name: "Create account", exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole("button", { name: "Account menu", exact: true }).click();
    await page.getByRole("menuitem", { name: "Log out", exact: true }).click();
    await page.goto("/login");
    await page.getByLabel("Email address").fill(email);
    await page.getByLabel("Password", { exact: true }).fill("Wrong-password");
    await page.getByRole("checkbox", { name: "Remember me" }).check();
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(page.getByText("These credentials do not match our records.", { exact: true })).toBeVisible();
    await expect(page.getByLabel("Email address")).toHaveValue(email);
    await expect(page.getByRole("checkbox", { name: "Remember me" })).toBeChecked();
    await expect(page.getByLabel("Password", { exact: true })).toHaveValue("");
    await expect(page.getByRole("button", { name: "Log in", exact: true })).toBeEnabled();
    await page.getByLabel("Password", { exact: true }).fill("Browser-test-123!");
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(errors).toEqual([]);
});
