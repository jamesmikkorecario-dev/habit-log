import { test, expect } from "./fixtures.js";
import { readdirSync } from "node:fs";
import { register } from "./support.js";

test("registration reports the real HTTP limit and shares it across contexts", async ({ page, context, browser }) => {
    await page.goto("/register");
    const token = await page.locator('input[name="_token"]').inputValue();
    // Real middleware counts rejected submissions too; no accounts or bypass
    // headers are needed to reproduce the exhausted budget seen in CI.
    for (let attempt = 0; attempt < 20; attempt++) {
        const response = await context.request.post("/register", {
            headers: { Accept: "application/json" },
            data: { _token: token },
        });
        expect(response.status()).toBe(422);
    }
    // Prove the HTTP server actually uses the disposable cache. Merely
    // passing LARAVEL_STORAGE_PATH is insufficient on PHP configured as GPCS.
    expect(readdirSync(process.env.HABIT_LOG_E2E_CACHE_PATH).length).toBeGreaterThan(0);

    const limited = page.waitForResponse(response =>
        new URL(response.url()).pathname === "/register" &&
        response.request().method() === "POST",
    );
    await expect(register(page, "limited")).rejects.toThrow(/Registration failed: HTTP 429/);
    const response = await limited;
    expect(response.status()).toBe(429);
    expect(Number(response.headers()["retry-after"])).toBeGreaterThan(0);
    await expect(page.getByText("Too Many Requests", { exact: true })).toBeVisible();

    const second = await browser.newContext();
    try {
        const other = await second.newPage();
        await expect(register(other, "stilllimited")).rejects.toThrow(/Registration failed: HTTP 429/);
    } finally {
        await second.close();
    }
});

test("the next scenario can register without inheriting the exhausted budget", async ({ page }) => {
    // Also runs independently. In the full file this immediately follows the
    // exhausted-budget scenario, exercising the automatic fixture boundary.
    const user = await register(page, "freshbudget");
    await expect(page.getByLabel("Username", { exact: true })).toHaveValue(user.username);
});
