import { mkdirSync, realpathSync, rmSync } from "node:fs";
import { isAbsolute, relative, resolve, sep } from "node:path";

export function resetTestCache(env = process.env) {
    const cachePath = env.HABIT_LOG_E2E_CACHE_PATH;
    if (env.HABIT_LOG_E2E !== "1" || !cachePath || !isAbsolute(cachePath)) {
        throw new Error("Browser cache isolation requires the opted-in E2E runner.");
    }

    // Resolve symlinks before validating: only this runner's disposable file
    // cache may be cleared, never the application's regular storage/cache.
    const cache = realpathSync(cachePath);
    const root = realpathSync(resolve("storage/app/phase7-checks"));
    const parts = relative(root, cache).split(sep);
    if (
        parts.length !== 4 ||
        !/^runtime-[a-zA-Z0-9]+$/.test(parts[0]) ||
        parts.slice(1).join("/") !== "framework/cache/data"
    ) {
        throw new Error("Refusing to clear a cache outside an isolated browser runtime.");
    }

    rmSync(cache, { recursive: true });
    mkdirSync(cache, { recursive: true });
}
