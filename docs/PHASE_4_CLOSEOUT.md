# Phase 4 closeout — 2026-10-01

Phase 4 implements owner-only heatmaps and analytics from the accepted documentation and user decisions. Final verification results are recorded below. The user subsequently authorized committing Phase 4 after fresh verification; no push was requested. The earlier user-requested plan replacement is preserved.

## Delivered behavior

- Individual yearly habit heatmaps on habit details and a normalized overall profile heatmap on the owner dashboard, with year navigation, date details, existing recording links, and a paginated textual day list.
- Lifetime current/longest strict streaks and trailing 30-day/selected-calendar-year consistency. Partial progress contributes to consistency; only full success extends streaks. N/A and inactive periods preserve but do not extend runs; past eligible unlogged dates score zero, while today remains pending until recorded.
- Historical goals/type/checkpoint snapshots and persisted lifecycle intervals determine each date's performance. Archived/ended habits retain eligible history; current trash excludes the habit from reports until restored. Timezone changes alter local today without moving saved dates; future-local retained entries stay neutral.
- Avoidance scores 1 at/below its acceptable limit, otherwise 0; zero maximum means abstinence. Goal-free numeric habits use assessment scores 1/0.5/0. Goal-based performance remains separate from the recorded personal assessment. Profile cells average contributing habits equally; profile consistency averages daily profile scores equally by date.
- Neutral, pending, N/A, unlogged, zero, and unavailable states remain distinguishable. Reports disclose contributing/pending/unavailable counts and denominator windows. Today is provisional while habits remain pending; an overall successful day cannot extend a streak before pending habits resolve.
- All reads/actions authenticate and enforce ownership for every visibility preference. Private notes and N/A reasons are excluded from analytics queries, results, markup, and Livewire state. Existing session/CSRF behavior is retained.

## Implementation and database impact

`app/Services/Analytics/HabitScore.php` calculates normalized scores and exact success flags. `ActivityAnalytics.php` loads historical projections and computes profile aggregation and consistency. `StreakCalculator.php` scans date segments for runs. The shared `app/Livewire/ActivityHeatmap.php` validates/authorizes interface state; the Livewire view and reusable calendar component render results, with Alpine limited to interaction/focus.

The dashboard and habit detail embed the shared component. CSS supplies five monotonic green performance bands and distinct neutral styling. No routes, privacy defaults, raw-record write actions, dependencies, seeders, or schemas change.

**No migrations or analytics cache were added.** Reports use a nonlocking MySQL repeatable-read transaction so owner timezone, entry parents, historical versions, and checkpoint children belong to one consistent snapshot. Lifetime processing uses 50-habit and 500-entry batches and date-boundary events. Long unlogged/inactive spans are processed as intervals rather than expanded day by day. Only the selected year's 365/366 cells and its entry details are materialized for display. All history is still considered for lifetime streaks; there is no silent recent-year cutoff.

## Verification evidence

- `composer ci:check`: **243 tests / 1,798 assertions passed**, including Pint, after the final UI fixes and edge-case regressions. This includes the 200-test Phase 3 baseline and 43 additional analytics cases. The final suite completed in 43.34 seconds in the final pre-commit verification.
- `npm run build`: production assets built successfully; the empty application JavaScript chunk is expected because Livewire/Flux supply runtime scripts.
- New unit tests cover all scoring branches, goal-free/zero/invalid cases, exact success versus rounded display values, monotonicity, and streaks across large neutral intervals.
- Integration tests cover historical configuration/checkpoints, pending/cancelled setup, aggregation/denominators, N/A/unlogged/pending, leap dates, timezone travel/DST, years 1000/9999, missing baselines, inclusive ranges, overlapping/empty lifecycle intervals, trash restoration, ancient histories, more-than-one-page profiles, privacy, read-only behavior, invalid/forged interface state, ownership, locked identities, and a real post-logout Livewire request.
- A guarded two-connection test commits a checkpoint/entry edit between the report's parent and child reads. The first report consistently sees the old state, and the next report sees the committed new state. The independent writer completes without waiting for an analytics row lock.
- Installed-browser acceptance checks actual rendered scores for all five types and the profile mean, details, keyboard movement/focus/Escape, year/leap-day/list navigation, mobile layout, private-field exclusion, cross-owner denial, and CSRF rejection. Installed Chrome 154.0.8037.58 passed all **41 checks**, including touch emulation and calendar/list focus restoration, with no JavaScript errors. Desktop 1280×1000 and mobile/narrow widths 375/320/640 were exercised; desktop and mobile screenshots were inspected. Weekday labels remain visible when the grid scrolls. The full-page touch screenshot captures a sticky header at the scrolled viewport position; layout-width and interaction checks are separate evidence.
- `git diff --check` and local documentation link checks passed; the retired Phase 3 plan remains absent and unreferenced. Historical Phase 3 evidence is retained and is not substituted for Phase 4 results.

MySQL was initially stopped; starting the existing Compose service restored integration-test access. Restricted execution also required the existing approved access to local MySQL/formatter sockets. No library or browser installation was needed. Test fixtures and destructive test resets use only `habit_log_test`; development data was not reset or migrated.

## Scoped performance findings

A test-only fixture with **55 habits and 1,650 entries**, configured from 2020 through the current date, generated a yearly profile plus lifetime metrics in **248.96 ms**, using **18 SELECT queries** and approximately **2 MiB additional allocated memory** in the measured process. It produced 365 cells and the expected longest run of six successful days. A separate automated regression covers 22 habits starting in year 1000 without expanding centuries of missing dates.

EXPLAIN used the existing owner/lifecycle index for habits and primary-key index/range scans for ID-batched entries. These observations do not justify a new index or cache in this phase. This is a local scoped measurement, not a production latency or load guarantee; global multi-user volume and more complex version histories can change the query plans and cost.

## Review findings fixed

1. Invalid year input raised validation during rendering and prevented a usable error response. A separate year input and explicit Go action now keep the displayed year valid while showing form errors; forged query/component state is rejected server-side.
2. Restoring focus through a saved DOM element reference failed after Livewire updated the date controls. Focus restoration now resolves a stable date/list key in the current DOM; actual browser checks cover Escape and Close.
3. Native day-list disclosure state was lost during pagination. Preserving the disclosure's own attributes while updating its children keeps the list open.
4. Screenshot review showed weekday labels scrolling away on a narrow calendar. They now remain visible in a sticky column while the calendar region scrolls.
5. Pre-commit review reproduced a tiny-positive-score color bug: a positive profile score below `1e-10` received the zero band. The fix uses exact positive/full-success counts to normalize true boundary values and removes the arbitrary zero cutoff. A failing-then-passing integration regression covers both the tiny positive score and the following true-zero date. Scoring formulas and raw data are unchanged.

No unresolved Phase 4 requirement conflict was found. ADR-0005 records the four user-selected scoring/consistency decisions. The old avoidance formula was replaced explicitly rather than inferred from Phase 3 assessments.

## Documentation and design verification

The user-requested replacement of the Phase 3 plan with the Phase 4 plan remains intact. README, roadmap, scoring/product requirements, architecture, database design, UI specification, security/privacy, testing, commands, structure, and ADR links reflect this phase. Existing closeouts remain historical.

Against `DESIGN_VERIFICATION.md`: roadmap outputs are present; server-side privacy/defaults are preserved; reports derive from raw records without writes; existing FK/index and transactional recording behavior remains intact; the new consistent-read transaction has a concurrent-edit regression; unit/integration/full-suite and browser evidence cover the included behavior. The UI uses ordinary controls with non-color cues, keyboard focus, touch details, and a textual alternative. Broader audits remain outside this phase.

## Limits and deferred work

There is no public/follower access, journal/mood, export, permanent deletion, automatic expiry, or persisted analytics cache. Phase 5 is not implemented. Pre-Phase-3 configuration changes and completed inactive intervals cannot be reconstructed. Current name/category metadata is not historical. Today updates on navigation/refresh rather than a live midnight timer.

Browser checks use installed Chrome; they do not establish exhaustive screen-reader/cross-browser support. Broader accessibility/security/performance/load/deployment hardening remains Phase 7. Hosted CI was not run because publication was not requested. Rolling back the analytics interface/services leaves raw activity intact; future formula changes require an ADR and regression tests.

The user-requested pre-commit rerun is recorded in `commit-verification.log`; it includes the precision regression and full suite/Pint. The production build also passed again.

Ignored local evidence, browser harnesses/screenshots, and benchmark output reside in `storage/app/phase4-checks/`. Temporary verification processes are stopped after acceptance. No artifact there belongs in a commit.

Suggested commit message, only if requested: `feat: implement Phase 4 heatmaps and analytics`.
