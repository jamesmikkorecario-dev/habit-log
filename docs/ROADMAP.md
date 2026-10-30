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

Private daily journal, optional mood, date navigation, and confirmed deletion are implemented. Required plain text, owner-only access, timezone-aware dates, stale-write protection, and dirty-draft navigation guards are documented in [the UI specification](UI_UX_SPEC.md#phase-5-private-journal) and [database design](DATABASE_DESIGN.md#phase-5-journal-schema). Verification includes 294 tests / 2,126 assertions, Pint, the production build, and 37 installed-browser checks; see [the closeout](PHASE_5_CLOSEOUT.md) for evidence, findings, and limitations. The additive journal migration is applied locally. Habit permanent deletion and automatic expiry remain deferred.

## Phase 6 — Social (complete)

Directional followers, private-account requests, approval/rejection, permitted profile viewing, and visibility enforcement. No comments.

Directional follow management and username-based read-only profiles/habits are implemented at `/people`. Signed-in browsing, private-account gates, retained pending requests, filtered analytics, stale-request protection, and owner-only writes follow [ADR-0006 and its access/state contract](adr/ADR-0006-SHARED-PROFILE-ACCESS.md). Full verification passed 329 tests / 3,610 assertions, Pint, the production build, and 45 Chrome checks, with measured filtered-report and relationship-list queries. The follows migration is applied locally as batch 6. See [the closeout](PHASE_6_CLOSEOUT.md) for evidence and limits.

## Phase 7 — Hardening (implemented; release verification open)

Security, privacy, accessibility, mobile, performance/query reviews; critical E2E coverage and deployment documentation.

Local implementation passed 336 tests / 3,737 assertions, Pint, the production build, 14 browser scenarios, comparative performance checks and isolated recovery verification. [The closeout](PHASE_7_CLOSEOUT.md) records fixes, critical browser coverage, representative measurements, recovery rehearsal evidence and open assistive/device, hosted CI, target-host and capacity gates. The Phase 7 plan and findings ledger were retired during commit preparation; their remaining release gates are retained in the closeout. Phase 7 release verification remains incomplete until those gates are resolved. Habit permanent deletion and automatic expiry remain deferred.

## Account management follow-up — Phase 1

Authenticated password changes are implemented in Settings → Security, including current-password checks, rate limiting, session/reset-token revocation, and stale-write protection. See [the implementation report](ACCOUNT_PASSWORD_CHANGE_REPORT.md) for verification and limitations and [ADR-0008](adr/ADR-0008-AUTHENTICATED-PASSWORD-CHANGE.md) for the accepted credential lifecycle. Email changes and session management are covered by the follow-up phases below. Account deletion and Settings draft protection in [the broader plan](ACCOUNT_MANAGEMENT_PLAN.md) remain deferred. No original roadmap phase or trash/history contract is broadened.


## Account management follow-up — Phase 2

Verified login-email changes are implemented with current-password checks, expiring signed links, pending/resend/cancel states, uniqueness at verified confirmation, session revocation and password/reset cancellation. The additive pending-request migration and recovery race protection follow [ADR-0009](adr/ADR-0009-VERIFIED-EMAIL-CHANGE.md). See [the implementation report](ACCOUNT_EMAIL_CHANGE_REPORT.md) for verification and release limits. This does not expand account deletion, session-management UI, Settings draft protection or habit trash scope.

## Account management follow-up — Phase 3

Session management is implemented and locally verified: owner session listing, individual/all-other revocation, current-password confirmation, current-session retention, durable stale-session rejection and the accepted shared remember-token limitation. See [the session report](ACCOUNT_SESSION_MANAGEMENT_REPORT.md) and [ADR-0010](adr/ADR-0010-SESSION-MANAGEMENT.md). Account deletion and Settings draft protection remain deferred.

Account-management Phase 4: secure account deletion is implemented and locally verified; see [the implementation report](ACCOUNT_DELETION_REPORT.md). It is separate from the completed core roadmap phases; the Composer advisory is patched; local backup remediation and production backup/restore prerequisites remain open. Settings draft protection remains deferred.

Account-management Phase 5: Settings unsaved-change protection is implemented and locally verified. See [the implementation report](SETTINGS_UNSAVED_CHANGES_REPORT.md). This supersedes the earlier Settings draft-protection deferral; backup/restore deployment blockers remain open.

Deployment remediation 2: provider-neutral encrypted backups, managed expiry, external deletion capture and isolated reconciliation/admission are implemented and locally verified. See [the backup/recovery security report](BACKUP_RECOVERY_SECURITY_REPORT.md). Production storage/durability/currentness, key custody, scheduler/alerts, target recovery acceptance and the historical backup's authorized disposal remain open deployment gates. This does not change habit-trash scope or authorize deployment.
