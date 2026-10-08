import { defineConfig, devices } from "@playwright/test";

const browser = process.env.E2E_BROWSER || "chromium";
const installedWindowsChrome =
    process.platform === "win32" && browser === "chromium"
        ? "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe"
        : undefined;

export default defineConfig({
    testDir: "./tests/Browser",
    testMatch: "**/*.spec.js",
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    outputDir: "storage/app/phase7-checks/browser-results",
    reporter: [
        ["list"],
        [
            "json",
            { outputFile: "storage/app/phase7-checks/browser-results.json" },
        ],
    ],
    use: {
        ...devices["Desktop Chrome"],
        browserName: browser,
        baseURL: "http://127.0.0.1:8017",
        launchOptions: {
            executablePath:
                process.env.E2E_BROWSER_EXECUTABLE || installedWindowsChrome,
        },
        viewport: { width: 1280, height: 960 },
        reducedMotion: "reduce",
        trace: "off", // Authenticated snapshots and reset tokens must not enter uploaded traces.
        screenshot: "only-on-failure",
    },
});
