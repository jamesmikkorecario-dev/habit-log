# Testing strategy

Use the single framework supplied by the Laravel 13 Livewire starter (PHPUnit). Prefer feature tests for HTTP/Livewire behavior and unit tests for isolated scoring or domain rules when those exist.

Phase 0 covers successful and invalid registration, successful and invalid login, logout, guest denial/redirect for the application page, and authenticated access. Database tests use only `habit_log_test`, reset safely, and remain repeatable.

Phase 1 covers profile loading/saving, username uniqueness and boundaries, nullable text, literal/padded zero text, privacy defaults/toggling, theme validation, escaped identity output, and dashboard navigation. Ownership tests verify that another user's record remains unchanged and that a real Livewire update request is rejected after logout. Invalid submissions must leave all stored profile values unchanged.

CI runs on pull requests and pushes to `main` or `master`. It starts the repository's MySQL Compose service with a health wait before application setup; the initialization script creates `habit_log_test` alongside `habit_log`. Composer runs migrations for setup, then the test command runs Pint and PHPUnit. Setup also builds frontend assets.

Browser smoke testing is a separate acceptance check: register/login/logout, save and reload profile fields (including `"0"`), trigger validation, toggle privacy, and use desktop/mobile navigation with keyboard focus. A regular installed browser is sufficient; browser automation is not an application dependency. Record the browser, viewport, outcomes, and any limitations in the phase closeout report. PHPUnit request tests do not prove client-side interaction or CSRF behavior because the framework bypasses CSRF checks during tests.

Each later feature tests happy paths, validation, server-side authorization/privacy, lifecycle and date boundaries, destructive behavior, and regressions. Run focused tests while developing, then the full suite, Pint, and production asset build before handoff.

Phase 2 tests live under `tests/Feature/Categories`, `tests/Feature/Habits`, and `tests/Unit/CategoryRecommenderTest.php`. They cover recommendation ranking/normalization, seed idempotency, custom categories, all habit configurations, optional/zero goals, date and checkpoint boundaries, atomic failed writes, ownership, session expiry, lifecycle recovery, pagination, and database constraints. A dedicated migration test first checks both configured and connected database names are `habit_log_test`, then applies the explicitly selected Phase 0–2 migration set, verifies four-step rollback preserving a profile, and reapplies migrations. This remains independent of later migrations. Run these tests serially; do not run browser fixtures concurrently with database-resetting tests.

Browser acceptance separately checks Livewire form rendering, category selection/creation, all habit types, checkpoint reorder/removal, validation, lifecycle confirmations/recovery, keyboard focus, reduced motion, and mobile overflow. Existing Chrome and an available automation runtime may be used without adding a project browser dependency. Record observed results in the Phase 2 closeout report after verification.

Phase 3 tests under `tests/Feature/Tracking` cover all types, numeric/text boundaries, private notes and N/A, dated setup, checkpoint snapshots, lifecycle gaps, timezone/DST boundaries, stale revisions, authentication/ownership, pagination, schema constraints, populated upgrade, interrupted/repeated backfill, and rollback/reapply. Fixtures use real transactional Actions rather than bypassing version invariants with raw entry factories.

Concurrency verification uses independent MySQL connections with a short lock timeout and a controlled PHP subprocess that waits behind the habit lock, then checks the committed revision. It guards configured and connected database names and never uses development data. Run these tests serially; do not use parallel testing or run database-resetting suites while browser fixtures are active. Every phase closeout records actual counts and verification limits.

Browser acceptance includes saved/reloaded activity for all five types, N/A transitions, timezone settings, historical labels and backfill, pending setup, conflict feedback, dirty-form navigation, mobile layout/keyboard behavior, and normal session/request-forgery middleware. Existing browser tooling is sufficient; no browser dependency installation is required.
