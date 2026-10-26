import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { test } from "node:test";
import { resetTestCache } from "./cache-state.js";

test("cache isolation clears only disposable cache and rejects unsafe paths", () => {
    mkdirSync("storage/app/phase7-checks", { recursive: true });
    const runtime = mkdtempSync(resolve("storage/app/phase7-checks/runtime-"));
    const cache = resolve(runtime, "framework/cache/data");
    const sessions = resolve(runtime, "framework/sessions");
    const options = { HABIT_LOG_E2E: "1", HABIT_LOG_E2E_CACHE_PATH: cache };
    try {
        mkdirSync(resolve(cache, "nested"), { recursive: true });
        mkdirSync(sessions, { recursive: true });
        writeFileSync(resolve(cache, "nested/limiter"), "20");
        writeFileSync(resolve(sessions, "sentinel"), "preserved");
        assert.throws(() => resetTestCache({}), /opted-in/);
        assert.throws(() => resetTestCache({ ...options, HABIT_LOG_E2E: "0" }), /opted-in/);
        assert.throws(() => resetTestCache({ ...options, HABIT_LOG_E2E_CACHE_PATH: "storage/framework/cache/data" }), /opted-in/);
        assert.throws(() => resetTestCache({ ...options, HABIT_LOG_E2E_CACHE_PATH: sessions }), /Refusing/);
        assert.equal(readFileSync(resolve(cache, "nested/limiter"), "utf8"), "20");

        resetTestCache(options);
        assert.deepEqual(readdirSync(cache), []);
        assert.equal(readFileSync(resolve(sessions, "sentinel"), "utf8"), "preserved");
        resetTestCache(options); // Repeated test boundaries remain valid.

        rmSync(cache, { recursive: true });
        symlinkSync(sessions, cache, "junction");
        assert.throws(() => resetTestCache(options), /Refusing/);
        assert.equal(readFileSync(resolve(sessions, "sentinel"), "utf8"), "preserved");
    } finally {
        rmSync(runtime, { recursive: true, force: true });
    }
});
