# Dashboard performance optimization

Local implementation and verification complete, 2026-10-30. This addresses the measured application bottleneck in QA-07; target-host capacity and release acceptance remain open. No commit, push, PR, merge or deployment was performed.

## Scope and method

Work started on synchronized `dev` at `680e3208cad6f1a8aa8c77d65a247c5f9dfa01a8`; remote `dev` and `main` matched. The pre-existing 22-line change to `ACCOUNT_DELETION_REPORT.md` is preserved. Requirements were reviewed against AGENTS, the design guardrails, scoring contract, architecture, QA audit and remediation plan before implementation. The plan targeted only historical analytics reads and redundant year-button calculation, with focused regressions and sequential verification.

The existing guarded benchmark resets only `habit_log_test`, uses synthetic records, and freezes time at 2026-10-05 12:00 UTC. Both runs use PHP 8.3.6, MySQL 8.4.11, the same fixture, query logging and Eloquent retrieval counters. Each operation has one first sample and ten warm samples, run sequentially in the same order. Tables report warm median / nearest-rank p95 (the maximum of ten samples); these are local in-process Laravel/Livewire request timings, not network, browser-paint or concurrent-production measurements. “Cold” in the raw files means the first operation sample, not an emptied OS/database cache.

The small fixture has 10 habits, one year, 3,650 entries and 1,460 checkpoint rows. The large fixture has 100 habits, five years, 94,350 entries, 37,740 checkpoint rows, 200 tracking versions and 1,001 relationships. Half its habits have sparse 30-day records. All five types, private/follower/public visibility, a trashed habit, an archived habit and suspension periods are included. The owner report reads 99 nontrashed habits and 94,289 entries. This fixture does not establish capacity for 100 densely recorded habits with 20 checkpoints each.

## Comparable results

| Scenario / operation | Before median / p95, ms | After median / p95, ms | Queries before → after |
| --- | ---: | ---: | ---: |
| 10 habits — Owner analytics only | 159.20 / 162.98 | 32.73 / 34.71 | 19 → 9 |
| 10 habits — Shared analytics only | 96.49 / 123.90 | 20.99 / 21.38 | 17 → 11 |
| 10 habits — Dashboard HTTP | 184.55 / 212.18 | 50.32 / 54.48 | 30 → 20 |
| 10 habits — Select date details | 170.22 / 174.47 | 41.85 / 43.70 | 22 → 12 |
| 10 habits — Submit year input | 172.39 / 181.76 | 40.64 / 44.61 | 23 → 13 |
| 10 habits — Current-year button | 345.10 / 371.75 | 42.65 / 44.60 | 42 → 13 |
| 100 habits — Owner analytics only | 4,505.90 / 4,735.05 | 1,511.58 / 1,537.18 | 387 → 87 |
| 100 habits — Shared analytics only | 3,024.64 / 3,073.96 | 1,027.13 / 1,043.47 | 263 → 65 |
| 100 habits — Dashboard HTTP | 4,523.11 / 4,627.48 | 1,561.89 / 1,581.89 | 398 → 98 |
| 100 habits — Select date details | 4,528.03 / 4,627.09 | 1,545.22 / 1,568.72 | 390 → 90 |
| 100 habits — Submit year input | 4,675.69 / 4,881.29 | 1,549.83 / 1,594.44 | 391 → 91 |
| 100 habits — Current-year button | 9,165.62 / 9,266.45 | 1,540.62 / 1,563.22 | 778 → 91 |

The reproduced 4.52-second large dashboard is comparable to the QA audit’s 4.45 seconds. Dashboard median falls **65.5%**, queries **75.4%**. The benchmarked current-year button falls **83.2%**; the same action implements previous/next buttons, which also receive regression/browser coverage. Shared-report numbers measure the analytics service, not the entire shared-profile HTTP response.

| Large scenario measurement | Before | After |
| --- | ---: | ---: |
| Dashboard median summed SQL time | 505.12 ms | 228.06 ms |
| Owner-report median summed SQL time | 508.12 ms | 203.59 ms |
| Owner-report history-entry queries | 189 | 48 |
| Owner-report checkpoint-entry queries | 189 | 30 |
| Owner-report hydrated entry / completion models | 94,289 / 37,740 | 0 / 0 |
| Dashboard warm peak PHP allocation | 75 MiB | 75 MiB |
| Owner-report warm peak PHP allocation | 71 MiB | 71 MiB |
| Shared-report warm peak PHP allocation | 69 MiB | 71 MiB |
| Dashboard response size, median | 562,473 bytes | 562,471 bytes |

Small dashboard warm peak allocation remains 67 MiB. Allocation uses `memory_get_peak_usage(true)` after resetting the peak for each sample; it includes the test framework and allocator pages retained from earlier operations. Warm additional allocation is usually zero because pages are reused. These observations do **not** demonstrate a per-request memory reduction. The slightly different HTTP byte counts include generated fixture/response content; analytics JSON lengths are unchanged, and complete reports passed the separate exact comparison.

## Findings and changes

The main cost was lifetime report processing. Before optimization, the owner service took 4,505.90 ms versus 4,523.11 ms for the whole dashboard. About 508 ms was measured SQL time; the remainder includes hydration, casts, date conversion, scoring/aggregation and framework/driver work. This is coarse profiling, not a function-level CPU trace. Query groups show bounded eager loading rather than a per-entry N+1: 189 entry batches each caused a checkpoint query, including batches without checkpoint habits. Constructing 132,029 entry/completion models per report added avoidable PHP work.

`ActivityAnalytics` now projects the six required entry fields through the query builder in 2,000-row batches, after the existing 50-habit authorization/visibility scope. Completion rows are fetched only for entries whose historical version is a checkpoint type. Exact checkpoint identity membership and cardinality remain required. Explicit scalar conversion preserves numeric values, while the enum still supplies visible assessment labels. Date conversions are reused within a batch. Only batch-local rows, lifetime event boundaries and selected-year details are retained; no persistent cache or precomputed aggregate is introduced.

The original 50-habit batch, fresh owner/viewer checks, policy-filtered shared query and single nonlocking repeatable-read transaction remain. Raw history is read, never modified. Notes, N/A reasons, journals and mood are not selected. `HabitScore`, streak calculation, interval aggregation, floating-point ordering, public report shape, routes, Blade, JavaScript and Terminal Green styling are unchanged. No migration, index, dependency or authorization change is needed.

`ActivityHeatmap::changeYear` previously evaluated its computed report to obtain today, invalidated it, then rendered another report. It now validates the existing year and obtains fresh authorized local time directly; rendering calculates the report once. Authentication, viewer pinning, shared visibility, timezone changes and normal selection resets remain enforced. Other interactions retain request-local computed reuse.

After optimization, owner-report processing still takes 1,511.58 ms, including 203.59 ms of SQL. The remaining approximately 1.31 seconds is PHP/driver/framework processing, including the unchanged lifetime aggregation; this difference is not a pure CPU measurement. The full dashboard adds roughly 50 ms in these separate median measurements. SQL and lifetime processing, rather than the unchanged visual calendar markup, remain the main scaling costs.

## Changed files

| Files | Purpose |
| --- | --- |
| `app/Services/Analytics/ActivityAnalytics.php` | Bounded projected history reads and selective checkpoint loading. |
| `app/Livewire/ActivityHeatmap.php` | One report per year-button interaction. |
| `tests/Feature/Analytics/LargeHistoryTest.php` | Large-history, query-budget, corruption, timezone and authorization regressions. |
| `tests/Performance/PerformanceTest.php` | Comparable latency/SQL/model/allocation measurement and dashboard scope. |
| `tests/Performance/ReportSnapshotTest.php` | Explicit baseline capture and exact report comparison. |
| `docs/DASHBOARD_PERFORMANCE_REPORT.md`, `docs/DEPLOYMENT_REMEDIATION_PLAN.md`, `docs/ARCHITECTURE.md` | Evidence, remaining QA-07 acceptance and actual read strategy. |

No migrations or dependencies changed. The pre-existing account-deletion documentation edit is outside this task.

## Regression evidence and reproducibility

Before editing application code, `ReportSnapshotTest` captured owner and authorized shared reports for 2024, 2025 and 2026 plus six individual-habit reports. After the edit, all 12 reports matched **byte for byte**, preserving floating-point values, every date/counter/state, score/band/symbol, label, link, consistency and streak. The ignored reference is not replaced by the optimized run. Its SHA-256 is `e120334296ab5c44087784309ea08cdaff3f29a7b2821b0245adbb8259bc3d69`.

`LargeHistoryTest` adds deterministic checks instead of timing thresholds: multi-batch numeric history with exact 75% leap-year/recent consistency, no entry-model hydration or completion queries, a bounded query budget, unchanged raw row count, malformed checkpoint membership and foreign versions around the batch boundary, retained longest streaks, one report per year action, fresh timezone rollover and pinned authentication. Existing scoring, historical type/goal changes, privacy, calendar extremes, DST, pending/N/A and two-connection snapshot regressions remain applicable.

Repeatable measurement sequence (before/after implementations must use the same instrumented harness):

```sh
HABIT_LOG_PERFORMANCE_SCOPE=dashboard HABIT_LOG_PERFORMANCE_LABEL=before php artisan test tests/Performance/PerformanceTest.php --compact
HABIT_LOG_CAPTURE_REFERENCE=1 php artisan test tests/Performance/ReportSnapshotTest.php --compact
# Apply the optimization before the next command; do not reset fixtures between capture and comparison.
php artisan test tests/Performance/ReportSnapshotTest.php --compact
HABIT_LOG_PERFORMANCE_SCOPE=dashboard HABIT_LOG_PERFORMANCE_LABEL=after php artisan test tests/Performance/PerformanceTest.php --compact
```

The capture refuses to overwrite an existing reference. The performance directory is outside the default PHP suite. It requires the guarded test environment and exclusive test lock; never point it at development or production data. Raw samples, SQL timing groups and hydration counts are in ignored `storage/app/dashboard-performance/{before,after}.json`; the reference and logs are ignored too. The existing QA benchmark remains historical evidence, not rewritten results.

The first focused run passed 59 cases and found a new test-counter error: it counted the onboarding `EXISTS` subquery as another lifetime-entry read after local New Year. The counter was narrowed to the primary queried table; all three new cases then passed (1,125 assertions). No application fix was needed for that test failure. Reference capture passed in 22.52 seconds; optimized comparison passed in 7.46 seconds. Before/after benchmark runs passed 92 assertions each in 377.04 / 120.01 seconds.

Sequential verification (logs ignored under `storage/app/dashboard-performance-*.log`):

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Analytics tests/Feature/Social/AnalyticsTest.php tests/Feature/Journals/AnalyticsRegressionTest.php tests/Unit/Analytics --compact` | 59 passed; the new query-counter assertion failed as described above, then was corrected. |
| `php artisan test tests/Feature/Analytics/LargeHistoryTest.php --compact` | 3 passed / 1,125 assertions, 3.21 seconds. |
| `php artisan test --compact` | **546 passed / 8,716 assertions**, 209.58 seconds; full suite including concurrency, privacy, scoring, raw-history, account/recovery and the three new cases. |
| `vendor/bin/pint --test` | Passed. |
| `npm run build` | Passed; production assets generated. |
| `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e -- tests/Browser/analytics.spec.js tests/Browser/heatmap-refinement.spec.js tests/Browser/dashboard-design.spec.js tests/Browser/dashboard-cleanup.spec.js tests/Browser/shared-profiles.spec.js tests/Browser/social.spec.js tests/Browser/habit-details-design.spec.js` | **23 passed**, 2.6 minutes, plus the runner’s cache-state checks. Chromium against isolated synthetic fixtures and production assets. |
| `composer audit --locked` | No security vulnerability advisories. |
| `npm audit` | 0 vulnerabilities. |
| `docker build --target production -t habit-log:dashboard-performance-check .` | Passed; local image manifest list `sha256:e329a01156e8583a2a880ffe9895306ce93ccaa6fb76464abef3126b8ca5a3b1`. |
| `sh tests/Containers/smoke.sh habit-log:dashboard-performance-check` | Passed; disposable non-root runtime, health/assets, cached configuration, CSRF, host/mail/log protections, restricted recovery-storage probe and recovery quarantine. No live deployment or real database was used. |
| `git diff --check` | Passed. |

Browser coverage includes owner/shared historical performance, revocation after an open view, date/year selection, leap dates, keyboard focus/return, touch scrolling, responsive geometry, status explanations and private-text exclusion. Existing axe assertions passed in the selected scenarios. Desktop (1440px) and mobile (390px) populated heatmap captures were visually reviewed; approved Terminal Green styling, controls and internal calendar scrolling remain intact. These are affected browser regressions, not a claim that all 88 repository browser scenarios were rerun.

Final review confirms eight task files changed, plus the preserved pre-existing account-deletion documentation edit. No generated samples, logs, fixture data or screenshots are added to Git. The branch remains `dev` at the starting revision.

## Limits and design review

Lifetime reads remain O(recorded history); event-boundary storage grows with distinct dates. Larger entry batches trade fewer round trips for a larger bounded working set: up to 2,000 parent rows plus their checkpoint rows (up to 40,000 completions for valid 20-item setups). No cache invalidation or schema risk is introduced. The approximately 562 KB uncompressed dashboard response is unchanged; transfer, compression, client rendering and concurrent load were not benchmarked here.

Target hosting is undecided. Supported history sizes, latency/concurrency limits, actual host/database memory and backup recovery objectives still require operator agreement and measurements. Passing local regressions does not close QA-07’s production-capacity acceptance or the other deployment gates. Other browser engines, physical devices and manual assistive-technology acceptance are outside these automated checks.

Design verification: higher score remains better; private defaults, policy enforcement and private-content exclusion are preserved; no write path, relationship rule or raw history changes; no migration or destructive cleanup; accessibility/date navigation and the approved heatmap passed the affected browser checks. Architecture documentation records the new read strategy; no new architectural boundary or scoring decision requires an ADR.

Suggested commit message, for the user to apply manually: `perf: reduce dashboard history processing and redundant year reports`.
