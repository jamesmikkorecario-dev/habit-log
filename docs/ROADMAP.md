# Roadmap

## Phase 0 — Project Foundation (complete)

Documentation, Laravel, MySQL, Livewire, Alpine, Tailwind, authentication, minimal responsive shell, testing, formatter, and Git initialization.

## Phase 1 — Profile + Theme Foundation (complete)

Username, display name, bio, account privacy, theme preference, and improved shell. Avatar remains optional.

Closed after the full suite (66 tests), Pint, frontend build, and 27 browser smoke checks passed. See `PHASE_1_CLOSEOUT.md` for evidence and verification limits. CI uses the local MySQL Compose setup before migrations and tests; its remote execution awaits an authorized push or pull request.

## Phase 2 — Categories + Habit CRUD (complete)

System categories and aliases, custom categories and recommendations, habit CRUD/type/visibility/dates, archive, trash, and restore.

Schema, categories/recommendations, habit configuration, ownership policies, and archive/trash/restore are implemented. Closed after 148 tests / 687 assertions, Pint, the frontend build, migration rollback/reapply, and 42 browser checks passed; the test-account follow-up brought the full suite to 151 tests / 702 assertions. See [the Phase 2 closeout](PHASE_2_CLOSEOUT.md) for evidence, scope decisions, and limitations.

Confirmed scope: trash and restore are included; permanent deletion and automatic expiry are deferred to a separately planned follow-up.

## Phase 3 — Daily Tracking (complete)

Boolean, count, duration, checkpoint, and avoidance tracking; private notes and N/A states.

Daily tracking, timezone settings, historical configuration, lifecycle gaps, and owner-only entry history are implemented. Closed after 200 tests / 1,100 assertions, Pint, the frontend build, migration/concurrency verification, and 34 main browser checks plus a settled mobile-menu check passed. Both additive migrations are applied locally. See [the Phase 3 closeout](PHASE_3_CLOSEOUT.md) for evidence and limits and [ADR-0004](adr/ADR-0004-DAILY-TRACKING-HISTORY.md) for the date/history contract.

## Phase 4 — Heatmaps + Analytics (complete)

Habit and normalized profile heatmaps; current/longest strict streaks and 30-day/yearly consistency.

Owner-only habit/dashboard heatmaps, lifetime current/longest streaks, and 30-day/yearly consistency are implemented. Closed after **243 tests / 1,798 assertions**, Pint, the production build, **41 browser checks**, a concurrent-read snapshot regression, and a 55-habit/1,650-entry query measurement passed. No migration, cache, dependency, or public sharing was added. See [the closeout](PHASE_4_CLOSEOUT.md) for evidence and limits and [the scoring contract](HEATMAP_SCORING.md) for its analytics rules. [ADR-0005](adr/ADR-0005-ANALYTICS-SCORING.md) records the user-selected avoidance limits, goal-free scoring, unlogged/pending treatment, and average-performance consistency.

## Phase 5 — Journal (complete)

Private daily journal, optional mood, date navigation, and confirmed deletion are implemented. Required plain text, owner-only access, timezone-aware dates, stale-write protection, and dirty-draft navigation guards follow [the plan](PHASE_5_PLAN.md). Verification includes 294 tests / 2,126 assertions, Pint, the production build, and 37 installed-browser checks; see [the closeout](PHASE_5_CLOSEOUT.md) for evidence, findings, and limitations. The additive journal migration is applied locally. Habit permanent deletion and automatic expiry remain deferred.

## Phase 6 — Social

Directional followers, private-account requests, approval/rejection, permitted profile viewing, and visibility enforcement. No comments.

## Phase 7 — Hardening

Security, privacy, accessibility, mobile, performance/query reviews; critical E2E coverage and deployment documentation.
