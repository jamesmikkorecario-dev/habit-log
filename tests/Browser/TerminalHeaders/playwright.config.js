import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    testMatch: '*.test.js',
    workers: 1,
    retries: 0,
    timeout: 30_000,
    outputDir: '../../../storage/app/header-checks/results',
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8029',
        viewport: { width: 1280, height: 900 },
        reducedMotion: 'no-preference',
        launchOptions: {
            executablePath: process.env.E2E_BROWSER_EXECUTABLE || (process.platform === 'win32'
                ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : undefined),
        },
        trace: 'off',
    },
});
