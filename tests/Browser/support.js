import { expect } from "@playwright/test";
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";

export async function action(page, perform) {
    const previous = await page.evaluate(() => {
        if (!window.__testCommits) {
            window.__testCommits = { started: 0, finished: 0 };
            window.Livewire.hook("commit", ({ succeed, fail }) => {
                window.__testCommits.started++;
                const done = () => window.__testCommits.finished++;
                succeed(done);
                fail(done);
            });
        }
        return window.__testCommits.finished;
    });
    const response = page.waitForResponse(
        (r) => r.url().includes("/livewire") && r.request().method() === "POST",
    );
    await perform();
    const result = await response;
    expect(result.status()).toBe(200);
    await result.finished();
    await expect
        .poll(() =>
            page.evaluate(
                (previous) =>
                    window.__testCommits.finished > previous &&
                    window.__testCommits.finished ===
                        window.__testCommits.started,
                previous,
            ),
        )
        .toBe(true);
    // The commit success hook fires after Livewire renders its response effects.
    await page.evaluate(
        () =>
            new Promise((resolve) =>
                requestAnimationFrame(() => requestAnimationFrame(resolve)),
            ),
    );
}

export async function registerUnverified(page, prefix = "browser") {
    const username = `${prefix}${Date.now().toString().slice(-9)}`;
    const email = `${username}@example.test`;
    await page.goto("/register");
    await page.getByLabel("Name", { exact: true }).fill("Browser Test");
    await page.getByLabel("Email address").fill(email);
    await page
        .getByLabel("Password", { exact: true })
        .fill("Browser-test-123!");
    await page
        .getByLabel("Confirm password", { exact: true })
        .fill("Browser-test-123!");
    const [response] = await Promise.all([
        page.waitForResponse(response =>
            new URL(response.url()).pathname === "/register" &&
            response.request().method() === "POST",
        ),
        page.getByRole("button", { name: "Create account", exact: true }).click(),
    ]);
    const status = response.status();
    const location = response.headers()["location"];
    const destination = location
        ? new URL(location, response.url()).pathname
        : "(none)";
    if (status !== 302 || !/^\/(email\/verify|dashboard|people)$/.test(destination)) {
        // Never include submitted values, response bodies, cookies, or URL
        // queries: status/route/retry timing are enough to diagnose rejection.
        throw new Error(
            `Registration failed: HTTP ${status}; redirect ${destination}; ` +
            `Retry-After ${response.headers()["retry-after"] ?? "(none)"}. ` +
            "Expected a redirect to the email verification process.",
        );
    }
    await page.waitForURL(/\/email\/verify$/);
    return { username, email };
}

export async function verificationLink(email) {
    if (process.env.HABIT_LOG_E2E !== "1") {
        throw new Error("Verification mail capture requires the isolated browser runner.");
    }
    const hash = createHash("sha1").update(email).digest("hex");
    let link;
    await expect.poll(() => {
        const mail = readFileSync("storage/app/phase7-checks/browser-server.log", "utf8")
            .replace(/=\r?\n/g, "").replace(/=3D/g, "=");
        link = [...mail.matchAll(/http:\/\/127\.0\.0\.1:8017\/email\/verify\/\d+\/([a-f0-9]{40})\?[^\s<"\)\]]+/g)]
            .filter(match => match[1] === hash).map(match => match[0].replace(/&amp;/g, "&")).at(-1);
        return Boolean(link);
    }, { message: "Captured verification mail for the synthetic account" }).toBe(true);
    return link;
}

export async function register(page, prefix = "browser") {
    const { username, email } = await registerUnverified(page, prefix);
    await page.goto(await verificationLink(email));
    await page.waitForURL(url => /^\/(dashboard|people)$/.test(url.pathname));
    await page.goto("/settings/profile");
    await page.getByLabel("Username", { exact: true }).fill(username);
    await action(page, () =>
        page.getByRole("button", { name: "Save changes", exact: true }).click(),
    );
    return { username, email };
}

export async function createHabit(
    page,
    type = "boolean",
    name = `Browser ${type}`,
) {
    await page.goto("/habits/create");
    await page.getByLabel("Habit name", { exact: true }).fill(name);
    await page
        .getByLabel("Category", { exact: true })
        .selectOption({ index: 1 });
    if (type !== "boolean")
        await action(page, () =>
            page
                .getByLabel("Tracking type", { exact: true })
                .selectOption(type),
        );
    if (type === "checkpoint") {
        await action(page, () =>
            page
                .getByRole("button", { name: "Add checkpoint", exact: true })
                .click(),
        );
        await page.getByLabel("Checkpoint 1", { exact: true }).fill("Read");
    }
    await expect(
        page.getByLabel("Visibility preference", { exact: true }),
    ).toHaveValue("private");
    await page.getByRole("button", { name: "Save habit", exact: true }).click();
    await page.waitForURL(/\/habits\/\d+$/);
    return page.url().split("/").pop();
}
