# Roadmap

## Phase 0 — Project Foundation (complete)

Documentation, Laravel, MySQL, Livewire, Alpine, Tailwind, authentication, minimal responsive shell, testing, formatter, and Git initialization.

## Phase 1 — Profile + Theme Foundation (complete)

Username, display name, bio, account privacy, theme preference, and improved shell. Avatar remains optional.

Closed after the full suite (66 tests), Pint, frontend build, and 27 browser smoke checks passed. See `PHASE_1_CLOSEOUT.md` for evidence and verification limits. CI uses the local MySQL Compose setup before migrations and tests; its remote execution awaits an authorized push or pull request.

## Phase 2 — Categories + Habit CRUD (complete)

System categories and aliases, custom categories and recommendations, habit CRUD/type/visibility/dates, archive, trash, and restore.

Schema, categories/recommendations, habit configuration, ownership policies, and archive/trash/restore are implemented. Closed after 148 tests / 687 assertions, Pint, the frontend build, migration rollback/reapply, and 42 browser checks passed. See [the Phase 2 closeout](PHASE_2_CLOSEOUT.md) for evidence and limitations and [the implementation plan](PHASE_2_PLAN.md) for scope and later-phase handoffs. Phase 3 has not started.

Confirmed scope: trash and restore are included; permanent deletion and automatic expiry are deferred to a separately planned follow-up.

## Phase 3 — Daily Tracking

Boolean, count, duration, checkpoint, and avoidance tracking; private notes and N/A states.

## Phase 4 — Heatmaps + Analytics

Habit and normalized profile heatmaps; current/longest strict streaks and 30-day/yearly consistency.

## Phase 5 — Journal

Private daily journal, mood, and date navigation.

## Phase 6 — Social

Directional followers, private-account requests, approval/rejection, permitted profile viewing, and visibility enforcement. No comments.

## Phase 7 — Hardening

Security, privacy, accessibility, mobile, performance/query reviews; critical E2E coverage and deployment documentation.
