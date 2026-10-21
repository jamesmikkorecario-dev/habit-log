import { test, expect } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";
import { action, register, createHabit } from "./support.js";

for (const type of [
    "boolean",
    "count",
    "duration",
    "checkpoint",
    "avoidance",
]) {
    test(`${type}: recording, reload and N/A preserve private input`, async ({
        page,
    }) => {
        await register(page, type);
        const id = await createHabit(page, type);
        await page.goto(
            `/habits/${id}/entries/${new Date().toISOString().slice(0, 10)}`,
        );
        if (type === "checkpoint")
            await page
                .getByRole("checkbox", { name: "Read", exact: true })
                .check();
        else
            await action(page, () =>
                page
                    .getByLabel((["count", "duration", "avoidance"].includes(type) ? "Your assessment" : "Your outcome"), { exact: true })
                    .selectOption(type === "boolean" ? "completed" : "partial"),
            );
        if (["count", "duration", "avoidance"].includes(type))
            await page.locator("#entry-value").fill("0");
        await page.locator("#entry-note").fill("Private browser note");
        await action(page, () =>
            page
                .getByRole("button", { name: "Save activity", exact: true })
                .click(),
        );
        await expect(
            page.getByText("Activity saved.", { exact: true }),
        ).toBeVisible();
        await page.reload();
        await expect(page.locator("#entry-note")).toHaveValue(
            "Private browser note",
        );
        if (type === "checkpoint")
            await expect(
                page.getByRole("checkbox", { name: "Read", exact: true }),
            ).toBeChecked();
        if (["count", "duration", "avoidance"].includes(type))
            await expect(page.locator("#entry-value")).toHaveValue("0");
        await action(page, () =>
            page
                .getByLabel(
                    type === "checkpoint" ? "Applicability" : (["count", "duration", "avoidance"].includes(type) ? "Your assessment" : "Your outcome"),
                    { exact: true },
                )
                .selectOption("not_applicable"),
        );
        await action(page, () =>
            page
                .getByRole("button", { name: "Save activity", exact: true })
                .click(),
        );
        await page.reload();
        await expect(
            page.getByLabel(
                type === "checkpoint" ? "Applicability" : (["count", "duration", "avoidance"].includes(type) ? "Your assessment" : "Your outcome"),
                { exact: true },
            ),
        ).toHaveValue("not_applicable");
    });
}

test("journal save, stale draft, confirmed deletion and navigation guard", async ({
    page,
    context,
}) => {
    await register(page, "journal");
    await page.goto("/journal");
    await page.locator("#journal-content").fill("First private journal");
    await page.locator("#journal-mood").selectOption("3");
    await action(page, () =>
        page.getByRole("button", { name: "Save journal", exact: true }).click(),
    );
    const other = await context.newPage();
    await other.goto("/journal");
    await page.locator("#journal-content").fill("New saved journal");
    await action(page, () =>
        page.getByRole("button", { name: "Save journal", exact: true }).click(),
    );
    await other.locator("#journal-content").fill("Stale draft remains");
    await action(other, () =>
        other
            .getByRole("button", { name: "Save journal", exact: true })
            .click(),
    );
    await expect(
        other.getByText(
            "This journal or your timezone changed. Reload the journal before saving again.",
            { exact: true },
        ),
    ).toBeVisible();
    await expect(other.locator("#journal-content")).toHaveValue(
        "Stale draft remains",
    );
    await Promise.all([
        other.waitForEvent("dialog").then((d) => d.dismiss()),
        other.getByRole("link", { name: "People", exact: true }).click(),
    ]);
    await expect(other).toHaveURL(/\/journal$/);
    await expect(other.locator("#journal-content")).toHaveValue(
        "Stale draft remains",
    );
    await Promise.all([
        other.waitForEvent("dialog").then((d) => d.accept()),
        other.getByRole("link", { name: "People", exact: true }).click(),
    ]);
    await expect(other).toHaveURL(/\/people$/);
    await page
        .getByRole("button", { name: "Delete journal", exact: true })
        .click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(
        page.getByRole("button", { name: "Delete journal", exact: true }),
    ).toBeFocused();
    await page
        .getByRole("button", { name: "Delete journal", exact: true })
        .click();
    await action(page, () =>
        page
            .getByRole("dialog")
            .getByRole("button", {
                name: "Permanently delete journal",
                exact: true,
            })
            .click(),
    );
    await expect(page.locator("#journal-content")).toHaveValue("");
    await expect(page.locator("#journal-mood")).toHaveValue("");
    await other.close();
});

test("accessible forms and responsive application shell", async ({
    page,
}, testInfo) => {
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto("/login");
    const results = [];
    async function scan(path) {
        await page.goto(path);
        const result = await new AxeBuilder({ page })
            .withTags(["wcag2a", "wcag2aa", "wcag21aa"])
            .analyze();
        results.push({
            path,
            violations: result.violations.map((v) => ({
                id: v.id,
                impact: v.impact,
                nodes: v.nodes.map((n) => n.target),
            })),
        });
    }
    await scan("/login");
    await register(page, "access");
    const id = await createHabit(page, "count", "Accessible practice");
    for (const path of [
        "/dashboard",
        "/settings/profile",
        "/categories",
        "/habits",
        "/habits/create",
        `/habits/${id}`,
        `/habits/${id}/entries/${new Date().toISOString().slice(0, 10)}`,
        "/tracking",
        "/journal",
        "/people",
    ]) {
        await scan(path);
        for (const width of [320, 375, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            await expect
                .poll(() =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth <= innerWidth,
                    ),
                )
                .toBe(true);
        }
        await page.setViewportSize({ width: 1280, height: 960 });
        await page.evaluate(() => {
            document.documentElement.style.fontSize = "200%";
        });
        await expect
            .poll(() =>
                page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            )
            .toBe(true);
        await page.evaluate(() => {
            document.documentElement.style.fontSize = "";
        });
    }
    await testInfo.attach("accessibility", {
        body: JSON.stringify(results, null, 2),
        contentType: "application/json",
    });
    expect(
        results.flatMap((r) =>
            r.violations.map((v) => ({ path: r.path, ...v })),
        ),
    ).toEqual([]);
    expect(errors).toEqual([]);
});
