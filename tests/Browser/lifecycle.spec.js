import { test, expect } from "@playwright/test";
import { action, register, createHabit } from "./support.js";

test("custom category review and archive, trash, restore preserve the habit", async ({
    page,
}) => {
    await register(page, "lifecycle");
    await page.goto("/categories");
    await page
        .getByLabel("Category name", { exact: true })
        .fill("Browser category");
    await action(page, () =>
        page
            .getByRole("button", {
                name: "Check for similar categories",
                exact: true,
            })
            .click(),
    );
    await page
        .getByRole("button", { name: "Create my category", exact: true })
        .click();
    await page.waitForURL(/\/habits\/create\?category=\d+$/);
    await expect(page.getByLabel("Category", { exact: true })).not.toHaveValue(
        "",
    );
    const id = await createHabit(page, "boolean", "Lifecycle practice");
    await page.goto("/habits");
    await action(page, () =>
        page
            .getByRole("button", {
                name: "Archive Lifecycle practice",
                exact: true,
            })
            .click(),
    );
    await action(page, () =>
        page.getByRole("button", { name: "Archived", exact: true }).click(),
    );
    await expect(
        page.getByRole("link", { name: "Lifecycle practice", exact: true }),
    ).toBeVisible();
    await action(page, () =>
        page
            .getByRole("button", {
                name: "Move Lifecycle practice to trash",
                exact: true,
            })
            .click(),
    );
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(
        page.getByRole("button", {
            name: "Move Lifecycle practice to trash",
            exact: true,
        }),
    ).toBeFocused();
    await action(page, () =>
        page
            .getByRole("button", {
                name: "Move Lifecycle practice to trash",
                exact: true,
            })
            .click(),
    );
    await action(page, () =>
        page
            .getByRole("button", { name: "Confirm move to trash", exact: true })
            .click(),
    );
    await action(page, () =>
        page.getByRole("button", { name: "Trash", exact: true }).click(),
    );
    await action(page, () =>
        page
            .getByRole("button", {
                name: "Restore Lifecycle practice",
                exact: true,
            })
            .click(),
    );
    await action(page, () =>
        page.getByRole("button", { name: "Archived", exact: true }).click(),
    );
    await expect(
        page.getByRole("link", { name: "Lifecycle practice", exact: true }),
    ).toBeVisible();
    await action(page, () =>
        page
            .getByRole("button", {
                name: "Unarchive Lifecycle practice",
                exact: true,
            })
            .click(),
    );
    await action(page, () =>
        page.getByRole("button", { name: "Active", exact: true }).click(),
    );
    await page
        .getByRole("link", { name: "Lifecycle practice", exact: true })
        .click();
    await expect(page).toHaveURL(new RegExp(`/habits/${id}$`));
});
