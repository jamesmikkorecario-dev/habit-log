# Phase 7 hardening implementation and verification

Verification date: 2026-10-05. Local implementation and automated verification are complete; external release gates below remain open. This is not a production-readiness or accessibility certification. No push, live deployment, domain migration or data backfill was performed. The Phase 7 plan and findings ledger were retired at the user's request during commit preparation on 2026-10-09; this closeout retains delivered behavior, verified findings and release limits. [Deployment and recovery](DEPLOYMENT.md) describes operator procedures.

## Delivered behavior

- Old category, habit and tracking Livewire snapshots now reject logout/account changes before rendering or applying a draft. A locked identity concern complements existing record policies; no new role system or sharing permission was introduced.
- Public registration/reset POSTs have separate 20/minute route/IP limits. Existing login and reset-broker limits remain. Web responses carry private/no-store, no-referrer, nosniff and SAMEORIGIN controls. Unhandled database errors retain ERROR severity/class/SQLSTATE/operation while excluding SQL, private bindings, driver messages and argument-bearing traces.
- CommonMark 2.10.1 was patched to 2.10.3, with its required polyfill update. Locked Composer and npm audits report no advisories. Playwright and axe are development test dependencies only.
- Heatmap borders now meet the measured 3:1 control distinction target. Navigation wraps at enlarged text sizes. Cancelling Back in the activity editor restores both its URL and unsaved draft, matching the journal guard.
- Analytics prepares immutable date/setup fields once per batch. It preserves scoring, all historical input, viewer filtering, chunking and consistent reads; there is no persisted cache or formula change.
- Repository browser tests and CI replace reliance on ignored scripts/another checkout. PHPUnit, browser resets and the restore drill share an exclusive process lock. The base test class checks configured and actual database identity before RefreshDatabase; browser setup requires explicit opt-in and uncached local test configuration. Browser storage/cache is isolated per run.

Principal files: `app/Livewire/Concerns/RequiresSameUser.php`, seven category/habit/tracking pages, `app/Http/Middleware/WebSecurity.php`, `bootstrap/app.php`, `app/Providers/FortifyServiceProvider.php`, `app/Services/Analytics/ActivityAnalytics.php`, `resources/js/app.js`, the application layout/CSS, two Auth regression classes, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Browser`, `tests/Performance`, guarded fixture helpers, Playwright configuration, npm/Composer locks and CI workflow. **No migrations are added.** Phase 6 plan retirement preserves its access matrix and transition rules in [ADR-0006](adr/ADR-0006-SHARED-PROFILE-ACCESS.md).

## Verification

| Command / exercise | Observed result |
| --- | --- |
| `composer ci:check` | Pint and **336 tests / 3,737 assertions**, 111.95 seconds; includes privacy, ownership, forged state, historical scoring, concurrent reads/writes and populated migration recovery. |
| `npm run build` | Production asset build passed. |
| `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e` | **14 scenarios passed**, 1.3 minutes, installed Windows Chrome 154.0.8037.93 / Playwright 1.63.0. Normal middleware, one worker, no automatic retries. |
| `php artisan test tests/Performance/PerformanceTest.php --compact` | **1 test / 311 assertions**, 349.96 seconds after optimization. Separate from the default suite. |
| Old HEAD versus optimized analytics on the large fixture | Complete owner, shared and individual report arrays were strictly identical; hashes retained in ignored evidence. |
| `composer audit --locked --format=json`; `npm audit --json` | Zero advisories in both dependency graphs. |
| `composer check-platform-reqs --no-dev` | Passed on local PHP 8.3.6. |
| Fixture/lock rejection | Four unsafe browser configurations exit 1 before reset; a second PHP process refuses while the first holds the shared lock. |
| `HABIT_LOG_RESTORE_REHEARSAL=1 python3 tests/Fixtures/restore-rehearsal.py` | All 15 restored tables match source counts/checksums; analytics and journal owner/other policy results match. |
| `git diff --check` and documentation links | Passed; 85 local Markdown destinations/anchors checked. Targeted private-key/AWS/GitHub-token patterns found no matches across 264 project files (not a comprehensive secret audit). |

Initial PHP baseline failed because the existing MySQL service was stopped. Starting the existing Compose service resolved connectivity; that failure is not classified as an application regression. Focused failures were reproduced for account switching, private exception logging, missing limits/headers, enlarged navigation and activity Back cancellation before their fixes. Final evidence is stored locally in ignored `storage/app/phase7-checks`; authenticated mail/server logs and tokens are excluded from CI artifacts.

Browser coverage includes registration/login/logout/reset and CSRF rejection; all five recording types, numeric zero, N/A, historical goals and known 50% scores; stale activity/journal drafts; category and habit lifecycle recovery; confirmed journal removal and focus; both cancelled/accepted Back paths; private/public/follower state transitions and shared-field canaries; calendar year/date/list navigation; keyboard and emulated touch. The private-to-public transition retains pending requests until an explicit action.

Axe WCAG 2 A/AA and 2.1 AA scans cover login and ten authenticated surfaces, with no detected violations. Automated layouts cover 320/375/768/1024/1440px and 200% root text enlargement. A 375×812 touch-emulated screenshot was visually inspected: navigation and People controls fit without clipping. Border contrast changed from 2.53:1 / 2.29:1 to 4.18:1 / 3.78:1 against the two app surfaces. Automated scans and emulation do not replace assistive or physical-device testing.

## Representative performance

PHP 8.3.6 / MySQL 8.4.11, local synthetic data, one cold plus ten warm samples per case. P95 is the slowest of ten warm samples. Timing is server/test-process elapsed time, not network latency or a production SLO. Peak memory includes the PHP test process and reused allocator; it is not independent request RSS. The fixture includes mixed types/visibility, two configurations per habit, checkpoint children, suspension gaps, archive/trash, and (in the large case) 1,001 directed relationships. Small: 10 habits, one year, 3,650 entries; large: 100 habits, five years, 94,350 entries.

| Habits | Operation | Before median ms | After median ms | After p95 ms | Queries | Peak MiB | Response bytes |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | owner report | 595.79 | 176.98 | 185.10 | 19 | 58.5 | 87,068 |
| 10 | shared report | 323.48 | 101.36 | 109.36 | 17 | 58.5 | 71,854 |
| 10 | dashboard | 596.36 | 186.83 | 198.11 | 23 | 63 | 267,959 |
| 10 | habit | 90.45 | 49.75 | 52.81 | 25 | 65 | 269,109 |
| 10 | shared profile | 342.38 | 126.23 | 136.10 | 47 | 65 | 277,731 |
| 10 | shared habit | 103.35 | 63.76 | 68.24 | 61 | 65 | 264,593 |
| 10 | tracking | 9.32 | 11.10 | 14.42 | 7 | 65 | 55,432 |
| 10 | history | 7.59 | 8.41 | 10.13 | 4 | 65 | 67,781 |
| 10 | habits | 7.04 | 8.26 | 10.58 | 4 | 65 | 91,876 |
| 10 | categories | 3.45 | 4.38 | 5.80 | 1 | 65 | 55,271 |
| 10 | relationships following | — | 5.76 | 6.54 | 5 | 65 | 24,687 |
| 10 | relationships followers | — | 7.06 | 8.69 | 7 | 65 | 27,422 |
| 10 | relationships incoming | — | 5.80 | 6.36 | 5 | 65 | 24,686 |
| 10 | relationships sent | — | 6.05 | 6.65 | 5 | 65 | 24,682 |
| 10 | date details | — | 187.38 | 193.26 | 22 | 67 | 253,922 |
| 10 | year change | — | 190.46 | 211.34 | 23 | 67 | 247,895 |
| 100 | owner report | 16,877.42 | 5,000.03 | 5,071.79 | 387 | 69 | 88,206 |
| 100 | shared report | 11,222.18 | 3,353.78 | 3,449.81 | 263 | 69 | 76,206 |
| 100 | dashboard | 16,955.91 | 5,158.26 | 5,402.38 | 391 | 71 | 268,320 |
| 100 | habit | 337.85 | 120.72 | 147.43 | 31 | 71 | 269,100 |
| 100 | shared profile | 11,223.29 | 3,481.68 | 3,667.30 | 293 | 71 | 292,145 |
| 100 | shared habit | 353.37 | 136.83 | 178.37 | 67 | 71 | 264,604 |
| 100 | tracking | 12.85 | 14.07 | 15.86 | 7 | 71 | 90,830 |
| 100 | history | 7.88 | 9.33 | 10.10 | 4 | 71 | 67,774 |
| 100 | habits | 10.75 | 12.50 | 13.87 | 4 | 71 | 185,779 |
| 100 | categories | 5.95 | 6.67 | 7.13 | 1 | 71 | 276,744 |
| 100 | relationships following | — | 10.11 | 17.65 | 7 | 71 | 99,223 |
| 100 | relationships followers | — | 9.03 | 10.27 | 7 | 71 | 99,283 |
| 100 | relationships incoming | — | 9.43 | 12.00 | 7 | 71 | 140,075 |
| 100 | relationships sent | — | 9.00 | 10.02 | 7 | 71 | 98,109 |
| 100 | date details | — | 5,227.02 | 5,433.95 | 390 | 71 | 254,240 |
| 100 | year change | — | 5,011.05 | 5,086.73 | 391 | 71 | 254,434 |

The owner-report median fell about 70% in both fixtures (595.79→176.98 ms and 16,877.42→5,000.03 ms), with identical report contents. Query counts remain unchanged: growth is driven by 50-habit/500-entry batches, not per-cell relationship checks. Existing deterministic query/privacy tests passed. EXPLAIN uses the habit/date index for entry lookup; incoming/outgoing relationship pages use the directed state indexes in the 1,001-row fixture. The sampled single-habit ID-order query still filesorts within that habit; no index migration was justified by this CPU-dominated comparison.

Service rows report JSON bytes; page rows report HTML bytes. Relationship/date/year rows report actual Livewire JSON after the harness was corrected from HTML-only measurement. Their original bytes/timings are therefore not shown as direct comparisons; the original year case also included two requests, while the final measure times one action. Full cold/warm/query/memory/plan data remain in the local JSON evidence.

**Capacity limit:** a five-second local owner report and roughly 250 KB Livewire heatmap responses remain material at the larger history. This is not an acceptable-latency guarantee. Establish target-host traffic/history budgets and measure concurrency before launch; further optimization requires evidence and must preserve historical/private semantics. No speculative cache, truncation or product limit was added.

## Deployment and recovery rehearsal

The provider-neutral runbook covers locked production dependencies/build, preserved application key/storage, secure sessions, actual proxy/TLS/host verification, captured versus real mail, controlled migrations, category-only seeding, encrypted off-host backups, restore comparison and compatible code rollback. Development Compose/setup/reset commands are explicitly excluded from production.

The restore drill exported synthetic `habit_log_test` under the shared lock, encrypted with AES256 through GPG, decrypted into a newly created isolated database, compared all table counts/checksums and application reads, then revoked its temporary grant and dropped it. The fixture contained 11 habits, 6 entries, versions/checkpoints, 2 journals and 1 follow. The plaintext export was 38,215 bytes; encrypted output 7,816 bytes. Export/import took 0.22/0.66 seconds. Temporary plaintext/key material was removed; the remaining encrypted artifact is disposable evidence, not a usable backup. This small rehearsal does not establish production recovery time.

Two independent temporary release copies ran `composer install --no-dev --optimize-autoloader --no-interaction --no-scripts`, `php artisan package:discover`, `config:cache`, `route:cache` and `view:cache`. Their own production autoloaders and caches were used; the prior Phase 6 code retained the current compatible patched dependencies. Both copies shared one synthetic application key and the test database. No migration or schema rollback was performed.

The current copy registered a controlled ordinary account, created a private habit, recorded activity and saved a journal. The browser verified reload persistence, 100% analytics, built asset responses, health, Secure/HttpOnly/SameSite=Lax session cookies, CSRF rejection and a non-debug 404. After stopping that server, the prior code started on the same loopback port and logged into the same account; all those data/read/session checks passed again. Each server was stopped by its owning helper. The first helper run used an incorrect cookie-name suffix; correcting the assertion to the configured `-session` name resolved it without application changes.

This exercised compatible code replacement, not a production traffic cutover or reverse-proxy switch. The rehearsal uses loopback PHP serving and synthetic test data; no actual service is published. Host-specific commands and a real production web-server/proxy configuration must still be verified.

## Remaining release gates

- Manual screen-reader journeys and a real touch device/virtual keyboard were unavailable. Touch emulation, automated accessibility scans, keyboard tests and 200% text checks passed; native 400% browser zoom and Firefox/WebKit have not been exercised.
- Hosted CI has been configured but not observed; no push was authorized. CI explicitly installs Chromium/system dependencies in its isolated runner. No browser binaries or system libraries were installed locally.
- Target hosting/domain, real TLS/trusted proxy/unknown-host handling and production mail delivery remain unspecified and unverified. Loopback Secure cookies do not establish TLS correctness.
- Operators must choose backup destination, key custody, retention and recovery objectives, then rehearse at representative production size. Proposed runbook targets are planning defaults, not guarantees.
- Target-host performance/concurrency budgets remain open, particularly the measured large-history latency and heatmap payloads.

These gates remain incomplete; the roadmap must not imply an unrestricted production-ready release.

## Design verification mapping

Product/guardrails: existing private defaults, user-selected timezone/scoring, raw historical activity, pending follows, archive/trash recovery and deferred habit destruction remain intact. Authorization/privacy: Policies/Gates and direct request/hydration tests cover owner/shared boundaries; no journal/mood/note sharing is introduced. Integrity: no schema changes; existing FK, transactional, stale-write and migration tests pass. UI: reproduced navigation/contrast/history failures are fixed and browser regressions pass, with manual/platform gaps above. Documentation: security, architecture, UI, commands, testing, structure, deployment, findings, plan and roadmap describe the delivered boundaries and limits; no new architecture ADR is required.

Suggested commit message: `Harden session boundaries, analytics, browser coverage and recovery procedures`.
