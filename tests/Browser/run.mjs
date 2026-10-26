import { spawn, spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, openSync, closeSync, rmSync } from "node:fs";
import { resolve } from "node:path";
import net from "node:net";

// This entry point runs under the same flock used by PHPUnit (npm script).
if (process.env.HABIT_LOG_E2E !== "1")
    throw new Error("Set HABIT_LOG_E2E=1 to allow resetting habit_log_test.");
const port = 8017;
await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once("error", () =>
        reject(
            new Error(
                `Port ${port} is occupied; no existing server will be reused or stopped.`,
            ),
        ),
    );
    probe.listen(port, "127.0.0.1", () => probe.close(resolve));
});
mkdirSync("storage/app/phase7-checks", { recursive: true });
const runtime = mkdtempSync(resolve("storage/app/phase7-checks/runtime-"));
for (const directory of [
    "framework/cache/data",
    "framework/sessions",
    "framework/views",
    "logs",
    "app/private",
]) {
    mkdirSync(resolve(runtime, directory), { recursive: true });
}
const env = {
    ...process.env,
    APP_ENV: "local",
    APP_DEBUG: "false",
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: "mysql",
    DB_DATABASE: "habit_log_test",
    DB_URL: "",
    SESSION_DRIVER: "database",
    MAIL_MAILER: "log",
    LOG_CHANNEL: "stderr",
    LOG_LEVEL: "debug",
    CACHE_STORE: "file",
    LARAVEL_STORAGE_PATH: runtime,
};
const setup = spawnSync("php", ["tests/Fixtures/browser-setup.php"], {
    env,
    stdio: "inherit",
});
if (setup.status !== 0) {
    rmSync(runtime, { recursive: true, force: true });
    process.exit(setup.status || 1);
}
const log = openSync("storage/app/phase7-checks/browser-server.log", "w");
const server = spawn(
    "php",
    [
        // Laravel reads LARAVEL_STORAGE_PATH from $_ENV/$_SERVER. PHP's web
        // SAPI omits it with GPCS, even when getenv() sees the runner's value.
        "-d", "variables_order=EGPCS",
        "-S", `127.0.0.1:${port}`,
        resolve("vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"),
    ],
    { cwd: resolve("public"), env, stdio: ["ignore", log, log], detached: true },
);
let runner;
function stop() {
    if (runner && runner.exitCode === null) runner.kill("SIGTERM");
    try {
        process.kill(-server.pid, "SIGTERM");
    } catch {}
}
process.on("SIGINT", () => {
    stop();
    process.exitCode = 130;
});
process.on("SIGTERM", () => {
    stop();
    process.exitCode = 143;
});
try {
    let ready = false;
    for (let i = 0; i < 100; i++) {
        if (server.exitCode !== null)
            throw new Error(
                "Browser preview exited; inspect browser-server.log.",
            );
        try {
            if ((await fetch(`${env.APP_URL}/up`)).ok) {
                ready = true;
                break;
            }
        } catch {}
        await new Promise((r) => setTimeout(r, 100));
    }
    if (!ready) throw new Error("Browser preview did not become healthy.");
    // A Windows Node executable can launch installed Windows Chrome from WSL.
    const executable = process.env.E2E_NODE || process.execPath;
    const windows = executable.endsWith(".exe");
    const toRunnerPath = (p) =>
        windows
            ? spawnSync("wslpath", ["-w", resolve(p)], {
                  encoding: "utf8",
              }).stdout.trim()
            : resolve(p);
    runner = spawn(
        executable,
        [
            toRunnerPath("node_modules/@playwright/test/cli.js"),
            "test",
            "--config",
            toRunnerPath("playwright.config.js"),
            ...process.argv.slice(2),
        ],
        {
            stdio: "inherit",
            env: {
                ...process.env,
                HABIT_LOG_E2E_CACHE_PATH: toRunnerPath(resolve(runtime, "framework/cache/data")),
                ...(windows ? {
                    // WSL does not forward arbitrary environment variables
                    // when launching the optional Windows Node executable.
                    WSLENV: [process.env.WSLENV, "HABIT_LOG_E2E", "HABIT_LOG_E2E_CACHE_PATH"]
                        .filter(Boolean).join(":"),
                } : {}),
            },
        },
    );
    process.exitCode = await new Promise((resolve, reject) => {
        runner.once("error", reject);
        runner.once("exit", (code) => resolve(code ?? 1));
    });
} finally {
    stop();
    closeSync(log);
    rmSync(runtime, { recursive: true, force: true });
}
