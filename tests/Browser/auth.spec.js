import { test, expect } from "@playwright/test";
import { readFileSync } from "node:fs";
import { register } from "./support.js";

test("login validation, logout, password reset mail and normal CSRF", async ({
    page,
    context,
}) => {
    const { email } = await register(page, "reset");
    await page
        .getByRole("button", { name: "Account menu", exact: true })
        .click();
    await page.getByRole("menuitem", { name: "Log out", exact: true }).click();
    await expect(page).toHaveURL("http://127.0.0.1:8017/");
    await page.goto("/login");
    await page.getByLabel("Email address").fill(email);
    await page.getByLabel("Password", { exact: true }).fill("Wrong-password");
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(
        page.getByText("These credentials do not match our records.", {
            exact: true,
        }),
    ).toBeVisible();
    await page.goto("/forgot-password");
    await page.getByLabel("Email address").fill(email);
    await page
        .getByRole("button", { name: "Email password reset link", exact: true })
        .click();
    await expect(
        page.getByText("If an account matches that email, a password reset link will be sent. Please wait a minute before trying again.", {
            exact: true,
        }),
    ).toBeVisible();
    // Isolated mail transport writes only to the ignored browser-server log.
    let resetUrl;
    await expect
        .poll(
            () => {
                const mail = readFileSync(
                    "storage/app/phase7-checks/browser-server.log",
                    "utf8",
                )
                    .replace(/=\r?\n/g, "")
                    .replace(/=3D/g, "=");
                const urls = [
                    ...mail.matchAll(
                        /http:\/\/127\.0\.0\.1:8017\/reset-password\/[a-zA-Z0-9]+\?email=[^\s<"\)\]]+/g,
                    ),
                ];
                resetUrl = urls
                    .map((m) => m[0].replace(/&amp;/g, "&"))
                    .find((u) => decodeURIComponent(u).includes(email));
                return Boolean(resetUrl);
            },
            { message: "Captured reset mail for the synthetic account" },
        )
        .toBe(true);
    await page.goto(resetUrl);
    await page
        .getByLabel("Password", { exact: true })
        .fill("Reset-password-456!");
    await page
        .getByLabel("Confirm password", { exact: true })
        .fill("Reset-password-456!");
    await page
        .getByRole("button", { name: "Reset password", exact: true })
        .click();
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel("Email address").fill(email);
    await page
        .getByLabel("Password", { exact: true })
        .fill("Reset-password-456!");
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    const csrf = await context.request.post("/logout", {
        headers: { Accept: "application/json" },
    });
    expect(csrf.status()).toBe(419);
    await page.goto("/journal");
    await expect(page.locator("#journal-content")).toBeVisible();
});
