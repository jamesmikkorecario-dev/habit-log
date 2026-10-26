# PR #8 browser CI fix

## Evidence and cause

GitHub Actions run [40259065205](https://github.com/jamesmikkorecario-dev/habit-log/actions/runs/40259065205) at `9acf1e460dc8f10bcb0c8ee0a5ddf90d2297ce83` finished with 60 browser scenarios passing and one failing. The populated People scenario's screenshot shows **429 Too Many Requests**. Its stack identifies `people-design.spec.js:105`, the first `register(page, 'peopledesign')` call; the second user's registration had not started.

`public-auth` intentionally admits 20 registration POSTs per minute per route/IP, including validation failures. All browser scenarios use the same loopback IP and retain one limiter cache across the entire suite. Independent scenarios therefore consumed one another's registration budget. Suite timing can determine which scenario crosses the limit. `register()` previously ignored the POST response and waited for `/dashboard` or `/people`, disguising HTTP rejection as a 60-second navigation timeout.

A separate local runner problem surfaced during controlled reproduction: PHP configured with `variables_order=GPCS` exposes the requested `LARAVEL_STORAGE_PATH` through `getenv()` but neither `$_ENV` nor `$_SERVER` in web requests. Laravel's `storagePath()` reads the latter two, so the local web server used regular storage despite the runner creating a temporary directory. A read-only HTTP probe confirmed that `EGPCS` resolves the intended temporary cache. This local configuration issue also allowed limiter state to survive separate runner invocations; it is not assumed to be the hosted runner's PHP configuration.

Hosted logs and screenshots and local reproduction logs are retained only under ignored `storage/app/pr8-ci-fix/`. No credentials, response bodies, cookies, reset links, or generated fixture data belong in this report.

## Change and boundaries

The browser runner starts PHP's built-in server with Laravel's existing router and explicit `variables_order=EGPCS`, then supplies its disposable file-cache path to a common automatic Playwright fixture. Optional Windows Node execution forwards the opt-in and already-converted cache path through `WSLENV`. Before each scenario, the fixture clears only that cache after checking explicit opt-in, an absolute canonical path, and the expected isolated runtime directory. All contexts and requests within a scenario retain the real shared rate-limit state. The single worker and existing process lock remain required. Sessions and database records are not cleared by this fixture. The HTTP regression also verifies that the server actually writes limiter files into the disposable cache.

All main browser specs use the common fixture. Registration observes the actual POST before clicking, checks HTTP status and redirect destination, and reports only safe response metadata when it fails. There are no retries, arbitrary sleeps, longer timeouts, new application endpoints, or production middleware exceptions.

Application code, auth/CSRF/session protections, configured rate limits, schema, and password-change regression behavior remain unchanged. No migration is needed. This is test isolation and diagnostics work, consistent with DG-021 and DG-025; no product, privacy, analytics, lifecycle, or roadmap behavior changes.

## Verification

Local browser runs use installed Windows Chrome through WSL with `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe'` before each `npm run test:e2e` command below. Database resets target guarded `habit_log_test`, run serially under the existing lock. No browser libraries were installed.

| Check | Command | Result |
| --- | --- | --- |
| Original failing scenario, before changes | `npm run test:e2e -- people-design.spec.js --grep 'presents populated'` | 1 passed (10.7s); not reproducible alone with a fresh budget. |
| Original full suite, before changes | `npm run test:e2e` | 60 passed, 1 failed (6.7m). The preceding People onboarding scenario received HTTP 429; the populated scenario passed after the timeout. This reproduced the cause, with the affected scenario depending on timing. |
| Controlled regression before per-test fixture | `npm run test:e2e -- registration-isolation.spec.js` | After correcting runner-level storage isolation and adding HTTP diagnostics: 1 passed, 1 failed (3.3s). The first scenario exhausted the real limit across contexts; the next failed immediately with HTTP 429 and Retry-After 59. |
| Focused fixed coverage | `npm run test:e2e -- registration-isolation.spec.js people-design.spec.js password-change.spec.js auth-security.spec.js` | 10 passed (47.7s), including all three password-change scenarios and both People scenarios. |
| Filesystem guard regression | `node --test tests/Browser/cache-state.test.js` | Passed; opt-in/path/symlink rejection, cache clearing, repeated boundaries, and session-file preservation. Also runs explicitly with the final `test:e2e` command. |
| Complete fixed browser suite | `npm run test:e2e` | Filesystem guard passed, then 63 browser scenarios passed (6.1m), including all original 61 scenarios. No retries or skips. |
| Full PHP suite and Pint | `composer ci:check` | Pint passed; 471 tests passed with 5,420 assertions (120.34s), including registration limits and password-change security/concurrency coverage. |
| Frontend production build | `npm run build` | Passed. |
| Diff whitespace check | `git diff --check` | Passed. |

These results record verification before publication. Local acceptance used installed Chrome; it does not establish a new hosted Chromium result. The authorized closeout will push this fix to `origin/dev` and report the resulting PR #8 CI run separately. Dependency versions and lockfiles, application code, and container definitions are unchanged, so dependency audits and container smoke checks were not repeated locally for this test-only change.

## Changed files and review

- `tests/Browser/run.mjs`: reliable runtime storage and platform-correct cache-path forwarding.
- `tests/Browser/cache-state.js`, `fixtures.js`, and `cache-state.test.js`: guarded automatic isolation and filesystem checks.
- `tests/Browser/support.js`: immediate registration HTTP/redirect diagnostics.
- `tests/Browser/registration-isolation.spec.js`: real limiter, cross-context, runtime-path, and next-scenario regressions.
- The 28 existing top-level browser specs: only their `test`/`expect` import changes; their scenario bodies and security assertions are preserved.
- `package.json`: explicitly run the filesystem regression before the existing locked browser runner. Npm lifecycle hooks remain disabled by repository policy.
- `docs/TESTING_STRATEGY.md` and this report: fixture contract, reproduction, verification, and limitations.

Review against `DESIGN_VERIFICATION.md`: no application authorization, privacy, schema, raw records, transaction, scoring, or UI changes; focused security and isolation regressions retain normal middleware. Generated fixtures, screenshots, and logs remain ignored. No new dependency or migration.

Approved commit message: `fix(test): isolate browser rate limits and improve registration diagnostics`.

Closeout review confirmed all 37 changed files are test source, the npm test command, or documentation; the 28 existing browser scenario bodies are unchanged. Saved verification is reused because closeout changes only this report. Generated fixture data, screenshots, logs, and built assets remain excluded.

The user authorized committing this reviewed fix and pushing to `origin/dev` to update PR #8. Merge and deployment remain outside the authorized scope.
