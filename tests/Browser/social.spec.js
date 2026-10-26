import { test, expect } from "./fixtures.js";
import { action } from "./support.js";
const base = "http://127.0.0.1:8017";
async function register(p, username) {
    await p.goto(base + "/register");
    await p
        .getByLabel("Name", { exact: true })
        .fill("Private Registration Name");
    await p.getByLabel("Email address").fill(username + "@example.test");
    await p.getByLabel("Password", { exact: true }).fill("Browser-test-123!");
    await p
        .getByLabel("Confirm password", { exact: true })
        .fill("Browser-test-123!");
    await p
        .getByRole("button", { name: "Create account", exact: true })
        .click();
    await p.waitForURL(/\/(dashboard|people)$/);
    await p.goto(base + "/settings/profile");
    await p.getByLabel("Username", { exact: true }).fill(username);
    await p.getByLabel("Bio", { exact: true }).fill("PRIVATE GATED BIO");
    await action(p, () =>
        p.getByRole("button", { name: "Save changes", exact: true }).click(),
    );
}
async function habit(p, name, visibility) {
    await p.goto(base + "/habits/create");
    await p.getByLabel("Habit name", { exact: true }).fill(name);
    await p.getByLabel("Category", { exact: true }).selectOption({ index: 1 });
    await p
        .getByLabel("Visibility preference", { exact: true })
        .selectOption(visibility);
    await p.getByRole("button", { name: "Save habit", exact: true }).click();
    await p.waitForURL(/\/habits\/\d+$/);
    return p.url().split("/").pop();
}
async function privacy(p, value) {
    await p.goto(base + "/settings/profile");
    const box = p.getByRole("checkbox", {
        name: "Private account",
        exact: true,
    });
    if ((await box.isChecked()) !== value) await box.click();
    await action(p, () =>
        p.getByRole("button", { name: "Save changes", exact: true }).click(),
    );
}

test("directional relationships, shared privacy and revocation", async ({
    browser,
}) => {
    test.setTimeout(180_000);
    const checks = [],
        errors = [];
    async function check(name, predicate = async () => true) {
        await expect.poll(predicate, { message: name }).toBeTruthy();
        checks.push(name);
    }
    const contexts = await Promise.all(
        [1, 2, 3].map(() =>
            browser.newContext({
                viewport: { width: 1280, height: 1000 },
                reducedMotion: "reduce",
            }),
        ),
    );
    const pages = await Promise.all(contexts.map((c) => c.newPage()));
    for (const p of pages) p.on("pageerror", (e) => errors.push(e.message));
    const [owner, viewer, other] = pages;
    const suffix = Date.now().toString().slice(-8),
        on = "owner" + suffix,
        vn = "viewer" + suffix,
        tn = "other" + suffix;
    await viewer.goto(base + "/people");
    await check("Guests must sign in", async () => (viewer.url().endsWith("/login")));
    await register(owner, on);
    await register(viewer, vn);
    await register(other, tn);
    await check("Accounts and usernames save");
    const pub = await habit(owner, "Public browser practice", "public"),
        fol = await habit(owner, "Followers browser practice", "followers"),
        pri = await habit(owner, "PRIVATE HABIT CANARY", "private");
    await check("Three habit visibility choices save");
    await owner.goto(base + "/journal");
    await owner.locator("#journal-content").fill("PRIVATE JOURNAL CANARY");
    await owner.locator("#journal-mood").selectOption("5");
    await action(owner, () =>
        owner
            .getByRole("button", { name: "Save journal", exact: true })
            .click(),
    );
    const today = new Date().toISOString().slice(0, 10);
    await owner.goto(base + "/habits/" + pub + "/entries/" + today);
    await action(owner, () =>
        owner
            .getByLabel("Your outcome", { exact: true })
            .selectOption("completed"),
    );
    await owner.locator("#entry-note").fill("PRIVATE NOTE CANARY");
    await action(owner, () =>
        owner
            .getByRole("button", { name: "Save activity", exact: true })
            .click(),
    );
    await check("Owner daily recording remains usable");
    await viewer.goto(base + "/people");
    await viewer
        .getByLabel("Exact username", { exact: true })
        .fill(on.toUpperCase());
    await viewer
        .getByRole("button", { name: "Find profile", exact: true })
        .click();
    await viewer.waitForURL("**/people/" + on);
    await check(
        "Exact username lookup reaches private header", async () => ((await viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .isVisible()) &&
            !(await viewer.locator("main").innerText()).includes(
                "PRIVATE GATED BIO",
            ) &&
            !(await viewer.content()).includes("Public browser practice")));
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .click(),
    );
    await check(
        "Private follow stays pending", async () => (await viewer
            .getByRole("button", { name: "Cancel request", exact: true })
            .isVisible()));
    await privacy(owner, false);
    await viewer.reload();
    await check(
        "Public switch preserves pending request", async () => ((await viewer
            .getByRole("button", { name: "Cancel request", exact: true })
            .isVisible()) &&
            (await viewer
                .getByRole("link", {
                    name: "Public browser practice",
                    exact: true,
                })
                .isVisible()) &&
            !(await viewer.content()).includes("Followers browser practice")));
    await other.goto(base + "/people/" + on);
    await action(other, () =>
        other.getByRole("button", { name: "Follow", exact: true }).click(),
    );
    await check(
        "New public follows accept immediately", async () => (await other
            .getByRole("button", { name: "Unfollow", exact: true })
            .isVisible()));
    await privacy(owner, true);
    await viewer.reload();
    await check(
        "Private switch hides public habit from pending requester", async () => (!(await viewer.content()).includes("Public browser practice")));
    await other.reload();
    await check(
        "Accepted follower survives private switch", async () => (await other
            .getByRole("link", {
                name: "Followers browser practice",
                exact: true,
            })
            .isVisible()));
    await owner.goto(base + "/people");
    await action(owner, () =>
        owner
            .getByRole("button", { name: "Incoming requests", exact: true })
            .click(),
    );
    await action(owner, () =>
        owner.getByRole("button", { name: "Approve", exact: true }).click(),
    );
    await viewer.reload();
    await check(
        "Approval grants permitted habits", async () => ((await viewer
            .getByRole("link", { name: "Public browser practice", exact: true })
            .isVisible()) &&
            (await viewer
                .getByRole("link", {
                    name: "Followers browser practice",
                    exact: true,
                })
                .isVisible())));
    for (const width of [320, 375, 768, 1024]) {
        await viewer.setViewportSize({ width, height: 950 });
        await check(
            "Shared profile fits " + width, async () => (await viewer.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            )));
    }
    await viewer.setViewportSize({ width: 375, height: 950 });
    await viewer.setViewportSize({ width: 1280, height: 1000 });
    await check(
        "Shared profile excludes private habit and journal", async () => (!(await viewer.content()).includes("PRIVATE HABIT CANARY") &&
            !(await viewer.content()).includes("PRIVATE JOURNAL CANARY")));
    await viewer
        .getByRole("link", { name: "Public browser practice", exact: true })
        .click();
    await viewer.waitForURL("**/people/" + on + "/habits/" + pub);
    await check(
        "Shared habit is read only", async () => (!(await viewer.content()).includes("PRIVATE NOTE CANARY") &&
            (await viewer
                .getByText("Read-only activity.", { exact: false })
                .isVisible()) &&
            (await viewer
                .getByRole("link", { name: "Edit habit", exact: true })
                .count()) === 0));
    const cell = viewer.locator('[data-date="' + today + '"]');
    await cell.focus();
    await action(viewer, () => viewer.keyboard.press("Enter"));
    await check(
        "Shared heatmap keyboard details", async () => ((await viewer
            .getByRole("button", { name: "Close details", exact: true })
            .isVisible()) &&
            (await viewer
                .getByRole("link", { name: "Open daily entry", exact: true })
                .count()) === 0));
    await viewer
        .getByRole("button", { name: "Close details", exact: true })
        .click();
    await check(
        "Calendar focus restored", async () => (await cell.evaluate((el) => el === document.activeElement)));
    for (const width of [320, 375, 768, 1024]) {
        await viewer.setViewportSize({ width, height: 950 });
        await check(
            "Shared habit fits " + width, async () => (await viewer.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            )));
    }
    await viewer.setViewportSize({ width: 1280, height: 1000 });
    const denied = await viewer.goto(base + "/habits/" + pub + "/edit");
    await check("Followers cannot edit owner habits", async () => (denied.status() === 404));
    const hidden = await viewer.goto(base + "/people/" + on + "/habits/" + pri);
    await check("Direct private habit denied", async () => (hidden.status() === 404));
    await viewer.goto(base + "/people/" + on);
    await action(viewer, () =>
        viewer.getByRole("button", { name: "Unfollow", exact: true }).click(),
    );
    const dialog = viewer.getByRole("dialog", {
        name: "Unfollow " + on + "?",
        exact: true,
    });
    await dialog.waitFor();
    await check(
        "Unfollow dialog names account", async () => (await dialog
            .getByText("This ends this follow relationship.", { exact: false })
            .isVisible()));
    await viewer.keyboard.press("Escape");
    await dialog.waitFor({ state: "hidden" });
    await check(
        "Escape keeps relationship and focus", async () => (await viewer
            .getByRole("button", { name: "Unfollow", exact: true })
            .evaluate((el) => el === document.activeElement)));
    await action(viewer, () =>
        viewer.getByRole("button", { name: "Unfollow", exact: true }).click(),
    );
    await action(viewer, () =>
        viewer
            .getByRole("dialog")
            .getByRole("button", { name: "Confirm", exact: true })
            .click(),
    );
    await expect(
        viewer.getByRole("button", { name: "Request to follow", exact: true }),
    ).toBeVisible();
    await check(
        "Confirmed unfollow revokes private activity", async () => ((await viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .isVisible()) &&
            !(await viewer.content()).includes("Public browser practice")));
    await expect(viewer.locator("#people-heading")).toBeFocused();
    await check(
        "Removed trigger focus moves to heading", async () => (await viewer
            .locator("#people-heading")
            .evaluate((el) => el === document.activeElement)));
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .click(),
    );
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Cancel request", exact: true })
            .click(),
    );
    await check(
        "Request cancellation persists", async () => (await viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .isVisible()));
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .click(),
    );
    await owner.goto(base + "/people");
    await action(owner, () =>
        owner
            .getByRole("button", { name: "Incoming requests", exact: true })
            .click(),
    );
    await action(owner, () =>
        owner.getByRole("button", { name: "Reject", exact: true }).click(),
    );
    await viewer.reload();
    await check(
        "Request rejection persists", async () => (await viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .isVisible()));
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .click(),
    );
    await action(owner, () =>
        owner
            .getByRole("button", { name: "Refresh list", exact: true })
            .click(),
    );
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Cancel request", exact: true })
            .click(),
    );
    await action(viewer, () =>
        viewer
            .getByRole("button", { name: "Request to follow", exact: true })
            .click(),
    );
    await action(owner, () =>
        owner.getByRole("button", { name: "Approve", exact: true }).click(),
    );
    await check(
        "Stale approval rejects replacement request", async () => (await owner
            .getByText(
                "This relationship changed. Refresh the list before trying again.",
                { exact: true },
            )
            .isVisible()));
    await viewer.reload();
    await check(
        "Replacement request remains pending", async () => (await viewer
            .getByRole("button", { name: "Cancel request", exact: true })
            .isVisible()));
    await action(owner, () =>
        owner.getByRole("button", { name: "Reject", exact: true }).click(),
    );
    await action(owner, () =>
        owner.getByRole("button", { name: "Followers", exact: true }).click(),
    );
    await action(owner, () =>
        owner
            .getByRole("button", { name: "Remove follower", exact: true })
            .click(),
    );
    await action(owner, () =>
        owner
            .getByRole("dialog")
            .getByRole("button", { name: "Confirm", exact: true })
            .click(),
    );
    await other.reload();
    await check(
        "Owner removal revokes follower access", async () => (await other
            .getByRole("button", { name: "Request to follow", exact: true })
            .isVisible()));
    await viewer.goto(base + "/people");
    for (const width of [320, 375, 768, 1024]) {
        await viewer.setViewportSize({ width, height: 950 });
        await check(
            "No page overflow at " + width, async () => (await viewer.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            )));
        if (
            await viewer
                .getByRole("button", { name: "Toggle navigation", exact: true })
                .isVisible()
        ) {
            await viewer
                .getByRole("button", { name: "Toggle navigation", exact: true })
                .click();
            await viewer
                .getByRole("link", { name: "People", exact: true })
                .click();
            await viewer
                .getByRole("button", { name: "Toggle navigation", exact: true })
                .filter({ has: viewer.locator("svg") })
                .waitFor();
            await check(
                "Mobile People menu closes at " + width, async () => ((await viewer
                    .getByRole("button", {
                        name: "Toggle navigation",
                        exact: true,
                    })
                    .getAttribute("aria-expanded")) === "false"));
        }
    }
    await viewer.setViewportSize({ width: 375, height: 950 });
    await viewer.setViewportSize({ width: 1280, height: 1000 });
    await viewer.goto(base + "/people/" + on);
    const updateUri = await viewer
            .locator("script[data-update-uri]")
            .getAttribute("data-update-uri"),
        snapshot = await viewer
            .locator("[wire\\:snapshot]")
            .first()
            .getAttribute("wire:snapshot");
    const deniedCsrf = await contexts[1].request.post(
        new URL(updateUri, base).href,
        {
            headers: { Accept: "application/json", "X-Livewire": "true" },
            data: {
                components: [
                    {
                        snapshot,
                        updates: {},
                        calls: [
                            {
                                path: "",
                                method: "refreshRelationships",
                                params: [],
                            },
                        ],
                    },
                ],
            },
        },
    );
    await check("Social update requires CSRF", async () => (deniedCsrf.status() === 419));
    await viewer.goto(base + "/journal");
    await viewer.locator("#journal-content").fill("Unsaved journal draft");
    await Promise.all([
        viewer.waitForEvent("dialog").then((d) => d.dismiss()),
        viewer.getByRole("link", { name: "People", exact: true }).click(),
    ]);
    await check(
        "Journal draft guard covers People navigation", async () => (viewer.url().endsWith("/journal") &&
            (await viewer.locator("#journal-content").inputValue()) ===
                "Unsaved journal draft"));
    await Promise.all([
        viewer.waitForEvent("dialog").then((d) => d.accept()),
        viewer.getByRole("link", { name: "People", exact: true }).click(),
    ]);
    await viewer.waitForURL("**/people");
    await check(
        "Reduced motion honored", async () => (await viewer.evaluate(
            () => matchMedia("(prefers-reduced-motion: reduce)").matches,
        )));
    await check("No JavaScript errors", async () => (errors.length === 0));
    await Promise.all(contexts.map((c) => c.close()));
});
