import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import net from 'node:net';

// Deliberately separate from the application QA runner: no setup, accounts or DB.
await new Promise((done, fail) => {
    const probe = net.createServer();
    probe.once('error', fail);
    probe.listen(8029, '127.0.0.1', () => probe.close(done));
});
const runtime = mkdtempSync(resolve(tmpdir(), 'habit-log-headers-'));
mkdirSync(resolve(runtime, 'framework/views'), { recursive: true });
const server = spawn('php', ['-S', '127.0.0.1:8029', '-t', 'public', 'tests/Fixtures/terminal-headers/router.php'], {
    env: { ...process.env, HEADER_TEST_RUNTIME: runtime },
    stdio: ['ignore', 'ignore', 'pipe'],
});
let errors = '';
server.stderr.on('data', (chunk) => { errors = (errors + chunk).slice(-12000); });
let runner;
const stop = () => { runner?.kill(); server.kill(); };
process.once('SIGINT', stop);
process.once('SIGTERM', stop);
try {
    let ready = false;
    for (let attempt = 0; attempt < 60; attempt++) {
        if (server.exitCode !== null) throw new Error('Header fixture server stopped.');
        try {
            const response = await fetch('http://127.0.0.1:8029/__headers/dashboard');
            if (response.ok) { ready = true; break; }
        } catch {}
        await new Promise((done) => setTimeout(done, 100));
    }
    if (!ready) throw new Error('Header fixture server did not become ready.');
    const executable = process.env.E2E_NODE || process.execPath;
    const runnerPath = (file) => executable.endsWith('.exe')
        ? spawnSync('wslpath', ['-w', resolve(file)], { encoding: 'utf8' }).stdout.trim()
        : resolve(file);
    runner = spawn(executable, [runnerPath('node_modules/@playwright/test/cli.js'), 'test', '--config',
        runnerPath('tests/Browser/TerminalHeaders/playwright.config.js'), ...process.argv.slice(2)], { stdio: 'inherit' });
    process.exitCode = await new Promise((done, fail) => {
        runner.once('error', fail);
        runner.once('exit', (code) => done(code ?? 1));
    });
} catch (error) {
    console.error(errors);
    throw error;
} finally {
    const closed = server.exitCode !== null ? Promise.resolve() : new Promise((done) => server.once('exit', done));
    stop();
    await closed;
    rmSync(runtime, { recursive: true, force: true });
}
