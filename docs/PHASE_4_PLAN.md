# Phase 4 implementation plan — Heatmaps + Analytics

Status: implemented and verified for the scoped Phase 4 contract; see [the closeout report](PHASE_4_CLOSEOUT.md). Prepared 2026-10-01 against Phase 3 commit `fa1d07c` and an initially clean worktree. This document replaces the Phase 3 plan at the user's request; [the Phase 3 closeout](PHASE_3_CLOSEOUT.md), [database design](DATABASE_DESIGN.md), [UI specification](UI_UX_SPEC.md), and [ADR-0004](adr/ADR-0004-DAILY-TRACKING-HISTORY.md) retain its delivered contract. The user subsequently authorized Phase 4 implementation. The user subsequently requested system verification and a Phase 4 commit after it passes. No push is authorized.

## Relevant requirements and repository findings

Follow [AGENTS.md](../AGENTS.md), [design guardrails](DESIGN_GUARDRAILS.md), [product requirements](PRODUCT_REQUIREMENTS.md), [architecture](ARCHITECTURE.md), database design, UI specification, [roadmap](ROADMAP.md), and accepted ADRs in their documented order. Use [heatmap scoring](HEATMAP_SCORING.md) and [ADR-0005](adr/ADR-0005-ANALYTICS-SCORING.md) for normalized scores. Verify delivered work against [design verification](DESIGN_VERIFICATION.md).

Phase 4 delivers individual yearly habit heatmaps, an overall normalized profile heatmap, current/longest strict streaks, and 30-day/yearly consistency. Relevant guardrails are DG-001/002, DG-003/004, DG-010–013, DG-015–018, and DG-020–026. Higher performance must always produce stronger visual intensity; neutral states must remain distinct from a zero score. Raw records remain authoritative.

Repository findings at the planning baseline:

- Phase 3 supplies all five tracking types, owner-only routes/policies, UTC-default editable timezone, effective configuration versions, immutable checkpoint snapshots, daily entries, and archive/trash intervals. No analytics service, heatmap component, or score table exists.
- `TrackingDate` resolves date eligibility, versions, and suspension intervals. Its single-date database fallback must not be called once per cell; analytics needs preloaded, date-appropriate data while preserving these rules.
- Existing entries reference their historical version; missing entries need the version effective on that date. Current mutable habit goals/type/checkpoints are not historical analytics inputs.
- Numeric entry status is an independent personal assessment. Goal-based analytics may disagree with it; neither value nor assessment may be rewritten to make them agree.
- `HabitPolicy::view` and scoped habit queries deny foreign and trashed habits. Stored public/followers preferences currently grant no external access. The dashboard is an owner-only navigation page, suitable for the overall profile activity view.
- Phase 3's closeout records 200 tests / 1,100 assertions, Pint, build, migration/concurrency checks, and browser verification. Those are historical baseline results, not Phase 4 acceptance evidence.

## Confirmed decisions and planning assumptions

The user confirmed on 2026-10-01:

1. Avoidance goals are acceptable limits: score 1 at/below the maximum and 0 above it. A zero maximum means abstinence.
2. Numeric habits without goals use the personal assessment: completed = 1, partial = 0.5, missed = 0.
3. Past eligible unlogged dates score 0 and break strict streaks. Today remains pending until recorded or the local day ends.
4. Consistency is average normalized performance, giving partial progress proportional credit.

The first decision resolves the source conflict between the product's acceptable maximum and the former gradual avoidance formula. ADR-0005 records that change; the scoring document must match it before code is written. No formula is inferred from old entry statuses where a historical numeric goal exists.

Consistency is `100 × mean(eligible normalized scores)`. Strict streaks remain reserved for fully successful days.

The remaining concrete rules below are planning choices, not claims of pre-existing behavior: calendar-year views with Monday-first week columns; owner dashboard for overall profile activity; lifetime streaks independent of the selected year; neutral gaps preserve but do not extend streaks. These keep the scope within Phase 4 without exposing profiles early.

Included: scoring, historical eligibility, aggregation, streaks, consistency, N/A counts, accessible heatmaps and text details, year navigation, existing daily-recording links, focused/full verification, and documentation.

Excluded: followers/public profile routes, sharing, journal/mood, category analytics, exports, reminders, timers, score persistence, queues, chart packages, new themes, gamification, entry deletion, permanent habit deletion, and automatic trash expiry. Trash recovery continues beyond 30 days under the earlier user decision.

## Calendar, history, and eligibility

- Capture a fresh owner timezone and local today once per analytics request so one calculation cannot cross two different day boundaries. Recompute on navigation/refresh; show the timezone and as-of date. Saved calendar dates never move after timezone changes. Do not promise a live midnight timer.
- Validate year and selected date server-side on initial requests and every Livewire update. Support years 1000 through the current local year; reject malformed/out-of-range values and future-year requests. Only show 365/366 dates belonging to the selected year; padding outside the year is not activity. Prevent arithmetic underflow/overflow at supported date boundaries.
- Resolve date eligibility using historical inclusive start/end and stored inclusive-start/exclusive-end suspension intervals. Preserve same-day empty intervals and overlapping archive/trash periods. Current archived/ended habits contribute their eligible history; current archive flags must not remove their entire past.
- Exclude currently trashed habits from every analytics surface, including historical overall totals. Restore includes preserved eligible history again, while the recorded trash gap remains neutral. Explain that trash/restore can change the overall aggregate without deleting raw data.
- For a saved entry, use its referenced version and immutable checkpoint set. For an unlogged date, use the latest version effective on/before that date. Include the predecessor version before any fetched range, versions within it, and all explicitly referenced entry versions. Never resolve an entire year using only its last version.
- Dates before a habit's first effective version are ordinary inactive dates, not corrupt data. A missing version is an integrity error only when an entry references an unavailable version or the habit has no required baseline at all.
- Eligibility and N/A override retained measurements. Future dates are neutral, including entries that become future-local after travel. Out-of-range/suspended dates are neutral even if retained raw data exists. Explicit eligible N/A is neutral, excluded from denominators, counted separately, and retains no public reason text.
- Past eligible missing rows are derived **Not logged — score 0**, not inserted or relabeled as stored Missed entries. Missing today is **Pending**, excluded until logged or the next local date. Recorded today participates immediately, including a recorded failure.
- Handle impossible historical data defensively: a missing version/value, zero checkpoint denominator, invalid target, or mismatched relation is **Data unavailable**, never invented success or numeric zero. Exclude it from score denominators, display the affected count, and break the claimed strict run across that date. Tests must distinguish this integrity state from legitimate N/A; do not log private content or repair raw records during reads.

## Scoring and aggregation

Score only valid eligible non-N/A entries, except the derived zero for past unlogged dates:

| Historical type/setup | Normalized score | Full success for streaks |
| --- | --- | --- |
| Boolean | Completed 1; Missed 0 | Completed |
| Count/duration with positive goal | `min(actual / goal, 1)`; duration stays minutes | Actual at least goal |
| Checkpoint | Checked snapshot items / all snapshot items | All snapshot items checked, denominator > 0 |
| Avoidance with maximum, including zero | 1 if actual <= maximum, otherwise 0 | Actual <= maximum |
| Count/duration/avoidance without goal | Completed 1; Partial 0.5; Missed 0 | Completed assessment |

Use exact input comparisons for full success; display rounding must not turn 0.999 into a successful day. Cap over-target positive progress at 1. For goal-based rows, show normalized performance separately from the saved assessment, with a plain explanation when they differ. N/A overrides both. Never invent a default goal or divide by zero.

Fixture examples: boolean completed = 1; count 5/10 = 0.5 and 12/10 = 1; duration 15/30 minutes = 0.5; checkpoints 2/4 = 0.5; avoidance actual 2 with maximum 2 = 1, actual 3 = 0; maximum 0 with actual 0 = 1 and actual 1 = 0; goal-free Partial = 0.5. Consistency over scores 1, 0.5, and 0 is 50%; adding an N/A date leaves it 50% and increments the separate N/A count.

For each date, average the eligible normalized habit scores with equal weight per habit. Include archived habits' eligible history and past unlogged zeros; exclude N/A, pending, future, unavailable, out-of-range, and suspended values. Exclude all currently trashed habits. No eligible scores means neutral/no data, not zero. Do not average raw counts/minutes, count completions, average already-rounded values, or weight checkpoint habits by checkpoint count.

Example: count 5/10 scores 0.5, boolean completed scores 1, and N/A is excluded: overall score = 0.75. Add a past eligible unlogged habit and the overall score becomes 0.5. If that missing entry is today, it stays pending and the current result stays 0.75, explicitly marked provisional with one pending habit. Later recording or the next local day changes the denominator.

Profile date details show included, N/A, pending, unlogged, and unavailable counts so a high score cannot imply all habits were logged. Profile N/A counts mean habit-days, while the grid's no-data state means a date with no contributing score.

## Strict streaks and consistency windows

For an individual habit, a successful eligible day increments the run by one. Any eligible score below 1, including a past unlogged date, resets it. N/A, inactive/suspended dates, and future dates do not add or break a run. Missing today does not reset yesterday's run; recorded failure today does. An unavailable-data date cannot support a claimed uninterrupted run.

Current streak is the most recent unbroken run through today after skipping legitimate neutral/pending dates; longest is the maximum run over the full available history through today. Both count successful eligible days, not elapsed calendar days. An archived/ended habit can retain its last run through a neutral interval; label that it is paused/ended. Never reset longest on January 1 or truncate it to the displayed year. With no successes, return 0.

Overall profile streaks apply the same rule to daily aggregates: a scored date is fully successful only when every contributing habit fully succeeds. A profile day with any pending habit today may preserve the previous run but cannot extend it until all pending habits resolve; any already-recorded failure resets it immediately. A no-score neutral date does not extend or break it. Any unavailable habit-date interrupts an overall strict run rather than concealing missing evidence. Label these **Overall streaks** to distinguish them from per-habit streaks.

Examples: full success, N/A, full success yields a run of 2; success, partial, success yields 1 current and 1 longest; success yesterday plus unlogged today keeps 1; tomorrow, if still unlogged yesterday, current becomes 0. Returning from an archive interval preserves the earlier run until the next eligible failure.

- **30-day consistency:** the inclusive range from local today minus 29 calendar days through today (clamped to the supported minimum date). It is independent of the year selector.
- **Yearly consistency:** January 1 through December 31 of the selected year, clipped at local today. This is a calendar year, not a trailing 365-day period.
- Exclude legitimate neutral/pending/unavailable days; include past unlogged zeros. Show the scored denominator, separate N/A count, pending/unavailable counts where relevant, and “No eligible data” when denominator is zero. Never display 0% for an empty denominator.
- Calculate consistency by averaging habit daily scores, or profile daily aggregate scores equally by date, not pooled habit-days. A day with many habits must not outweigh a day with one habit. Round percentages only for presentation (one decimal place).

## UI and authorization

Add the individual yearly heatmap and metrics to the existing owner-only habit detail page. Add the overall yearly heatmap and metrics to the owner dashboard as the Phase 4 profile activity surface, preserving Track today and existing navigation. Show the owner's display identity and timezone; no separate browsable username/profile route or follower counts are needed.

Use a reusable Blade heatmap component with server-prepared cells, labels, and legend. Use explicit bands: score 0, `(0, 0.25]`, `(0.25, 0.5]`, `(0.5, 0.75]`, `(0.75, 1]`; stronger fill means better performance. Neutral cells have a distinct treatment and do not share the zero-score label. Show N/A, pending, unavailable, and inactive/future states with text/symbol cues, not color alone. A fully successful day is identified in text even when it shares a visual band with 0.9.

Provide Previous year, Current year, Next year, and a bounded year input; show the selected year, weekday/month context, legend, score explanation, and metric windows. Allow only the heatmap region to scroll horizontally on narrow screens; the page and controls must fit 320px/375px widths. Keep current-year focus near recent dates on first display without forcing focus changes after every update.

Cells have full date/status/performance labels and standard button/link semantics. Use Alpine only for focus/detail behavior: one entry tab stop with documented arrow-key date navigation (left/right one week, up/down one day), plus Enter/Space selection, visible focus, and no focus traps. Detail content must open on keyboard activation and touch, not hover alone; Escape closes transient details and returns focus. Do not claim an ARIA grid role unless all corresponding keyboard behavior is implemented.

Offer an equivalent paginated textual day list with ordinary controls and comfortable touch targets; it uses the same derived cells and never exposes notes/reasons. Selected habit-day details link to the existing entry editor when permitted. Selected overall-day details link to the existing daily overview. Future or ineligible cells explain their state without offering an invalid recording action. Screen-reader labels, selected-day updates, no-color understanding, reduced motion, focus restoration, and mobile interactions require actual browser checks.

Every request remains behind `auth`. Scope all reads through the authenticated user, authorize habit views using existing policies, and reauthorize on Livewire reads/actions; locked IDs are only defense in depth. Foreign/missing/trashed habit IDs return indistinguishable not-found responses. Analytics input never accepts an arbitrary owner ID. Select only required measurements, status, dates, version identifiers/configuration, and checkpoint completion fields; omit private notes and N/A reasons from queries, DOM, component payloads, details, and logs. Public/followers visibility does not grant Phase 4 access.

## Application and database boundaries

Use a small `app/Services/Analytics/` group for independently testable scoring and reused aggregation, rather than business logic in Blade:

- `HabitScore`: pure calculation from validated historical entry/setup, returning score, full-success flag, and state.
- `ActivityAnalytics`: owner-scoped data loading and date projection shared by habit/profile surfaces, using the existing tracking eligibility contract.
- `StreakCalculator`: a pure scan of scored/neutral/gap segments. Small arrays/value records suffice; no repository, generic pipeline, interface hierarchy, or event system.

Names are provisional. Extract another helper only when implementation demonstrates reusable complexity. If `TrackingDate` needs a pure eligibility helper for range processing, retain its current public behavior and prove parity with Phase 3 tests. Build immutable per-request results; UI code handles selection and rendering only.

No migration, new score column/table, cache, seeder, background job, or dependency is planned. Existing indexes include habit/date entries, owner/date/habit entries, habit/effective-date versions, version/checkpoint order, and habit/reason/end/start suspensions. Inspect actual query plans and query counts before adding any index; a demonstrated need requires a separate reversible additive migration and database documentation.

Load only the requested year and trailing-window data for heatmaps/consistency, plus predecessor/referenced versions and intersecting suspensions. Batch owner habits and range-limited entries/checkpoint counts; do not fetch notes or entire model graphs. Include habits with no entries so past missing dates are scored correctly. The profile result must cover every authorized habit, not just one habit-list pagination page.

Lifetime streaks need history outside the selected year. Process it in bounded batches/ordered segments, detecting unlogged gaps from eligibility intervals without materializing every date since year 1000. Never silently cap history to a recent year. A consistent read snapshot across related analytics queries must prevent mixing pre-edit and post-edit entry/configuration/checkpoint data; keep that read transaction short and avoid locking recording rows. Test old-or-new results during a concurrent edit, never a mixture.

Profile aggregation can grow with both habit count and date count. Merge batch sums/counts by calendar date, keep query count bounded by batches rather than cells, and measure realistic multi-year fixtures. No cache is introduced without profiling and a documented invalidation design for entry corrections, N/A, configuration, lifecycle, and timezone/day changes.

## Files likely to change

| Files | Intended work |
| --- | --- |
| `app/Services/Analytics/HabitScore.php`, `ActivityAnalytics.php`, `StreakCalculator.php` | Derived scores, historical projections, aggregation, streaks, consistency. |
| `app/Services/TrackingDate.php` | Only a justified shared eligibility extraction, retaining tracking behavior. |
| `app/Models/Habit.php`, `HabitEntry.php`, tracking models | Narrow query helpers/relationships only if required; no stored analytics fields. |
| `resources/views/pages/habits/⚡show.blade.php`, `resources/views/pages/⚡dashboard.blade.php` | Owner heatmaps, year/day selection, metric summaries. |
| `resources/views/components/activity-heatmap.blade.php`, `resources/css/app.css` | Reusable accessible grid/details/list, semantic score bands and responsive styling. |
| `routes/web.php`, `app/Policies/HabitPolicy.php` | Expected to retain existing routes/ownership; change only if required for those same surfaces. |
| `tests/Unit/Analytics/`, `tests/Feature/Analytics/`, `tests/Feature/DashboardTest.php` | Formula/sequence fixtures, integration/privacy/UI/query regression tests. |
| README and relevant `docs/` files | Contract, structure, commands, verification, roadmap, and eventual Phase 4 closeout. |

## Implementation sequence and acceptance gates

1. **Contract review:** verify the confirmed scoring/consistency decisions and the remaining concrete plan rules against ADR-0005 and the product text. Gate: no unresolved formula conflict, all five types and neutral/unlogged/zero-goal cases have numeric examples, and approval provenance is accurate.
2. **Pure scoring:** add focused table-driven tests and score/state calculation. Gate: every formula, exact success threshold, N/A precedence, invalid denominator, and higher-is-better invariant passes.
3. **Historical projections:** load owner-scoped configuration/entry/suspension data with consistent reads, derive missing dates, and aggregate profile scores. Gate: version/lifecycle/timezone boundaries, all-habit denominators, privacy exclusions, read-only behavior, and query behavior pass.
4. **Streaks and consistency:** calculate lifetime runs and the two named windows. Gate: cross-year runs, neutral gaps, provisional today, unlogged failures, weighted-vs-equal-date distinction, zero denominators, and bounded history processing pass.
5. **Interface:** integrate shared heatmap, year/day controls, metrics, accessible details/list, and recording links into habit details/dashboard. Gate: real desktop/mobile/browser interactions pass, including keyboard/touch and non-color cues.
6. **Closeout:** run focused/full checks, build, browser acceptance, query inspection, and design verification. Update implemented docs and create `PHASE_4_CLOSEOUT.md` with actual evidence. Mark Phase 4 complete only when all included gates pass; retain Phase 3 history and later-phase exclusions.

## Required tests and verification

| Requirement | Required evidence |
| --- | --- |
| Scoring | Boolean 0/1; count/duration below/at/above goals; values 0 and 999,999,999; checkpoint 0/some/all with historical denominator; avoidance below/at/above positive limit and 0/1 occurrences with zero limit; all goal-free assessments; deliberately contradictory numeric assessment/actual; N/A retained values excluded; no divide-by-zero/NaN or rounded false success. |
| Invariants | Score always finite in [0,1]; positive progress cannot lower positive-habit scores; more unwanted behavior cannot improve avoidance; overall mean correct independent of habit order/type/units; private fields absent from result and hydration payloads. |
| Eligibility/history | Inclusive start/end, absent baseline, predecessor before selected year, type/goal/checkpoint/date changes, pending/cancelled versions, snapshot rename/removal, archive/end history, overlapping/empty suspensions, trash denial/restoration, future-local retained entries, and unchanged raw records after analytics reads. |
| Dates | UTC/Asia-Manila day split, positive/negative offsets, DST spring/fall, midnight refresh, stable dates after timezone change, leap day, 365/366-year length, Monday alignment, padding, year 1000/9999 boundaries, and forged year/date Livewire payloads. |
| Unlogged/N/A | Missing past contributes zero without insert; missing today pending; pending becomes past failure on next local date; recorded failure today resets; all N/A/no eligible returns no data; count eligible N/A separately; missing goal differs from missing actual. |
| Metrics | Current/longest across year and inactive gaps; no-success case; partial interrupts; trailing 30 includes exactly 30 calendar dates; selected-year cutoff; known consistency numerator/denominator; overall daily mean versus pooled habit-day average; pending overall success cannot prematurely extend a streak. |
| Authorization | Guests and expired sessions; actual Livewire requests after logout; two users across private/followers/public preferences; foreign/trashed IDs; no arbitrary owner selector; note/reason canaries absent from queries/output/details/serialized state; ordinary escaping and middleware preserved. |
| Data/query behavior | Profile includes >20 habits and habits without entries; bounded queries per batch rather than per cell; representative many-habit/multi-year data; repeated neutral spans handled without huge daily arrays; consistent old-or-new view during a concurrent multi-record edit; no writes on reads. |
| Browser | Habit and profile grids agree with fixture values; year navigation/leap day; correct selected-day links; N/A/pending/zero distinct; keyboard arrows/activation/Escape/focus; accessible text list; mobile touch/region-only scroll at 320/375px and desktop; reduced motion, no-color interpretation, no JavaScript errors, and existing tracking flow regression. |

Use existing PHPUnit/MySQL and installed browser tooling. No browser library installation is required for this plan. Keep database-resetting tests serial and separate from browser fixtures. Guard both configured and connected database names as `habit_log_test`; never reset development data. Use actual domain Actions for history fixtures rather than bypassing their invariants.

Verification commands (observed outcomes are in the closeout):

```bash
docker compose up -d --wait
php artisan test tests/Unit/Analytics --compact
php artisan test tests/Feature/Analytics --compact
php artisan test tests/Feature/Tracking tests/Feature/Habits tests/Feature/DashboardTest.php --compact
composer ci:check
npm run build
git diff --check
```

`composer ci:check` includes Pint and the full suite. Record observed results and counts, browser/version/viewports, representative query counts/plans/timings, screenshots, and limitations. PHPUnit does not prove client interaction or real CSRF enforcement. Preserve middleware and use normal session/CSRF behavior in browser acceptance. Stop temporary processes afterward. No migration command is needed unless a separately justified index is introduced. Hosted CI remains contingent on separately authorized publication.

## Documentation, risks, and completion audit

This planning change replaces the old plan, updates README/roadmap/ADR links, records confirmed scoring decisions in ADR-0005 and `HEATMAP_SCORING.md`, and clarifies the product's analytic definitions. The original planning edit did not change runtime behavior or historical closeouts. The subsequent authorized implementation is recorded in the closeout.

As implementation lands, update architecture, database design (explicitly state analytics remain derived), UI/UX, security/privacy, testing, commands, and project structure. Link the new closeout from README/roadmap after verification. ADR-0002's universal higher-is-better rule and ADR-0004's history/date contract remain in force.

Material risks: users may confuse assessments with goal performance; today's profile score is provisional; trash changes included historical totals; lifetime analytics can be expensive; timezone changes alter “today” without moving rows; pre-Phase-3 configurations/completed inactive periods cannot be reconstructed. Address these with labels, denominator/state counts, bounded query work, boundary tests, and explicit closeout limitations. Do not fabricate unavailable history.

Completion requires requirement-by-requirement evidence against `DESIGN_VERIFICATION.md`: all roadmap outputs, server-side ownership/privacy, historical correctness, raw-data preservation, validation, focused/full tests, Pint/build, responsive accessible interactions, and accurate docs. Planning checks alone cannot close the implementation phase. No schema rollback is planned; removing derived UI/services must leave raw data intact, and any later formula change requires another ADR.

Planning verification (2026-10-01): inspected the current history models, eligibility service, policies, routes, daily overview, and project commands against the required documentation. Local Markdown links resolve; the retired plan has no remaining references; changes are limited to README/docs; `git diff --check` passes. Application tests, Pint, build, migrations, and browser checks are future implementation gates and were not rerun for this documentation-only change.

Suggested planning commit message, only if requested: `docs: plan Phase 4 heatmaps and analytics`.
