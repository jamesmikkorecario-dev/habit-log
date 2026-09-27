# Phase 2 closeout — 2026-09-27

Phase 2 — Categories + Habit CRUD is implemented and passes its scoped acceptance checks. Phase 3 daily tracking has not started. Permanent deletion and automatic trash expiry remain deferred, as explicitly selected by the user during planning.

## Behavior delivered

- Ten seeded system categories and curated aliases, plus private personal categories. Custom creation first presents deterministic name/alias/token recommendations and preserves the option to create a personal category. Normalization handles Unicode and literal `"0"`; duplicate custom names are constrained per owner, including a concurrent-insert race.
- Authenticated category and habit navigation, paginated Active/Archived/Trash lists with category filtering, and habit create/detail/edit pages. Habits configure boolean, count, duration, checkpoint, or avoidance tracking. Each requires one accessible category, valid dates, and a private-default visibility preference. Goals are optional where meaningful; duration uses minutes and avoidance permits zero.
- Ordered checkpoint labels with add/remove/reorder controls and retained row IDs. Configuration saves and checkpoint writes share a transaction. Existing writes lock the habit row and reject stale/foreign references.
- Archive/unarchive preserves dates and configuration. Confirmed trash is reversible; restore returns the prior active/archived state. Trashed records stay out of ordinary detail/edit routes, remain recoverable after 30 days, and cannot be permanently deleted through the application.
- Owner-only policies and scoped reads/actions, including for habits with public/followers preferences. Those preferences do not enable sharing yet. Session, CSRF/origin protection, escaped output, and prior authentication/profile behavior are preserved.
- The requested test-account follow-up is included in the Phase 2 commit: `TestAccountSeeder` creates `test@example.com` with the documented local password only in local/testing environments. Repeated seeding preserves an existing account's password and profile; the main seeder runs both category and account seeders. The account has ordinary private defaults and no elevated role.

## Main files and boundaries

| Area | Files |
| --- | --- |
| Schema | Four migrations named `2026_09_27_000001_create_categories_table.php` through `2026_09_27_000004_create_habit_checkpoints_table.php`. |
| Models/fixtures | `app/Models/{Category,CategoryAlias,Habit,HabitCheckpoint}.php`, relationships on `User.php`, four factories, `CategorySeeder.php`, `TestAccountSeeder.php`, and their invocation from `DatabaseSeeder.php`. |
| Domain/authorization | `app/Enums/{HabitType,HabitVisibility}.php`, `app/Policies/{CategoryPolicy,HabitPolicy}.php`, `app/Support/NormalizedName.php`, `app/Services/CategoryRecommender.php`, `app/Livewire/Forms/HabitForm.php`, and transactional `app/Actions/Habits/SaveHabit.php`. |
| UI | Category page, three habit pages, routes, dashboard entry points, desktop/mobile navigation, and reduced-motion dialog CSS. |
| Permanent tests | `tests/Feature/Categories/CategoryTest.php`; `tests/Feature/Habits/{HabitTest,LifecycleTest,SchemaTest,MigrationTest}.php`; `tests/Unit/CategoryRecommenderTest.php`; `tests/Feature/Auth/TestAccountSeederTest.php`. |
| Documentation | README, database/UI/test/command/structure docs, roadmap, implementation plan, and this report. |

The application remains a Laravel modular monolith. The action exists for a multi-record transaction; the recommender is independently testable logic. No packages, browser libraries, queues, caches, APIs, or repository layers were added. No accepted architecture/privacy/scoring decision changed, so no new ADR was required.

## Verification evidence

| Command/check | Observed result |
| --- | --- |
| `php artisan test tests/Feature/Habits tests/Feature/Categories tests/Unit/CategoryRecommenderTest.php --compact` | 79 tests / 480 assertions passed before three additional race/identity/mass-assignment tests were added. |
| `./vendor/bin/pint --dirty` | Formatted changed PHP files. |
| `composer ci:check` after the test-account follow-up | Passed: Pint and the full suite, **151 tests / 702 assertions**, including 85 new tests and prior authentication/profile coverage. Initial Phase 2 acceptance passed at 148 tests / 687 assertions before the three account-seeder tests were added. |
| `npm run build` | Passed. Empty app JavaScript chunk remains expected because Livewire/Flux provide their scripts. |
| `MigrationTest` in the full suite | Verified configured and connected database names are `habit_log_test`; fresh migrations, rollback of exactly four Phase 2 migrations, profile preservation, and reapplication all passed. |
| Existing Chrome browser checks | **42 passed**, no client-side errors. Desktop/mobile screenshots were inspected. |
| `php artisan migrate` | Applied all four Phase 2 migrations to the local `habit_log` development database after verification. No reset/rollback of development data. |
| `php artisan db:seed --class=CategorySeeder` | Installed the ten system categories and aliases in development without creating an example user. |
| `php artisan db:seed --class=TestAccountSeeder` | Created the requested account in local `habit_log`; its documented password was verified without exposing the stored hash. Tests verify login, repeat seeding without overwrites, and exclusion from production. |
| Whitespace and documentation review | `git diff --check` and local Markdown link/whitespace checks passed. New files were reviewed alongside tracked diffs. |

All automated tests and browser fixtures used `habit_log_test`. Browser testing used a temporary local server with normal session and CSRF/origin middleware, not the testing middleware bypass. The temporary server/browser were stopped. Tests that reset the database were not run concurrently with browser verification. Implementation and the account follow-up were originally left uncommitted; the user subsequently authorized the Phase 2 commit. No push is authorized.

## Browser findings and acceptance

Used the already-installed Windows Chrome **154.0.8037.57** and existing Playwright runtime without adding dependencies. Viewports: **1280 × 900**, **375 × 812**, and **320 × 812**. The temporary harness, result JSON, and screenshots are under `/tmp/habit-log-phase2`; this report is the durable evidence summary.

The passing checks covered guest denial, registration/dashboard entry points, recommendation selection, custom creation despite a match, private defaults, all five create/edit flows, server-side date validation, visible save feedback, persisted zero text and visibility, checkpoint reorder/removal persistence, archive/unarchive, named trash confirmation, Escape cancellation and focus restoration, restore to the prior archive state, no-expiry wording, mobile navigation/no overflow, reduced motion, another user's denial for a public-preference habit, request-forgery rejection, dark theme, no JavaScript errors, and logout denial.

Browser testing found and fixed two UI defects that PHP tests alone did not detect:

1. Reordering retained checkpoint rows but their index-based inputs displayed stale values. Including both row identity and current position in the rendering key refreshes the binding after reorder; the moved label receives focus.
2. Modal label attributes landed on Flux's wrapper instead of its native dialog. The dialog now receives its accessible name/description directly, and closing it restores focus to the relevant trash trigger when that row remains present.

The request-forgery probe omits both a token and a trusted same-origin header and receives HTTP 419. An initial in-page `fetch` was an invalid negative test: Laravel 13's installed middleware accepts trusted same-origin requests. No application middleware change was needed.

## Design verification and limits

Product scope matches the Phase 2 roadmap and confirmed retention boundary. Every habit/category selection is authorized server-side; public/followers preferences never widen management access. New habits default private in both the form and database. Later journal, mood, notes, tracking, and analytics features remain absent, so no raw activity was created or rewritten.

Foreign keys, scoped indexes, category ownership checks, unique constraints, reversible migrations, row locks, and transactions are implemented and tested. Failure after the parent habit write rolls back checkpoint and habit changes together. Tests cover invalid input, foreign identifiers, session expiry, stale mutations, locked identity, mass-assignment protection, repeat actions, recovery, escaped output, and pagination isolation. Mobile controls, dialog keyboard cancellation/focus, status feedback, and reduced motion were exercised with ordinary browser controls.

Hosted CI has not run: no push or pull request was authorized. Browser smoke checks are scoped acceptance evidence, not an exhaustive screen-reader, cross-browser, concurrency-load, accessibility, or performance audit; those broader reviews remain Phase 7. The race test simulates an intervening competing insert, rather than a multi-process load test. Indexes follow current owner/lifecycle/category queries; no profiling-based cache is introduced.

Before Phase 3, decide user-local daily boundaries and how later entries interact with configuration changes and archive/unarchive periods. Before Phase 4, resolve the previously documented optional/zero-goal and avoidance-scoring semantics through the required ADR process. Permanent deletion and automatic expiry need their own explicitly planned follow-up.

Suggested commit message, if requested: `feat: implement Phase 2 categories and habit management`.
