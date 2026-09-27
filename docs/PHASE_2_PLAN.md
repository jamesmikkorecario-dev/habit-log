# Phase 2 implementation plan — Categories + Habit CRUD

Status: implemented and verified for the confirmed Phase 2 scope; see [the closeout report](PHASE_2_CLOSEOUT.md). Originally prepared 2026-09-27 against Phase 1 commit `738a16c` and a clean worktree. The baseline below describes that planning snapshot; current schema and UI behavior are recorded in `DATABASE_DESIGN.md` and `UI_UX_SPEC.md`.

## Relevant requirements and current baseline

Follow [AGENTS.md](../AGENTS.md), [design guardrails](DESIGN_GUARDRAILS.md), [product requirements](PRODUCT_REQUIREMENTS.md), [architecture](ARCHITECTURE.md), [database design](DATABASE_DESIGN.md), [UI specification](UI_UX_SPEC.md), [roadmap](ROADMAP.md), and accepted ADRs, in that order. Review the implementation against [design verification](DESIGN_VERIFICATION.md).

The current application has authenticated Dashboard and Profile Settings Livewire pages, a responsive Flux/Alpine shell, and user/profile migrations. There are no domain models, tables, policies, or routes for categories or habits. The Phase 1 closeout recorded 66 passing tests plus Pint, build, and browser checks; these are historical baseline results, not verification of Phase 2.

Phase 2 is complete when an authenticated user can choose a system or personal category, receive deterministic recommendations before creating a custom category, create and manage all five habit configurations, and archive, trash, and restore their own habits. Every read and mutation must enforce ownership on the server.

## Scope and proposed defaults

Included:

- Seeded system categories and aliases; private custom category creation and selection.
- Deterministic recommendations using normalized names, aliases, and curated keywords, with the user's choice preserved (DG-008/009).
- Habit list, detail, create, and edit; boolean, count, duration, checkpoint, and avoidance configurations; one required category; optional goals where meaningful; dates and visibility.
- Configurable checkpoint labels and order, without daily completion records.
- Archive/unarchive, recoverable trash, and restore; preserved rows and configuration (DG-013).
- Mobile and desktop navigation, validation feedback, empty states, accessible confirmations, and focused tests.

Excluded: daily entries, notes, N/A recording, journals/mood, streaks, heatmaps, caches, followers, public profiles, sharing endpoints, notifications, and additional themes. Do not create their tables or placeholder services. Category rename/delete, user-managed aliases, bulk actions, and advanced search are not required by this phase.

The user confirmed the lifecycle boundary on 2026-09-27: trash and restore now; permanent deletion and automatic expiration deferred. Until that follow-up exists, trash remains recoverable, including after 30 days; the UI must not promise an implemented expiration date. The product's roughly 30-day recovery period and confirmed complete deletion remain outstanding product requirements, not cancelled requirements.

Use application UTC for timestamps, matching `config/app.php`. Store entered start/end dates as calendar dates without timezone conversion. Do not infer the application timezone from the developer's machine. User-local daily boundaries belong to Phase 3 planning.

## Behavior and validation

### Categories and recommendations

Show system categories plus the signed-in user's custom categories. Never return another user's custom names or aliases in options, recommendations, validation feedback, or habit responses.

Proposed initial seed catalog (editable curated data, not a new taxonomy feature):

| Category | Example aliases / keywords |
| --- | --- |
| Health & Fitness | exercise, workout, movement |
| Nutrition | food, meals, hydration, water |
| Sleep | bedtime, rest |
| Learning | study, reading, practice |
| Work & Productivity | focus, planning, productivity |
| Mindfulness | meditation, breathing |
| Home & Personal Care | cleaning, chores, hygiene |
| Relationships | family, friends, connection |
| Finance | saving, budget, spending |
| Other | miscellaneous |

Make seed runs idempotent using stable system slugs; update only seeded records and never overwrite custom categories. Expose a dedicated category seeder so installing the catalog does not require creating the starter's example user. Keep curated alias/keyword rows in this seeder; custom users do not edit them in this phase.

Normalize category input by trimming, Unicode-aware lowercasing, collapsing whitespace, and replacing punctuation runs with spaces. Preserve Unicode letters/digits; do not discard non-Latin names. Reject a normalized empty name. Keep the display name separate. Match only authorized candidates, then rank by: exact normalized name, exact alias, then whole-token keyword overlap. Rank overlap by matched-token count descending; break ties by normalized name and category ID. Return at most three distinct categories, excluding zero-score matches. Identical data/input must produce identical results; no network, fuzzy AI, or external service.

The custom creation flow first shows any recommendations with ordinary “Use this category” buttons and a “Create my category” alternative. A match to a system category must not force adoption. Prevent duplicate normalized custom names within the same user's collection; two users may use the same name. If the user already owns an exact duplicate, offer that existing category. Recompute recommendations and validate ownership on submission rather than trusting a submitted recommendation ID.

Proposed limits: category name 1–60 characters after trimming, description optional up to 500. Preserve literal `"0"` text. Custom ownership, `is_system=false`, and slug are assigned on the server; none is editable client input. Standalone category creation is sufficient; choosing a category and saving a habit are separate submissions, so a failed habit save does not silently delete a category the user created.

### Habit configuration

Common fields: trimmed name 1–100 characters, optional description up to 2,000, exactly one accessible category, recognized type, visibility in `private|followers|public`, required valid start date, and optional end date on/after the start date. Default visibility is private in both the form and database, independent of account privacy. Default start date is today's application date and remains editable; past/future dates are valid. Bound dates to MySQL DATE's supported application range (1000-01-01 through 9999-12-31).

| Type | Configuration accepted in Phase 2 |
| --- | --- |
| Boolean | No numeric goal, unit, or checkpoints. |
| Count | Optional positive whole-number goal; optional unit label up to 30 characters. |
| Duration | Optional positive whole-minute goal; canonical unit `minutes`. |
| Checkpoint | 1–20 ordered labels, each 1–80 trimmed characters; no numeric goal/unit. Reject blank or duplicate normalized labels. |
| Avoidance | Optional nonnegative whole-number maximum, including zero for stopping a behavior; optional unit label up to 30 characters. |

Proposed numeric ceiling: 999,999,999. Whole numbers are the initial input contract, not a claim that product requirements demand integer-only tracking. Persist goals in an integer column; duration always means minutes. State units and constraints beside inputs. Reject fractional, negative, oversized, or malformed values. Blank optional goals become null; zero is preserved for avoidance.

Type switching must visibly explain which incompatible settings will be cleared. The server derives applicable fields and clears incompatible goal/unit/checkpoint data in the same transaction; forged incompatible fields cannot persist. Existing checkpoint IDs must belong to this habit. Reordering retains IDs; removed labels remove only configuration rows while no daily-entry tables exist. Do not add historical-entry rewrites or future entry-detection scaffolding.

Permit configuration edits on active and archived habits; trashed habits must be restored first. All invalid submissions leave existing rows unchanged. List rows show name, category, type, visibility, dates, and lifecycle state as text. Future-start or ended habits may remain lifecycle-active; dates and archive/trash are distinct concepts.

Provide `/habits`, `/habits/create`, `/habits/{habit}`, `/habits/{habit}/edit`, and `/categories` under `auth`. Use the habit list's Active / Archived / Trash tabs, an optional category filter, stable ordering, and pagination (20 per page). Scope before filtering/pagination and eager-load categories to avoid per-row queries. A trashed item has only its owner-visible summary and Restore action in Trash; normal detail/edit routes exclude it.

Store followers/public as preferences now. Explain that sharing becomes available in Phase 6; these choices must not expose data through Phase 2 routes. The private account preference must never alter new-habit defaults.

### Lifecycle

| Action | Allowed source | Result |
| --- | --- | --- |
| Archive | Active, not trashed | Set `archived_at`; preserve configured dates and checkpoints. |
| Unarchive | Archived, not trashed | Clear `archived_at`; preserve original dates, even if the end date has passed. |
| Move to trash | Active or archived | Set `deleted_at`; preserve archive state and all configuration. |
| Restore | Trashed | Clear `deleted_at`; recover the prior active/archived state without changing dates or visibility. |

Reauthorize and reload the habit for every action. Reject invalid transitions without writes; repeated requests must not change the original archive/trash timestamp. A stale edit submitted after trashing must fail instead of silently saving or restoring the habit. Use a transaction with a locked current row for edits/lifecycle transitions that can race with each other; checkpoint synchronization shares that transaction.

Confirm moving to trash with a named, keyboard-accessible dialog explaining recovery. Archive/unarchive and restore provide clear status feedback. No `forceDelete` action, scheduled prune, or automatic 30-day cutoff is included under the confirmed boundary.

## Database changes

Add four migrations in dependency order and reverse them in dependency order on rollback. Do not modify the already-applied profile migration.

| Table | Planned columns and integrity |
| --- | --- |
| `categories` | Planned fields from database design plus `normalized_name` and timestamps. Nullable owner only for system categories; system/owner consistency checked by application validation and a database check constraint. Globally unique generated slug; unique `(user_id, normalized_name)` protects custom duplicates. MySQL's nullable-owner uniqueness does not protect system names: seed stable unique slugs and keep system creation unavailable to users. |
| `category_aliases` | Category FK, display `alias`, `normalized_alias`; unique `(category_id, normalized_alias)`. Exact/token matching runs against the small authorized catalog; no speculative search indexes. |
| `habits` | Planned fields, timestamps, soft deletes; required owner/category FKs; string-backed type/visibility enums, database default `private`; nullable unsigned integer goal; DATE start/end; nullable archive timestamp. Start with `(user_id, deleted_at, archived_at, id)` for lifecycle lists and the category FK index; check actual queries before adding more. |
| `habit_checkpoints` | Habit FK, label, nonnegative sort order, timestamps; index `(habit_id, sort_order)`. Validate ordered positions and label uniqueness in the write boundary; no unique position constraint that makes swaps fail halfway through. |

Use PHP backed enums for type/visibility with string columns and enum validation/casts, rather than database ENUMs. Do not expose server-owned fields through mass assignment from request payloads.

FK behavior: restrict deleting users while owned categories/habits exist; restrict deleting a category referenced by habits (including trashed habits); cascade category aliases when their category is deliberately deleted; cascade checkpoints on eventual physical habit deletion. Soft deletion triggers no cascades. No user/category deletion UI is introduced here. The habit/category FK proves existence, while a policy plus scoped validation proves that the selected category is system-owned or belongs to the habit owner.

Unique custom-name races should become a field error or selection of the existing own category, not a database exception page. Multi-row habit/configuration writes must roll back together. Test migration up/down/reapply using an isolated test database, confirming that prior user/profile tables survive rollback of just these four migrations.

## Authorization impact

- `CategoryPolicy`: authenticated users can list/select system and own categories and create their own custom categories. No user may mutate a system category or read another user's custom category.
- `HabitPolicy`: owner-only view/update/archive/unarchive/delete/restore. `delete` means soft deletion in this phase. Deny force deletion. No visibility preference overrides ownership on these management routes.
- Every route uses authentication middleware; every Livewire action authorizes again. Scope reads through the authenticated user's relationship and validate category/checkpoint references on the server. Do not rely on hidden buttons or locked component properties as authorization.
- Only the owner-scoped Trash query uses `onlyTrashed`/`withTrashed`. Keep ordinary binding and listing free of trashed rows. Missing and foreign route identifiers should produce the same not-found response; action attempts must fail without disclosing foreign content.
- Preserve session/CSRF middleware and escaped output. Reject forged owner IDs, system flags, lifecycle timestamps, and checkpoint IDs. Never log submitted private habit descriptions.

## Files likely to change

| Area | Likely files |
| --- | --- |
| Schema and fixtures | Four new `database/migrations/*` files; `CategorySeeder.php`; `DatabaseSeeder.php`; factories for categories, aliases, habits, and checkpoints. |
| Models | `app/Models/{Category,CategoryAlias,Habit,HabitCheckpoint}.php`; relationships on `User.php`. |
| Validation and domain behavior | `app/Enums/{HabitType,HabitVisibility}.php`; shared `app/Livewire/Forms/HabitForm.php`; `app/Services/CategoryRecommender.php` for independently testable ranking; `app/Actions/Habits/SaveHabit.php` for transactional configuration saving. Keep simple lifecycle operations in their component unless reuse/complexity justifies extraction. |
| Authorization | `app/Policies/{CategoryPolicy,HabitPolicy}.php`, using Laravel policy discovery conventions. |
| Pages | Existing Livewire single-file convention under `resources/views/pages/habits/` and `resources/views/pages/categories/`; small shared form partial if needed. |
| Integration | `routes/web.php`, app layout navigation, dashboard habit-list entry point/empty state. Retain the existing greeting and profile navigation; do not turn the dashboard into a tracking screen. |
| Tests | New feature suites under `tests/Feature/Categories/` and `tests/Feature/Habits/`; recommendation unit tests under `tests/Unit/`; navigation regressions in `DashboardTest.php`. |

No new Composer/npm dependency, browser library installation, repository layer, API, queue, or cache is needed.

## Implementation sequence and acceptance gates

1. **Schema, models, catalog.** Add migrations, enums, relationships, factories, category policy, and idempotent seeding. Gate: up/down/reapply on the isolated test database, valid FK/default/unique behavior, repeatable seeds without an example-user dependency.
2. **Category selection and recommendations.** Implement the small normalizer/ranker and category page/create flow. Gate: deterministic fixtures, user choice after recommendations, duplicate handling, and no cross-user data through any category path.
3. **Habit create/read/update.** Add policy, form validation, transactional save, list/detail/edit pages, and checkpoint configuration. Gate: all five types round-trip, invalid submissions make no partial writes, defaults stay private, and foreign identifiers fail.
4. **Lifecycle.** Add archive/unarchive, Trash tab, confirmation, restore, and stale-state protection. Gate: prior archive state/configuration survives trash/restore, invalid transitions are harmless, and owner checks apply to every action.
5. **Shell and acceptance.** Add desktop/mobile Habits and Categories navigation; dashboard entry point; loading, empty/error/success states; keyboard/focus and responsive review. Complete docs and the full verification matrix below.

Suggested implementation commits, only if separately requested: `feat: add category catalog and recommendations`; `feat: add habit configuration and ownership policies`; `feat: add habit archive trash and restore`; `test: verify Phase 2 flows and document acceptance`. Keep tests with their behavior changes, rather than postponing them to the last commit.

## Test plan

| Requirement | Required evidence |
| --- | --- |
| Catalog and recommendation behavior | Repeat seeding; exact-name/alias/token precedence; tie ordering; punctuation/case/whitespace/Unicode; empty/no match; three-result cap; no substring false positives; same name across owners; same-owner duplicate/race; accepting a recommendation and choosing custom creation despite a system match. |
| Configuration | Create/edit each type; optional goals; avoidance zero; goal limits/fractions; canonical minutes; dates including equal endpoints/leap day/reversed range; category required/foreign/missing; invalid enums; trim/null and literal `"0"`; compatible type changes with incompatible fields cleared. |
| Checkpoints and atomicity | Add/rename/reorder/remove; 1/20 boundaries and 0/21 rejection; duplicate/blank labels; forged foreign IDs; retained IDs on reorder; failed synchronization rolls back habit and checkpoint changes together. |
| Ownership/privacy | Guest HTTP routes and Livewire actions; two-user list/detail/edit isolation for every visibility; custom category leakage in recommendations/errors; forged owner/category/checkpoint IDs; direct lifecycle invocation; stale Livewire request after logout; rejected writes leave all affected rows unchanged. |
| Lifecycle/integrity | Archive/unarchive preserves dates; active and archived trash/restore; trashed items absent from normal routes; stale edit/action rejection; duplicate actions; restoration after 30 days under the confirmed retention boundary; no physical deletion; FK/default/uniqueness constraints and restricted category/user deletion. |
| UI/regressions | Pagination/filtering cannot widen ownership; escaped names/descriptions/labels; navigation, profile saving, registration/login/logout remain functional; success/error announcements and useful empty states. |

Use PHPUnit feature tests for database/Livewire behavior and unit tests for pure normalization/ranking. Exercise transactions with a meaningful forced failure after the parent write. Do not substitute policy-unit tests for requests through actual routes/actions.

Browser acceptance uses an existing installed browser with normal session/CSRF middleware and test-only fixture data: create/edit all types, choose/override recommendations, reorder checkpoints, validate dates/goals, archive/trash/cancel/confirm/restore, refresh persisted state, keyboard-only operation, focus return after dialogs, reduced motion, desktop and 375px/320px widths. Check logout/session expiry and a missing-CSRF request separately from PHPUnit, which bypasses CSRF during tests. Record browser/version/viewports/outcomes; installing browser libraries is not a Phase 2 requirement.

## Documentation changes and verification

During implementation, update `DATABASE_DESIGN.md` with exact columns, constraints, casts, and rollback behavior; `UI_UX_SPEC.md` with fields/lifecycle and sharing limitations; `TESTING_STRATEGY.md` with permanent coverage/browser evidence; `PROJECT_COMMANDS.md` with dedicated seeding/verification commands; and `PROJECT_STRUCTURE.md`, README, and roadmap with actual progress. Add a Phase 2 closeout report. Change architecture/ADRs only if a material boundary or accepted decision actually changes.

Run focused suites while developing. Final implementation gate:

```bash
docker compose up -d --wait
php artisan test tests/Feature/Categories --compact
php artisan test tests/Feature/Habits --compact
php artisan test tests/Unit --compact
composer ci:check
npm run build
git diff --check
```

Before any destructive migration verification, inspect the resolved database configuration and require `habit_log_test`; abort for development/production/unknown names. Test up/down/reapply only there. `composer ci:check` includes Pint and the full suite. Record actual command results and migration/browser evidence; do not reuse Phase 1 counts as Phase 2 evidence. Observe hosted CI only after a separately authorized push/PR.

Review every applicable item in `DESIGN_VERIFICATION.md`: product scope; server-side ownership and defaults; FK/index/transaction/rollback evidence; confirmations; validation and regressions; accessible mobile UI; and accurate docs. Mark journals, mood, analytics, and permanent deletion as unimplemented/out of this phase, without claiming their acceptance tests have passed. Phase 2 may be closed only after all included gates pass.

## Risks and later-phase handoffs

- **Retention:** this plan deliberately leaves the product's eventual 30-day cleanup and confirmed permanent deletion outstanding. Schedule that work explicitly before describing the full product lifecycle as complete; do not add a destructive cron job implicitly.
- **History:** Phase 3 must decide how type/goal/checkpoint/date edits and repeated archive/unarchive periods interact with existing daily records before enabling those combinations. Phase 2 has no raw activity to rewrite; do not assume configuration replacement will remain safe after tracking ships.
- **Scoring gaps:** existing scoring docs do not resolve optional goals, zero avoidance maximum, or whether an “acceptable maximum” should count as worst performance. Phase 2 stores configuration without calculating scores; resolve these semantics through the required ADR process before Phase 4. Do not reject valid zero avoidance configuration solely to fit the current formula.
- **Privacy rollout:** Phase 6 needs its own account/follower/visibility policies and safe output projections. Stored public/followers preferences are not evidence that sharing works today.
- **Planning history:** the original planning pass was documentation only. Subsequent implementation and runtime verification are recorded separately in `PHASE_2_CLOSEOUT.md`.
