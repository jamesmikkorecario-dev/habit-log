import { test as base, expect } from "@playwright/test";
import { resetTestCache } from "./cache-state.js";

// All contexts in a scenario share real rate limits. Only the next scenario
// gets a fresh cache, under the runner's existing single-worker/process lock.
export const test = base.extend({
    isolatedApplicationCache: [
        async ({}, use) => {
            resetTestCache();
            await use();
        },
        { auto: true },
    ],
});

export { expect };
