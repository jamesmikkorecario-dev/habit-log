# Phase 3 implementation plan — Daily Tracking

Status: implemented and verified for the scoped Phase 3 contract; see [the closeout report](PHASE_3_CLOSEOUT.md). Prepared 2026-09-27 against Phase 2 commit `1f55efe` and an initially clean worktree. This document replaces the previous phase's plan; [Phase 2's closeout](PHASE_2_CLOSEOUT.md) retains its delivered scope and verification evidence.

## Requirements and findings

Follow [AGENTS.md](../AGENTS.md), [design guardrails](DESIGN_GUARDRAILS.md), [product requirements](PRODUCT_REQUIREMENTS.md), [architecture](ARCHITECTURE.md), [database design](DATABASE_DESIGN.md), [UI specification](UI_UX_SPEC.md), [roadmap](ROADMAP.md), and accepted ADRs in their documented order. Verify implementation against [design verification](DESIGN_VERIFICATION.md).

Phase 3 delivers owner-only daily recording for boolean, count, duration, checkpoint, and avoidance habits; completed/partial/missed outcomes where meaningful; explicit N/A with an optional reason; and private habit notes. Raw entries remain authoritative (DG-004, DG-010/011, DG-013, DG-021–026).

Repository findings:

- Phase 2 supplies configuration, categories, authentication, owner policies, and reversible lifecycle actions. There are no entry models, migrations, tracking routes, timezone preferences, or daily outcome rules yet. The dashboard is a navigation entry point.
- `config/app.php` uses UTC, and habit creation currently defaults its start date with application `today()`. Daily boundaries must instead use the owner's selected timezone while timestamps remain UTC.
- `SaveHabit` replaces configuration and physically deletes removed checkpoints. Directly attaching daily completions to those mutable rows would lose or reinterpret history. Tracking configuration needs dated versions before entries ship.
- The lifecycle component stores only current `archived_at`/`deleted_at`; clearing those fields loses previous inactive periods. Explicit date intervals are needed so restored or unarchived habits do not acquire false missed days.
- `tests/Feature/Habits/MigrationTest.php` rolls back the newest four migrations and assumes they are Phase 2. Adding migrations breaks that assumption; isolate phase migration sets in the test.
- The Phase 2 closeout records 151 tests / 702 assertions, Pint, build, and 42 browser checks. These are historical baseline results, not Phase 3 acceptance evidence.

**Unresolved source conflict:** the product describes avoidance's goal as an acceptable maximum, while [heatmap scoring](HEATMAP_SCORING.md) assigns zero performance at that maximum. Optional goals and a maximum of zero also lack usable formulas. Stop formula work at this boundary: this plan does not select or change a scoring formula. Resolve the conflict explicitly through an ADR before Phase 4. Phase 3 stores raw measurements and explicit outcomes without turning them into normalized scores.

## Scope and decisions

The user confirmed both planning choices on 2026-09-27:

1. A user-selectable timezone, defaulting to UTC until changed.
2. Preserve historical tracking setup while allowing future configuration changes.

The concrete semantics below were adopted during the authorized implementation and recorded in [ADR-0004](adr/ADR-0004-DAILY-TRACKING-HISTORY.md). The two user choices above remain the confirmed product preferences. Resolve any newly discovered source conflict before changing the affected behavior.

Included: daily date navigation; create/update a day's entry; all five tracking types; notes and N/A; historical configuration; lifecycle eligibility; timezone settings; server-side ownership; migrations, focused tests, accessible UI, and documentation.

Excluded: heatmaps, scores, streaks, consistency calculations, journal/mood, followers or sharing, reminders, timers, bulk logging, APIs, audit/event-sourcing infrastructure, queues, caches, new themes, and dependency installation. No entry deletion/reset-to-unlogged feature is included; users can correct an entry or mark it N/A. Permanent habit deletion and automatic trash expiry remain deferred by the user's Phase 2 decision. Recovery remains available beyond 30 days until that separately planned follow-up exists.

## Date and eligibility contract

- Add a validated IANA timezone preference to profile settings, default `UTC`. Show the timezone beside daily date navigation. Do not infer a stored preference from the developer machine, IP address, or browser. Keep application/database timestamps in UTC.
- A day is a calendar `Y-m-d` in the owner's timezone. Persist `entry_date` as DATE, without converting midnight to UTC. Derive today on the server using the fresh owner preference; do not trust a client clock or submitted timezone.
- Changing timezone changes future requests' definition of today; it never moves existing entry dates, configuration effective dates, or stored lifecycle interval dates. Repeated local dates after travel still address the same unique habit/date entry. A stale form crossing midnight or a timezone change must be revalidated and may require reload.
- Permit today and backdated recording within the effective configuration's inclusive start/end range and outside suspension intervals. Reject future dates, malformed/nonexistent dates, and dates outside 1000-01-01 through 9999-12-31. No arbitrary 30-day backfill cutoff.
- An absent entry is **Not logged**, not an automatically inserted missed record. N/A is an explicit stored entry; out-of-range, suspended, and future dates are neutral/ineligible without fabricated N/A records. Phase 4 will define how eligible but unlogged dates contribute to analytics.
- Normal tracking reads exclude trashed habits. Archived habits retain readable history and permit corrections/backfill for their eligible past dates, including the archive date; archive disables later dates. Ended habits likewise permit eligible historical edits.
- Changing dates must not hide or reinterpret recorded history. Use the dated configuration described below rather than evaluating every old entry against today's mutable habit row.

## Recording and validation

Use a string-backed `HabitEntryStatus` enum with `completed`, `partial`, `missed`, and `not_applicable`; no stored status for an absent row. Explicit save is required. Visiting a date or opening a form must not create records.

| Type | Daily input and outcome |
| --- | --- |
| Boolean | Completed or Missed, plus the common N/A option. Store nullable numeric value as null. Partial is invalid for a binary habit. |
| Count | Nonnegative whole-number actual count, including zero, and an explicit Completed / Partial / Missed assessment. Display the historical optional goal and unit as context. |
| Duration | Nonnegative whole-minute actual value, including zero, and an explicit Completed / Partial / Missed assessment. Always label and store minutes; no timer or implicit hours conversion. |
| Checkpoint | Checkboxes from that date's configuration version. All checked means Completed, some means Partial, none means Missed; derive this status on the server. Store every eligible checkpoint, including unchecked ones. |
| Avoidance | Nonnegative whole-number occurrences, including zero, and an explicit Completed / Partial / Missed assessment. Display the historical optional maximum/unit as context. Zero unwanted behavior must be recordable, including with a zero maximum or no maximum. |

For numeric types, status is the owner's assessment, not a calculated goal result. Label it accordingly; never silently alter the actual value to match it. This supports partial results and optional goals without importing the unresolved heatmap formula. Later analytics must use raw values and the historical configuration under its accepted scoring contract; it must not assume the assessment is a normalized score.

Proposed numeric range: 0–999,999,999, consistent with current whole-number configuration limits. Reject negative, fractional, malformed, boolean, array, or oversized numeric payloads. Blank is not zero. Goals stay optional; do not invent a denominator or require a goal to record activity.

Private notes are optional plain text, maximum 2,000 characters; N/A reasons are optional plain text, maximum 500. Trim optional strings, map whitespace-only to null, preserve literal `"0"`, and escape output. A reason is applicable only to N/A. Do not include notes/reasons in activity summaries, logs, or future public projections.

N/A overrides outcome eligibility; it is not a failure or an unlogged date. A new N/A entry does not require a value or completed checkpoints. When changing an existing entry to N/A, retain its previous measurement/checkpoint data and note; exclude them from performance while N/A is active. Explain that behavior in the form. Switching back prefills retained values but requires validation and explicit save; clear the now-inapplicable reason. A new N/A checkpoint entry still snapshots its eligible checklist with all items unchecked. Never fill a missing numeric measurement with zero during these transitions.

Updates correct the existing daily record; they do not append another entry for the same habit/date. Do not expose mass-assignment of owners, foreign parents, configuration versions, snapshot labels, completion timestamps, or lifecycle state. Derive those fields on the server. Reject unknown/duplicate checkpoint IDs and IDs from another habit or another version; omitted eligible checkboxes become unchecked only in a validated full-form submission.

## Preserve configuration history

Introduce narrow, effective-dated tracking configuration versions, not a generic revision framework. A version stores type, goal value/unit, start/end dates, and ordered checkpoint snapshots. Name, description, category, and visibility remain ordinary current metadata; Phase 3 does not promise historical names or categories.

- Create a baseline version for each existing habit using its current configuration, effective from its start date. Previously edited Phase 2 configurations cannot be reconstructed; there are no daily records from that phase to migrate. New habits receive their baseline in the creation transaction.
- Before any entry exists, configuration edits can replace the baseline and its checkpoint snapshots, preserving Phase 2's edit behavior. Recheck entry existence under the same habit lock used by recording; an N/A entry counts as history. Increment the version's revision on replacement so a previously opened daily form cannot save against changed units/type/checkpoints unnoticed.
- Once any entry exists, tracking configuration edits take effect **tomorrow in the owner's timezone**. The current day's form remains on the current version. Show current and pending configuration with the effective date on detail/edit pages; the mutable habit fields represent the latest requested configuration, so daily forms must resolve a version, not read those fields directly.
- Resolve a date to the latest version whose effective date is on/before it, then apply that version's start/end dates. No qualifying version means out of range. A new entry backfilled for an older date uses that date's version, even if its type/checkpoints differ from today's setup.
- Permit one pending next-day version; repeated edits before it becomes effective replace that pending version transactionally. After tracking begins, effective versions are immutable. If a timezone change makes an existing pending effective date differ from the newly computed tomorrow, offer keeping the pending change or cancelling it before submitting a replacement; do not silently move it or accumulate conflicting pending versions. Cancellation may remove only a not-yet-effective, unreferenced pending version and must restore the editable configuration from the current effective version in the same transaction.
- Each entry points to its historical version; checkpoint completions point to immutable checkpoint snapshots. Renaming/reordering/removing a current checkpoint or switching habit type must not delete historical labels, order, completion rows, or measurements.
- Checkpoint snapshots may retain a nullable FK to their original configuration row for provenance. Removing a configuration row may null that link; it must never cascade into snapshots or daily activity. Historical forms render snapshot labels and order.
- Date edits also take effect tomorrow after tracking begins, preserving past eligibility and entries. This permits extending or shortening future tracking without retroactively manufacturing missed days or erasing past activity. Validate the proposed start/end pair and explain when it becomes effective.

Example: a count habit has a goal of 10 on September 27. The owner logs 6, then changes the goal to 20. September 27 retains goal 10, September 28 uses 20, and later corrections to September 27 still use 10. The same boundary applies to type changes and checkpoint edits.

## Preserve archive and trash gaps

Add date-based suspension intervals with separate archive/trash reasons. Current lifecycle timestamps still drive Phase 2's lists and state; intervals preserve eligibility across repeated transitions.

- Archive/trash begins suspending dates from the **next local date**, preserving the transition day's earlier work. Unarchive/restore ends the corresponding interval at the **current local date**, making the resumption date eligible subject to all other restrictions. While trashed, no tracking reads or writes are available at all.
- Store intervals as inclusive start / exclusive end; null end means ongoing. Overlapping archive and trash intervals are allowed: a date is suspended if either covers it. Restoring an archived habit closes only trash suspension, not archive suspension.
- Same-day suspend/resume produces an empty interval; close with `end = max(start, resume_date)` so timezone changes cannot create a negative interval. Empty intervals exclude no dates. Repeated/invalid lifecycle requests do not append duplicate intervals.
- Resolve boundaries with the owner's timezone at the transition and persist those dates. Do not recalculate them later from UTC timestamps after a timezone preference changes.
- Backfill an open interval for currently archived/trashed habits from the known timestamp in the migration default timezone, UTC. Completed pre-Phase-3 episodes are unknowable; do not invent them or claim complete lifecycle history before rollout.
- Entry saving, configuration editing, and lifecycle transitions acquire the same parent habit lock. An intervening trash/archive/configuration change must be rechecked under that lock. Already-recorded activity survives every transition, including repeated recovery after 30 days.

Example: archive on September 27, unarchive September 30. September 27 remains eligible, September 28–29 are neutral, and September 30 resumes tracking. Trash and restore follow the same date rule while access remains denied during trash.

## Database changes and migration safety

Add new migrations; never rewrite applied Phase 1/2 migrations. The outline below refines the previously planned entry tables to satisfy the user's historical-setup decision. Publish the exact schema in `DATABASE_DESIGN.md` when implemented.

| Table/change | Planned fields and integrity |
| --- | --- |
| `users.timezone` | Non-null string (64), default `UTC`; validate against supported IANA identifiers. Existing users get UTC. UTC timestamps remain unchanged. |
| `habit_tracking_versions` | ID, habit FK, effective DATE, type string (20), nullable unsigned integer goal value, nullable unit string (30), start/end DATE, unsigned revision default 1, timestamps; unique `(habit_id, effective_date)`. Parent habit FK restricts physical deletion until an explicit deletion workflow removes dependent history. |
| `habit_tracking_checkpoints` | ID, tracking-version FK, nullable source `habit_checkpoint_id` FK, label (80), unsigned sort order; unique `(habit_tracking_version_id, sort_order)`. Version deletion cascades its snapshots; source deletion sets only its provenance FK null. Snapshots referenced by activity cannot be independently removed. |
| `habit_entries` | ID, habit FK, user FK, tracking-version FK, entry DATE, status string (20), nullable unsigned integer value, nullable private note TEXT, nullable N/A reason (500), unsigned integer revision default 1, timestamps; unique `(habit_id, entry_date)`, index `(user_id, entry_date, habit_id)` for the daily list. User/habit/version deletion is restricted while referenced. |
| `habit_checkpoint_entries` | ID, entry FK, immutable `habit_tracking_checkpoint_id` FK, boolean `is_completed` default false, nullable UTC `completed_at`, timestamps; unique `(habit_entry_id, habit_tracking_checkpoint_id)`. This replaces the original outline's direct dependency on a mutable checkpoint with a snapshot FK. Entry deletion cascades children; snapshot deletion is restricted while referenced. |
| `habit_suspensions` | ID, habit FK with restricted physical deletion, reason string (`archive`/`trash`), start DATE, nullable exclusive end DATE, timestamps; index `(habit_id, reason, end_date, start_date)`. Check end is null or on/after start. At most one open interval per habit/reason enforced under the parent lock and tested. |

Use foreign keys with deliberate deletion order, enum casts, integer/boolean casts, and immutable date/datetime casts. FK existence alone does not prove that an entry owner, version, and checkpoint all belong together: derive these relationships through the locked parent, validate them again in the action, and test forged combinations. Add composite integrity constraints where practical after checking actual MySQL key requirements; do not claim ordinary independent FKs enforce same-habit membership.

Seed baseline versions/checkpoint snapshots and known open suspensions without modifying existing habit dates, archive state, categories, profiles, or checkpoint IDs. Make backfill restart-safe using deterministic parent/effective identities. MySQL schema changes and data backfill cannot be assumed to share one rollback transaction; separate their steps and document recovery from interrupted deployment. Do not create daily entries, N/A records, scores, or sample private notes during migration/seeding.

Rollback in reverse dependency order. Dropping Phase 3 tables intentionally removes Phase 3 activity, so prove rollback only on `habit_log_test`; a real deployment rollback needs data backup and a preservation plan. Refactor the old Phase 2 migration test to apply/rollback its explicit migration set, then add a Phase 3 upgrade/rollback/reapply test with existing Phase 2 fixtures. Verify both configured and connected database names before destructive test commands.

## Authorization, concurrency, and application boundaries

- Put all tracking routes behind existing `auth` middleware. Add `HabitEntryPolicy` and a habit-level recording ability for the date; owner-only access applies for every visibility and account privacy preference. Phase 6 sharing remains absent.
- Resolve habit, date, entry, configuration, and checkpoint IDs through authorized relationships before returning data. Missing and foreign route identifiers return the same not-found response. Authorize every Livewire read/mutation again; locked component properties are not authorization.
- `SaveHabitEntry` is a justified Action: within a transaction, lock the owner's current habit, recheck lifecycle/date/configuration, load the unique daily row, validate the revision, write parent and checkpoint children atomically, and increment the revision. All recording/configuration/lifecycle actions take a shared user lock, then the exclusive habit lock. This keeps timezone stable during the transaction and avoids establishing a stale MySQL read snapshot before waiting for the habit.
- Use an expected revision for updates and an explicit expected-absent state for first saves. Two first saves serialize; the second sees an existing row and returns a reload/conflict message instead of silently overwriting it. A stale update changes nothing. The unique date constraint is the final duplicate defense; handle its violation as a conflict, not an exception page.
- Also compare the form's expected configuration version/revision with the server-resolved version under the lock. These are conflict tokens, never trusted parent assignments. A baseline edit or a midnight boundary must not turn an old count form into a duration submission. Revalidate timezone-sensitive dates against the fresh user preference before writing.
- For a newly completed checkpoint set `completed_at` from server UTC; retain it on unchanged completed rows, clear it when explicitly unchecked, and use a new timestamp when checked again. A backfilled completion timestamp is the recording time, not an invented historical completion instant. N/A toggling alone preserves it.
- A shared date/configuration eligibility service is justified because list, editor, policy, and transactional action require identical rules. Keep validation in a Livewire form and complex writes in Actions; do not create a repository abstraction or analytics service.
- Eager-load selected-date entries/version checkpoints for each paginated set of habits. Scope ownership before filtering or pagination. Avoid loading every user's history or issuing a query per checkbox.
- Preserve session/CSRF middleware and output escaping. Never log notes, reasons, or submitted credentials. No public serialization or sharing endpoint is introduced.

## UI and likely files

Use `/tracking?date=YYYY-MM-DD` for a paginated daily overview and `/habits/{habit}/entries/{date}` for a single daily editor; validate route dates on the server. Add a Today/dashboard entry point and desktop/mobile Tracking navigation. Retain habit management and profile navigation. The detail page links to recording and a paginated owner-only history list, including archived history.

Daily navigation uses Previous day, Today, Next day, and a native date input; Next day is disabled at today. Show selected date, timezone, current outcome/Not logged, and why a habit/date is unavailable. Numeric forms show unit and the effective goal/maximum; checkpoint forms show historical labels. Clearly label notes and N/A reasons private. Separate configuration editing from activity recording and explain pending next-day changes.

Use normal buttons, selects, radios, checkboxes, and textareas with field errors, visible keyboard focus, loading/disabled-save state, and announced success/conflict feedback. Protect dirty input when changing date or leaving the editor. Dates/statuses need text cues, not color alone. Check narrow widths and reduced motion. Existing browser tooling is sufficient; installing browser libraries is not part of this plan.

| Area | Likely files |
| --- | --- |
| Schema/fixtures | New migrations for timezone and the five tables above; baseline backfill; test fixtures constructed through the real transactional actions so they obey version/owner invariants. Keep the existing local test-account seeder behavior. |
| Models/enums | `User`, `Habit`, `HabitCheckpoint` relationships; new `HabitEntry`, `HabitCheckpointEntry`, `HabitTrackingVersion`, `HabitTrackingCheckpoint`, `HabitSuspension`; status/reason backed enums. |
| Validation/actions | New `app/Livewire/Forms/HabitEntryForm.php`, `app/Actions/Habits/SaveHabitEntry.php`, shared eligibility/version resolution; update `HabitForm`/`SaveHabit`. Extract lifecycle writes from the list component into an Action now that they also maintain intervals. |
| Authorization | New `app/Policies/HabitEntryPolicy.php`; extend `HabitPolicy` with date-aware recording access using the shared rules. |
| Settings/UI/routes | `resources/views/pages/settings/⚡profile.blade.php`, `resources/views/pages/habits/⚡edit.blade.php`, `⚡show.blade.php`, `⚡index.blade.php`, new tracking/entry pages, `resources/views/pages/⚡dashboard.blade.php`, `resources/views/layouts/app.blade.php`, and `routes/web.php`. Update new-habit default date to the owner timezone. |
| Tests | `tests/Feature/Tracking/` for recording/privacy/history/schema/migrations/concurrency; existing Habits/Profile/Dashboard suites; unit tests only for isolated date/configuration rules. |

## Implementation sequence and acceptance gates

1. **Date/history contract and schema.** Record an ADR for effective dates, suspensions, and raw outcome semantics; update planned schema details. Implement timezone, snapshots, baseline upgrade, models/test fixtures, and migration test isolation. Gate: existing data survives upgrade; scoped rollback/reapply passes; timezone validation and date-boundary tests pass.
2. **Protect configuration and lifecycle.** Make edits version-aware and transitions interval-aware with shared parent locks. Gate: previous configuration and activity cannot be removed/reinterpreted; next-day changes, same-day repeats, archived history, overlapping archive/trash gaps, and recovery all behave as specified.
3. **Record entries.** Implement validation, policy, eligibility resolution, and transactional saves for all five types, N/A, and notes. Gate: unique habit/date, historical backfill, invalid-input atomicity, ownership, stale revisions, and rollback after a child-write failure pass through actual actions/routes.
4. **Daily interface.** Add date navigation, overview/editor/history, dashboard/navigation integration, timezone controls, effective-configuration messaging, and accessible feedback. Gate: each type saves/reloads on desktop/mobile; date changes cannot silently discard unsaved work; errors and conflict recovery are usable.
5. **Verification and documentation.** Run focused suites, full suite/Pint/build, browser checks, and the complete design-verification review. Record exact evidence in a new Phase 3 closeout. Mark the roadmap complete only after all included gates pass.

Keep tests with their behavior changes. Do not implement Phase 4 to close Phase 3. No commit or push is authorized by this planning request.

## Required test matrix

| Requirement | Evidence needed |
| --- | --- |
| Types and validation | Round-trip all five types; all meaningful statuses; boolean Partial rejection; numeric blank versus zero, fractions/negative/limit/oversized/malformed; optional goals and zero avoidance maximum; historical minutes/units; 1/20 checkpoints and invalid/duplicate/foreign/version-mismatched IDs; server-derived checkpoint status. |
| N/A and private text | New N/A without measurements; transitions into/out of N/A preserve prior measurements/checkpoints/notes and clear obsolete reasons; no invented zero; note/reason bounds, trim/null, literal zero, escaped markup; no note/reason in overview projections or logs. |
| Dates/timezones | UTC default/settings validation; Asia/Manila and UTC date split; a DST zone's spring/fall transitions; leap day, equal start/end, future/out-of-range dates, midnight stale forms; timezone changes do not relocate history; selected-date isolation and unlogged versus explicit missed/N/A. |
| Historical configuration | Baseline from existing Phase 2 data; edits before first entry; N/A starts history; next-day goal/unit/type/checkpoint/date changes; pending replacement/cancellation; timezone change with a pending version; historical backfill after a type switch; rename/reorder/removal preserves old labels, order, values, and completion timestamps. |
| Lifecycle eligibility | Archive day and gap/resumption boundaries; corrections to archived/ended habits; trash denies all reads/writes; active/archived restoration; overlapping reasons; repeat transitions, empty intervals, timezone changes, and more-than-30-day recovery without lost activity. |
| Privacy and authentication | Guest routes and actual Livewire calls; post-logout update rejection; two-user access attempts for private/followers/public preferences; forged owner/habit/entry/version/checkpoint IDs; list/history pagination isolation; rejected actions leave foreign and own data unchanged. |
| Atomicity and concurrency | Failure after parent save rolls back all child writes; stale entry/configuration revisions and simultaneous first saves; competing baseline edit/archive/trash versus entry save using the shared lock; duplicate constraint; retry/reload behavior. Include a controlled two-connection integration check for locking, rather than claiming sequential calls prove concurrent execution. |
| Schema and upgrade | FK/unique/revision/default/date constraints; source-checkpoint removal preserves snapshots; unsafe snapshot deletion denied; Phase 2 migration test still isolated; upgrade with populated configuration, interrupted/repeated backfill, rollback preserving Phase 2 records, and reapply on the test DB only. |
| UI and regressions | Navigation/auth/profile/category/configuration/lifecycle tests remain green; actual browser save/reload for all types, past-date editing, timezone/N/A/private note flow, historical checkpoint rendering, stale conflict feedback, keyboard/dirty-form behavior, mobile overflow and reduced motion. |

Use existing PHPUnit, real MySQL for constraints/locking, and actual Livewire/HTTP paths for authorization. Do not let factories silently bypass the version/owner invariants under test. Run database-resetting tests serially and separately from browser/concurrency fixtures.

## Verification commands and documentation

During implementation, run focused suites as they are added. Final gate from the repository root:

```bash
docker compose up -d --wait
php artisan test tests/Feature/Tracking --compact
php artisan test tests/Feature/Habits tests/Feature/Settings tests/Feature/DashboardTest.php --compact
composer ci:check
npm run build
git diff --check
```

`composer ci:check` includes Pint and the full PHPUnit suite. Before any reset/rollback, require both configured and connected database names to equal `habit_log_test`; never reset development data. Record the actual Phase 3 test counts and migration/concurrency outcomes. Do not treat an old closeout or a proposed command as evidence of a new pass.

Browser acceptance uses an installed browser and normal session/CSRF middleware on a test-only database. Check a request lacking both token and trusted same-origin header separately from PHPUnit's CSRF bypass. Record browser/version, desktop and 375px/320px viewports, keyboard focus, reduced-motion results, JavaScript errors, and screenshots. Stop temporary servers/browsers afterward. Hosted CI can be observed only following separately authorized publication.

Update `DATABASE_DESIGN.md`, `UI_UX_SPEC.md`, `TESTING_STRATEGY.md`, `PROJECT_COMMANDS.md`, `PROJECT_STRUCTURE.md`, README, roadmap, and the date/history ADR as their implementation lands. Create `PHASE_3_CLOSEOUT.md` after verification. Change architecture/security docs only where implemented boundaries need explanation; retain accepted privacy and heatmap invariants.

Map final evidence to every applicable item in `DESIGN_VERIFICATION.md`: requirements/scope, server-side privacy, raw history, foreign keys/indexes/transactions/rollback, validation and concurrency, mobile/keyboard interaction, and accurate docs. Journals, mood, sharing, analytics, and permanent deletion remain unimplemented; do not claim their acceptance tests passed.

## Risks and handoffs

- **History cost:** configuration versions and suspension intervals are required by current daily history, not speculative analytics caching. Keep their behavior narrow and test the upgrade from populated Phase 2 data. Pre-rollout configurations and completed inactive periods cannot be recovered.
- **Outcome/scoring boundary:** numeric assessments can differ from normalized performance. Resolve the documented avoidance conflict, missing-goal/zero-maximum formulas, and unlogged-day treatment before Phase 4; never rewrite raw entries to fit a formula.
- **Timezone changes:** date identities remain stable even when travel repeats/skips a local day. Existing effective dates remain fixed; pending configuration needs visible conflict handling. Do not silently rebase old records.
- **Retention:** permanent deletion later must remove entries, checkpoint completions, version snapshots, suspensions, and eventual caches in a deliberate order. Restricted historical FKs are intentional; no destructive cleanup is added here.
- **Privacy rollout:** stored public/followers preferences do not grant access in Phase 3. Phase 6 needs separate permitted activity projections that exclude private notes and N/A reasons.
- **Planning verification:** the original planning change replaced the Phase 2 plan and updated links only. The user subsequently authorized Phase 3 implementation. Its final verification evidence belongs in the Phase 3 closeout; the command list above is not evidence of a pass.

Suggested planning commit message, only if requested: `docs: replace Phase 2 plan with Phase 3 daily tracking plan`.
