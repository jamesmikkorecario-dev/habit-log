# Phase 5 implementation plan — Private daily journal

Status: implemented and verified; see [the closeout](PHASE_5_CLOSEOUT.md). The user authorized Phase 5 on 2026-10-04 and subsequently requested its commit. Prepared 2026-10-04 against Phase 4 commit `77650fe` and an initially clean worktree. This document replaces the retired Phase 4 plan at the user's request. The [Phase 4 closeout](PHASE_4_CLOSEOUT.md), [scoring contract](HEATMAP_SCORING.md), and [ADR-0005](adr/ADR-0005-ANALYTICS-SCORING.md) retain its delivered behavior and evidence. Deployment and push are not requested.

## Relevant requirements and findings

Follow [AGENTS.md](../AGENTS.md) and its source precedence: [design guardrails](DESIGN_GUARDRAILS.md), [product requirements](PRODUCT_REQUIREMENTS.md), [architecture](ARCHITECTURE.md), [database design](DATABASE_DESIGN.md), [UI specification](UI_UX_SPEC.md), [roadmap](ROADMAP.md), and accepted ADRs. Also apply [security/privacy](SECURITY_AND_PRIVACY.md), [testing strategy](TESTING_STRATEGY.md), [AI workflow](AI_WORKFLOW.md), and [design verification](DESIGN_VERIFICATION.md).

Phase 5 requires one private journal per user/calendar date, journal text, optional private mood from 1–5, and date navigation. Relevant guardrails include DG-002, DG-004, DG-017, and DG-021–026. Journals remain private regardless of account or habit visibility.

Repository findings:

- Phases 0–4 are complete for their current scope. The database document outlines `journals`, but there is no journal migration, model, policy, route, or editor yet.
- Existing session authentication, policies, Livewire forms, accessible dialogs, dirty-form protection, and owner timezone settings provide the needed foundation. No new package or browser library is required.
- `User::localToday()` and [ADR-0004](adr/ADR-0004-DAILY-TRACKING-HISTORY.md) establish owner-local calendar dates and UTC timestamps. Journals can follow those date semantics without habit configuration versions or eligibility rules.
- `tests/Feature/Tracking/MigrationTest.php` applies all pending migrations, then rolls back the latest two assuming they are Phase 3. A new migration would invalidate that assumption. Isolate its Phase 0–3 migration paths before introducing the journal migration, following the existing Phase 2 migration-test pattern.
- Phase 4's recorded 243 tests / 1,798 assertions and browser checks are historical evidence, not Phase 5 acceptance results.

No conflicting journal requirements were found. The user has resolved the two missing product choices below. Other detailed limits and interaction rules were implementation proposals adopted for this phase, not separate user-confirmed product answers.

## Confirmed decisions and scope

The user confirmed on 2026-10-04:

1. Journal text is required; mood remains optional. Mood-only entries are rejected.
2. Include confirmed deletion of that day's journal and mood.

Included: create, read, edit, explicitly confirmed deletion, optional mood, date navigation, ownership enforcement, validation, stale-write protection, migration, focused/full verification, and documentation.

Excluded: journal sharing, followers, mood analytics, journal streaks, changes to habit scoring, search, tags, titles, attachments, rich text/Markdown rendering, autosave, export, reminders, retention jobs, and a journal trash/recovery system. Habit permanent deletion and automatic trash expiry remain deferred. Journal deletion is the specific user-authorized destructive operation; it must not delete or rewrite habit activity.

## Calendar and content contract

- `/journal` opens the authenticated user's local today. Use `/journal?date=YYYY-MM-DD` for a selected date, matching the existing tracking overview's navigation pattern. No owner ID is accepted from the client.
- Validate exact real calendar dates server-side on initial requests and every action. Support MySQL DATE's application range, `1000-01-01` through `9999-12-31`; reject invalid, impossible, or out-of-range dates. Prevent navigation arithmetic from underflowing/overflowing. Invalid query dates receive a validation/HTTP error without creating rows; invalid date-form input preserves the current editor and draft.
- Permit today and past-date creation/editing, including dates before account creation. Journaling has no habit-start, archive, trash, or category dependency and works for an owner with no habits. Missing days have no record and no implied mood or failure.
- Default controls stop at local today. A previously stored date that becomes future-local after a timezone change remains readable by direct date navigation, but creation, editing, and deletion are disabled until that date is current/past again. Never move stored dates when the timezone changes.
- Capture a fresh persisted timezone and local today for each request; recheck under the owner lock before mutation. Reject writes from an editor loaded under a different timezone with reload guidance. Refresh/navigation updates today; a live midnight timer is unnecessary.
- Store the journal date as an immutable calendar date, without UTC conversion. Keep created/updated timestamps in UTC. Editing cannot move an entry to a different date or owner.
- Use a plain-text textarea, maximum 10,000 characters. Trim surrounding whitespace, reject blank/whitespace-only content, preserve interior newlines and literal `"0"`, and render escaped text. Boundary tests include multibyte text. No HTML execution or Markdown interpretation.
- Mood is nullable or a validated integer 1–5. Offer labeled ordinary controls with a clear unset choice; labels are Very low, Low, Okay, Good, Very good. Clearing mood must persist null while retaining content. Reject zero, fractions, out-of-range values, and nonnumeric input. Mood is an optional personal assessment, not a clinical interpretation or performance score.

## Database and concurrency

Add one new additive migration; do not rewrite earlier migrations or backfill journals from habit notes.

| Field/constraint | Storage and purpose |
| --- | --- |
| `id` | Standard primary key; also distinguishes a deleted entry from a later replacement. |
| `user_id` | Required user foreign key with restricted parent deletion; explicit journal deletion remains possible. |
| `journal_date` | Required DATE with immutable date cast. |
| `content` | Required TEXT, bounded to 10,000 characters by application validation. |
| `mood` | Nullable unsigned tiny integer, integer cast, database check for null or 1–5. |
| `revision` | Unsigned integer, default 1; increment after each successful edit. |
| timestamps | Standard created/updated timestamps. |
| unique `(user_id, journal_date)` | Guarantees one journal per owner/date and supports the exact owner/date lookup. |

The revision extends the existing planned table outline to protect simultaneous editors. No `deleted_at`, score, visibility, history-version, cache, or mood-summary table is needed. Add `User::journals()` and `Journal::user()`. Keep content and mood hidden from general model serialization; explicitly load them only for the authorized editor.

Use focused transactional save/delete Actions because authorization, timezone checks, and concurrent mutations need a single tested boundary. Validate again inside the Action for non-UI callers. Set ownership from the authenticated server-side user; never accept mass-assigned ownership or revision values.

Acquire an exclusive owner-row lock first, then lock the owner/date journal row, using the same owner-first ordering as existing habit writes. The owner lock serializes concurrent creation when no journal row exists and stabilizes timezone during the write. Keep transactions short and acquire no habit locks. The unique database key remains a final integrity guarantee.

Compare the editor's expected entry ID and revision with the locked current row; a new editor expects no row. Reject competing creates, edits, deletes, and delete/recreate races instead of silently overwriting or resurrecting data. Revision alone is insufficient when a deleted journal is recreated with revision 1. Tokens detect stale state; they never replace ownership authorization. Preserve the draft on conflict and offer an explicit reload/discard choice. Do not expose another user's data in errors.

Deletion removes the one selected journal row, including its mood, after explicit confirmation and the same ownership/date/timezone/stale-state checks. There is no application undo. Cancellation changes nothing. Deleting must not remove habit entries, notes, or profile data. A new journal may later be created for the same date; stale earlier editors must still be rejected.

The migration's `down()` drops only `journals`. Verify populated upgrade and rollback/reapply exclusively on guarded `habit_log_test`; check both configured and connected database names. Existing profiles, habits, entries, versions, and suspensions must survive unchanged. Production rollback would destroy journal data: preserve/export it first, and prefer an application rollback that leaves the additive table intact. Do not use `migrate:fresh` on development data.

## Authorization and privacy

- Add `JournalPolicy` with authenticated creation and owner-only view/update/delete. Query through the authenticated owner's relationship and authorize every read/action, including Livewire updates after logout or a session-account change.
- Lock the loaded date, entry ID, expected revision, and timezone in component state; validate the editable navigation input separately. Recheck all trusted state at the server boundary. Tampered IDs, ownership fields, or dates cannot access a foreign record.
- Keep the existing auth, CSRF, session, validation, and escaping protections. Use consistent denied/not-found responses that do not disclose foreign journals or their existence.
- Account public/private preferences and habit visibility never grant journal or mood access. No public/profile route, summary preview, analytics projection, or sharing control may expose them.
- Do not put content/mood in URLs, logs, exception context, browser storage, or shared caches. Authorized editor HTML/Livewire state necessarily includes the owner's fields; general model serialization and unrelated page payloads must omit them. Privacy tests inspect both rendered output and Livewire state, including another logged-in user's requests.
- Journals and mood do not enter heatmap, streak, or consistency calculations. Saving/deleting a journal must leave raw habit records and computed habit/profile results unchanged.

## UI behavior

Add Journal to desktop/mobile navigation and a simple dashboard link without private previews. Build one date-based Livewire editor with Previous day, Today, Next day, a native date input and explicit Go action, visible selected date/timezone, textarea, optional mood, and explicit Save. Reuse the current terminal theme and standard Flux/Blade controls.

Distinguish an empty date from a saved journal without inserting rows on reads. Announce save/delete feedback and field errors. Save content and mood atomically; invalid input changes neither. Disable duplicate submissions while requests are pending. After a successful save, refresh identity/revision and clear dirty state. After deletion, remain on the same date with an empty editor.

Protect unsaved text/mood during date changes, Today/Previous/Next actions, app navigation, browser back/forward, reload, and unload where the browser permits. Cancelling navigation preserves both the selected date and draft. A failed save or conflict must not clear dirty state. Reloading a conflict explicitly warns that the draft will be discarded. Avoid autosaving or copying drafts into local storage.

The deletion dialog names the date, explains permanent removal of its text and mood, and provides Cancel and Delete controls. Escape cancels; focus returns to the trigger. If deletion succeeds and removes that trigger, move focus to a sensible editor heading/control. Confirmation is separate from any dirty-draft discard decision.

Verify labels, keyboard navigation, visible focus, announced statuses/errors, reduced motion, comfortable touch targets, and escaped multiline text. Check 320px/375px widths and the 768px navigation breakpoint: adding another desktop link must not overflow. This phase does not require a calendar widget or a separate paginated history list; date navigation provides access to previous journals.

## Files likely to change

| Area | Files |
| --- | --- |
| Schema/models | New journal migration and `database/factories/JournalFactory.php`; `app/Models/Journal.php`; `app/Models/User.php`. No sample private journals in the login seeder. |
| Authorization/write boundary | `app/Policies/JournalPolicy.php`, `app/Livewire/Forms/JournalForm.php`, `app/Actions/Journals/SaveJournal.php`, `app/Actions/Journals/DeleteJournal.php`. Reuse existing conflict conventions where suitable. |
| Routes/UI | `routes/web.php`, `resources/views/pages/journal/⚡edit.blade.php`, `resources/views/layouts/app.blade.php`, the existing dashboard page, and `resources/js/app.js` for Back/Forward cancellation. |
| Tests | New `tests/Feature/Journals` coverage; update `tests/Feature/Tracking/MigrationTest.php`; focused existing navigation/privacy regressions as needed. |
| Documentation | Product/schema/architecture/UI/privacy/testing/commands/structure/roadmap/README and a Phase 5 closeout after verification. |

Use names consistent with nearby code at implementation time. Keep journal validation independent of habit-specific `TrackingDate` eligibility/version logic; do not build a general calendar or concurrency framework for this feature.

## Implementation steps

1. Recheck the worktree and current sources. Confirm this plan's scope before implementation; do not treat proposed limits as already shipped behavior.
2. Isolate the Phase 3 migration test, add the journal schema/model/factory, and verify constraints plus a populated upgrade without changing existing activity.
3. Implement owner policy and transactional save/delete boundaries with validation, timezone, identity/revision conflict checks, and focused tests.
4. Build the date editor and navigation, including optional mood, empty/read-only states, feedback, dirty-draft handling, and confirmed deletion.
5. Add HTTP/Livewire privacy and regression coverage; run the serial MySQL suite, formatting, and production build, then installed-browser acceptance.
6. Review against design verification, update durable documentation, and write a closeout with observed results and limitations. Mark Phase 5 complete only when acceptance checks pass. Commit/push only if separately requested.

## Test plan

- **Behavior/validation:** create and reload, edit both fields, clear mood, delete/cancel/recreate, required text, whitespace and literal zero, 10,000/10,001 characters, multibyte/newlines, escaped script-like text, all moods and invalid boundaries. Failed writes preserve all stored values; GET never creates a row.
- **Dates:** local midnight in positive/negative offsets, DST, leap day, exact date format, MySQL lower/upper limits, future writes, historical backfill before account creation, no-habit owners, timezone changes without date relocation, and future-local retained rows. Guard Next/Previous arithmetic at boundaries.
- **Authorization/privacy:** guests, two owners on the same date, direct policy/Action calls, forged IDs/date/owner/revision, expired sessions and account switching during Livewire interaction. Public account preferences do not expose content/mood. Verify private fields are absent from unrelated HTML, hydration state, serialization, and analytics results.
- **Concurrency:** independent MySQL connections exercise create/create, edit/edit, edit/delete, delete/recreate, and timezone changes while an editor is open. Verify committed state after lock waits and no silent overwrite/resurrection. Follow existing controlled-worker patterns with short timeouts and test-database guards; run serially.
- **Schema/upgrade:** unique owner/date, user FK, mood constraint, default revision, populated Phase 4 upgrade, rollback/reapply preserving earlier tables, and the isolated Phase 3 regression. Two users may save the same calendar date.
- **Regression:** journal save/delete does not change habit rows or analytics; existing navigation, tracking, category, profile, auth, and lifecycle behavior still passes.
- **Browser acceptance:** actual save/reload and delete/cancel, native date navigation, unsaved drafts across all navigation paths, stale editor recovery in two tabs, optional mood clearing, keyboard/dialog focus, mobile/breakpoint layouts, reduced motion, and normal session/CSRF enforcement. PHPUnit alone cannot prove browser interactions or production CSRF behavior.

Use the existing installed browser and automation runtime if available; browser tooling is a development verification aid, not an application dependency. Do not install new browser libraries to implement journaling. Record unavailable checks honestly rather than treating HTTP tests as browser evidence.

## Verification, documentation, and risks

During implementation, run focused journal and migration tests first, then the full serial suite and build using the existing MySQL test database:

```bash
php artisan test tests/Feature/Journals --compact
php artisan test tests/Feature/Tracking/MigrationTest.php --compact
composer ci:check
npm run build
git diff --check
```

`composer ci:check` includes Pint and the full PHPUnit suite. Never run database-resetting suites concurrently with browser fixtures. Inspect final diffs and complete every applicable item in `DESIGN_VERIFICATION.md`; record actual commands/results, migration effects, browser evidence, and limitations in `PHASE_5_CLOSEOUT.md`.

Update the database outline with the implemented revision/constraints, architecture with journal write boundaries, UI with date/deletion behavior, security with journal ownership/privacy, and testing/commands/structure with actual files and checks. Record major deviations from the established date/privacy contract in an ADR before implementation; no new ADR is required merely to repeat those existing decisions. Keep Phase 4 closeout evidence historical.

Main risks are foreign-user access through reactive requests, private content in generic serialization/logging, accidental draft loss, stale overwrite or resurrection after deletion, timezone-boundary writes, and migration tests depending on the latest batch. The explicit boundaries and acceptance cases above address each. Physical deletion intentionally has no application recovery; confirmation must make that clear. Owner-row serialization favors correctness for brief daily writes; do not add queues or optimize locks without measured contention.

The initial planning change retired the previous phase's plan and recorded the confirmed choices. The subsequent authorized implementation follows this contract; actual migration, test, build, and browser evidence is recorded in the closeout. The browser history guard in `resources/js/app.js` was required to preserve both URL and draft when Back/Forward is cancelled. No commit or push has been performed.
