# Account management implementation plan

Status: Phases 1 (password) and 2 (verified email) are authorized and implemented; remaining features proposed. Phase 2 evidence is recorded in [the email-change report](ACCOUNT_EMAIL_CHANGE_REPORT.md) and [ADR-0009](adr/ADR-0009-VERIFIED-EMAIL-CHANGE.md). Reviewed on 2026-10-25 against synchronized `dev` at `e124ee14896706e31587b03b227a1b8029abadbe` (also the remote `dev` and `main` head at review time). The original plan below describes the broader follow-up. Current implementation evidence is recorded in [the Phase 1 report](ACCOUNT_PASSWORD_CHANGE_REPORT.md).

## Phase 2 accepted clarification

The 2026-10-26 request authorizes verified email changes, including the pending-request migration and password/reset cancellation hooks. The user explicitly chose identical request/resend behavior for occupied and unoccupied addresses, with uniqueness checked at confirmation after mailbox control is proved. This supersedes the original immediate-availability check in step 1 below. Signed links, fresh password checks at both steps, 60-minute expiry, token rotation, owner/generation checks and session revocation follow the plan. Only Profile and Security navigation remain enabled; session-management UI, deletion and Settings draft protection are still deferred.

## Phase 1 sequencing adjustment

The 2026-10-25 implementation request authorized password changes only. At that point, pending email state did not exist, so its migration and cancellation hook were deferred to Phase 2; they were not a strict dependency for password changes. Phase 1 introduced no migration and enabled only Profile/Security navigation and the password form. Phase 2 now adds pending email state and cancellation hooks, as described above. Session-management UI, deletion and draft protection remain proposed; the password and email lifecycles are accepted in ADR-0008 and ADR-0009.

## Scope and decisions

Add authenticated password changes, verified login-email changes, active-session visibility and revocation, confirmed account deletion, and Settings draft protection. Keep the Laravel 13 / Fortify / Livewire 4 modular monolith and Terminal Green interface. Reuse framework authentication, transactions, validation, policies, database sessions, and the existing navigation guard.

This is a separately proposed follow-up to the completed roadmap phases. Existing Settings intentionally has no navigation guard; this plan proposes changing that contract. Account deletion is a new, explicitly destructive operation, not an implementation of habit trash expiry. Recoverable habit trash and historical preservation continue unchanged for accounts that are not deleted.

Recommended implementation contracts:

| Area | Proposed contract |
| --- | --- |
| Password | Require the current password and confirmed new password; retain the initiating browser, revoke other sessions and remembered logins. |
| Email | Keep the old login/recovery address until the new address is verified and the owner confirms the change with their current password. |
| Verification | One pending request per owner; 60-minute lifetime; replacement/resend invalidates the previous token. No registration-wide verification gate. |
| Sessions | List the owner's active sessions; provide **Sign out other sessions** and existing **Sign out** for the current session. |
| Individual session removal | Outside this first implementation. Deleting a single session row is insufficient against stale writes; a later design would need durable revocation and remembered-device semantics. |
| Account deletion | Immediate, confirmed, transactional deletion of account-owned live data, including history and trash. No account recovery window. Backup retention is described separately and must be settled before release. |
| Settings drafts | Warn before abandoning changed forms; no autosave or persistent draft storage; security redirects take precedence. |

The password, email and verification contracts are accepted in the product documents and ADR-0008/ADR-0009. The remaining rows are recommendations for implementation review. Record deletion semantics and any further credential/session changes in an accepted ADR and update the product/UI contracts when implementation is authorized. Do not silently reinterpret existing history-preservation rules or mark roadmap work complete from this plan.

## Reviewed foundations

- [Guardrails](DESIGN_GUARDRAILS.md), [requirements](PRODUCT_REQUIREMENTS.md), [architecture](ARCHITECTURE.md), [database design](DATABASE_DESIGN.md), [UI specification](UI_UX_SPEC.md), [roadmap](ROADMAP.md), and [verification checklist](DESIGN_VERIFICATION.md) remain authoritative in the order established by [AGENTS.md](../AGENTS.md).
- [Authentication/account review](AUTH_ACCOUNT_REVIEW.md) identifies these missing account surfaces. Its original defect descriptions are historical: the current code already includes the subsequent reset-session, confirmation, recovery-response, and auth-interface fixes.
- `routes/web.php` authenticates the application routes. `bootstrap/app.php` runs `AuthenticateSession` across the web group, including Livewire requests. `RequiresSameUser` protects existing hydrated owner components.
- `config/fortify.php` enables registration and password recovery, not password/profile updates or email verification. `User` has a hashed password cast, hidden credentials, and `email_verified_at`, but does not implement `MustVerifyEmail`.
- `PasswordValidationRules`, production password defaults, Fortify's confirmation view, and user-bound confirmation throttling are reusable. The existing three-hour confirmation timestamp is not sufficient by itself for the proposed final destructive or credential-changing submissions.
- `ResetUserPassword` changes the password and remember token and deletes database sessions transactionally. The session store uses the default database connection. Moving sessions to another store/connection would require a different atomicity design.
- `SaveProfile` locks the authenticated user and allows only the six existing profile fields. Credentials must remain outside that allowlist. Journals, moods, notes, and private profile/account fields remain absent from shared projections.
- `resources/js/app.js` and the habit/journal/activity editors already cover Livewire navigation, native unload, and cancelled Back/Forward traversal without storing drafts. Settings can extend that behavior.
- [ADR-0004](adr/ADR-0004-DAILY-TRACKING-HISTORY.md) preserves effective-dated tracking and immutable checkpoint snapshots; [ADR-0006](adr/ADR-0006-SHARED-PROFILE-ACCESS.md) defines current social privacy. Credential and session changes must not mutate these records or rules.

Framework behavior was checked against installed source, especially `SessionGuard`, `AuthenticateSession`, `DatabaseSessionHandler`, Fortify's password-update/reset controllers, and email verification controller. Supporting references: [Laravel authentication](https://laravel.com/framework/docs/13.x/authentication), [Laravel email verification](https://laravel.com/framework/docs/13.x/verification), and [Livewire navigation](https://livewire.laravel.com/docs/4.x/navigate). Laravel's normal verification flow verifies the user's current address; it does not itself implement a pending-address swap.

## Shared implementation boundaries

Create Settings navigation for **Profile**, **Security** (password, email, sessions), and **Account** (deletion). Preserve `/settings/profile` and its current profile fields. Prefer ordinary server-submitted Blade forms for passwords and final destructive operations, with CSRF protection and method spoofing where appropriate. This avoids serializing passwords into Livewire public properties. Livewire remains appropriate for the existing profile editor and non-secret presentation.

All account mutations derive the subject from the authenticated guard, never a submitted user ID. Apply owner authorization at the HTTP/action boundary and recheck the fresh user within the transaction. If a component is used, retain `RequiresSameUser` and authorize every hydrated action. Use named, account-bound limits for password verification and mutations, with an additional IP limit where useful; changing sessions must not reset the account's attempt budget. Initial recommendation: five sensitive submissions per minute per user and a 60-second email resend cooldown, tested with the existing cache-backed limiter.

Use separate validation error bags per form. Never flash passwords, verification tokens, cookies, or session payloads. Extend Laravel's non-flash list for any new sensitive input name and ensure request/error logging does not capture it. Keep sensitive responses non-cacheable through the existing security middleware. No passwords, tokens, pending email addresses, or session identifiers belong in analytics or shared views.

For account security operations, lock a freshly loaded user row before verifying the current password and mutating credentials. Recheck existence and owner identity after acquiring the lock. Hold no database lock during email delivery. Serialize password reset with the same user-first locking discipline so a stale reset/change cannot overwrite a newer credential state. Keep transactions short, use bounded deadlock retries where appropriate, and return safe errors on failure.

After a successful operation that changes the password hash or revokes sessions, refresh the guard's user, set the current session's password-hash marker consistently with Laravel, migrate its session ID with destruction of the old ID, and regenerate CSRF state. Complete with a full redirect so the next form receives the new token. Verify the order against the installed middleware; successful mutation followed by immediate logout or a 419 is a failure. Passwords are never retained across that redirect.

Native `logoutOtherDevices` verifies the supplied password and forces a password rehash. It does not delete database rows. Do not invoke it with the old password after changing the password. A small reusable account-session action is justified by password changes, email confirmation, and explicit revocation, but should expose clear operation-specific behavior rather than a generic authentication framework.

## 1. Authenticated password change

### Reuse and changes

Enable only Fortify's password-update feature and register an `UpdateUserPassword` action implementing its existing contract. Reuse the password rules, hashed cast, confirmation field convention, auth middleware, and response contracts. Add a Security form with current password, new password, and new-password confirmation; use `autocomplete="current-password"` and `autocomplete="new-password"` correctly.

The action validates input shape and production policy, locks/reloads the owner, verifies the current password against that fresh row, saves the new password, rotates the global remember token, invalidates outstanding password-reset tokens, and cancels pending email changes in the same transaction. Delete other database sessions. The changed hash makes stale authenticated session payloads fail subsequent middleware checks. Keep the initiating session through the shared finalization flow above.

Account/session cleanup must not depend solely on Fortify's post-action reset-token cleanup, which occurs outside the action transaction. Check duplicate cleanup/event behavior when binding the action and response. Reuse the same locking and pending-request cancellation in forgotten-password reset without changing its public response parity or its contract of revoking all sessions.

### Security, data, and UI

No migration is required for password changes. No profile, tracking, journal, relationship, or analytics writes are permitted. Explain beside Save that other sessions and remembered logins will be signed out. Successful change requires a server-confirmed status; failures keep non-secret context but clear password values. Do not offer an optional bypass of current-password verification based on a previously confirmed timestamp.

### Regression coverage and dependency

PHP: guest/CSRF rejection, wrong current password, weak/mismatched/non-string input, throttling across sessions, attempted foreign-user fields, atomic failure, old reset-token invalidation, pending-email cancellation, and unchanged domain records. Exercise a second session, remember-only cookie, old marker, and legacy marker-free session. Assert the new password works and the old one fails.

Browser: use independent cookie contexts; change the password, save a profile change in the initiating browser, and prove the other browser cannot navigate or submit a preloaded Livewire mutation. Check current-session ID rotation and a subsequent CSRF-protected submission. Check keyboard/error focus, password-manager attributes, pending state, and narrow layouts.

Implement after the shared locking/session design and before email confirmation, which uses the same revocation contract.

## 2. Verified email change

### Reuse and schema

Reuse Laravel validation, signed URLs, mail notifications, hashing/random generation, and transactions. Do not enable Fortify's generic profile-update route or replace the existing profile action. Do not add `verified` middleware globally: requiring verification of a new address does not authorize locking existing users out of the application.

Add `pending_email_changes` with an opaque UUID primary key, unique `user_id` foreign key cascading on user deletion, `new_email` (255), `source_email` (255), SHA-256 token hash (64), `expires_at`, `last_sent_at`, and timestamps. Index expiry for pruning. Generate at least 256 random bits for each token, store only its hash, and compare safely. `source_email` binds the request to the current login identity; password/reset operations also delete pending requests.

Use the existing registration/login normalization and database uniqueness semantics consistently; explicitly test case variants. Do not reserve addresses globally with a unique constraint on pending requests. `users.email` remains the final uniqueness authority. New users and other pending requests can race, so confirmation must handle the database unique constraint safely.

### Request, verification, and cancellation flow

1. The owner submits a new email and current password. Lock the user; validate the address differs from the current address. Per the accepted Phase 2 clarification, do not disclose availability at request time; check uniqueness only after mailbox verification at confirmation. Replace the owner's pending request with a fresh generation/token. Commit before sending mail.
2. Send a temporary signed verification link bound to the request, owner, and token to the proposed address. Keep the current address active for login and recovery. No unverified address receives password-reset authority.
3. A GET opens an authenticated confirmation page without changing anything. Mail scanners cannot consume or apply the request. A logged-out owner may log in using the existing address and return to this page; a different signed-in account gets no pending-address disclosure.
4. Final POST requires CSRF and the current password, and revalidates signature, token hash, expiry, source email, current owner, and request generation under user-then-request locks. Update `users.email`, set `email_verified_at`, delete the pending request and old-address reset token, rotate remembered access, and revoke other sessions atomically. Force a fresh password hash for session invalidation because changing email alone would leave the existing hash unchanged. Preserve the initiating browser through the shared flow.
5. Resend is rate-limited, rotates the token, and invalidates older links. Cancellation is authenticated, owner-scoped, CSRF-protected, and generation-aware. Expired, cancelled, replaced, replayed, or already-used links produce safe recovery instructions without mutation. A lost race for the address keeps the original email and lets the owner choose another.

Do not delete password-reset tokens for a proposed address that belongs to someone else. After successful reassignment, ensure stale tokens for the newly acquired address cannot become valid for the new owner; perform that cleanup only once the address is owned within the transaction. Lock and recheck recovery updates so a reset started for an old email cannot finish against a reassigned identity.

Delivery failures leave the current login unchanged and a visible retryable pending state. Return a delivery failure, not a claim that mail was sent. A late message from a superseded request must remain invalid. Use synchronous transactional mail after commit initially; no queue infrastructure is justified. Redact verification query parameters from access/application logs and avoid third-party assets/referrer leakage on the landing page. A pruning command may remove expired requests; expiration is enforced at verification even if pruning never runs.

The verification email is required transactional authentication mail. An alert to the old address is recommended as part of this narrowly scoped security change; record that explicit addition to the current product exclusions rather than introducing general notifications. It must contain no token that can bypass authentication. Alert delivery failure after the committed change must not revert identity or misreport whether the change succeeded.

### UI, tests, and dependency

Security shows the current login address, a separately labelled pending address, expiry/retry guidance, and Resend/Cancel actions. State plainly that the old address remains usable until completion. Confirming a link has a normal labelled form, visible error summary, and final action; email links never execute mutations directly.

PHP: initial request and no premature login change; wrong password/foreign owner; invalid signature/token/expiry; source-email change; cancellation/replacement/replay; two owners racing for one address; delivery failure; resend cooldown; old/new reset-token handling; password reset versus email confirmation; no new verification gate; session revocation and unchanged private activity. Test schema up/down/reapply on the isolated test database.

Browser: receive mail through the test mail transport; verify in the same and a separate authenticated context; try a wrong account and an expired/replaced link. Prove the old login works before confirmation and only the new login works after it. Check pending UI, error focus, and submitting another protected form after success. Real production SMTP delivery remains a separate release check.

Depends on password/session finalization and the pending-request migration. The mail-preview and final POST can use dedicated controllers and actions; they do not belong in Blade business logic.

## 3. Active sessions and revocation

### Reuse and behavior

Use the configured database session table and Laravel session authentication. Query only rows with the authenticated `user_id` and an activity timestamp within the configured lifetime. Label the current session using the server-side session ID comparison. Paginate if necessary; select only metadata needed for the list. Do not decode session payloads or send raw session IDs to the client.

Display a conservative browser/device description derived from escaped user-agent text, last activity in the user's timezone, and IP address as connection metadata rather than a claimed location. These are sessions, not verified physical devices. Refresh explicitly; no polling or new device-detection dependency. The current row may not exist until a response is saved, so provide a reliable **This session** presentation without assuming it appears in the query.

**Sign out other sessions** requires the current password, account-bound throttling, and an explanation that remembered logins on other browsers will also stop working. Under the user lock, force a fresh password hash using Laravel's native other-device invalidation behavior, rotate the global remember token, delete other session rows, and finalize the current session. The actual password string does not change. Existing **Sign out** handles leaving the current session.

Removing rows alone is not the security boundary: a request already in flight can write a stale session back. Subsequent requests must fail the password-hash check, including Livewire updates. Test remembered-cookie recall and marker-free legacy sessions around the race. Requests already authorized before revocation cannot be recalled; document that limitation without claiming instantaneous cancellation. If a race permits a newly minted session to remain authorized after revocation, fix authentication admission/serialization before shipping rather than weakening the acceptance test.

### Schema, accessibility, and tests

No new table is required for the recommended scope. Measure the owner/activity query using existing `user_id` and `last_activity` indexes; add `(user_id, last_activity)` only if measured access patterns justify it. Do not use the absence of a foreign key on `sessions.user_id` as an ownership guarantee.

Use a semantic list/table that stacks on mobile, unambiguous **This session** text, and an accessible confirmation surface with focus restored on cancellation. Announce server-confirmed completion. Do not imply an unselected device can be individually revoked.

PHP: owner isolation, expired-row filtering, metadata escaping, missing current row, guest/wrong-password/throttle rejection, repeated revocation, transaction rollback, current-session survival, remember-token invalidation, and stale-write interleavings against MySQL. Browser: at least three contexts, with a remembered-only context and a preloaded Livewire form; confirm both other contexts lose access while the initiator can still save. Verify stale pages cannot restore authorization through Back/Forward.

Implement the common revocation mechanism before the password/email surfaces rely on it, then add the session list and explicit action. Individual-device removal needs a separate design for persistent revocation of stale session IDs and the global remember token; it is not implied by this plan's list UI.

## 4. Confirmed account deletion

### Contract and authorization

Add an owner-only Account page and a dedicated transactional deletion action. Require the current password on final submission and explicit confirmation that all account data will be permanently removed. A fresh confirmation page may reuse Fortify's confirmation surface, but its timestamp cannot replace the final password check. Rate-limit attempts and protect the endpoint with authentication, CSRF, and owner authorization. No arbitrary user identifier or administrator deletion capability is introduced.

The warning lists active, archived, and trashed habits; entries and notes; checkpoint/history configurations; journals and moods; custom categories; profile data; pending/accepted follow relationships; sessions; and recovery/email-change requests. Confirmed account deletion overrides retention for that account only. Do not anonymize a remaining public profile, transfer habits, preserve public-marked activity, or add automatic expiry. The current recoverable habit trash feature remains separate.

### Transaction and ownership cleanup

Existing domain foreign keys intentionally restrict parent deletion. Preserve those constraints and use explicit child-first cleanup; no broad cascade conversion, disabled foreign-key checks, or soft-delete-only user removal.

Lock/reload the owner exclusively, verify the password, and scope all queries by ownership. Include soft-deleted habits. Validate any unexpected cross-owner references before cleanup; fail safely rather than deleting another user's records to satisfy a constraint. Use this dependency order within one transaction:

| Data | Treatment |
| --- | --- |
| Habit entries and checkpoint completions | Delete the owner's entries, allowing their checkpoint-entry children to cascade. Remove all versions of private notes with those entries. |
| Tracking versions and snapshot checkpoints | After entries/completions are gone, delete the owner's habits' tracking versions; snapshot checkpoint children cascade. |
| Suspensions | Delete for the same owned habits. |
| Habits and current checkpoints | Physically delete all owned habits, including archived/trash; current checkpoints cascade. |
| Custom categories and aliases | Delete only owner categories after habits; aliases cascade. Preserve all system categories and other owners' categories. |
| Journals | Delete all owner dates, including private content and mood. |
| Follows | Delete pending and accepted rows in both directions. Other users and their habit history remain intact. |
| Account security state | Delete pending email changes, reset tokens for the current owned email, and database sessions. Do not delete another user's tokens because their address appeared in a failed pending request. |
| User | Delete last, with all required dependents gone. |

The relative order of independent journals/follows/security cleanup is flexible; the habit-entry/version/checkpoint ordering is not. There are currently no persisted analytics caches to purge. Derived owner reports disappear because the source account is gone; other users' personal scores remain unchanged. Shared profile/habit URLs and pending Livewire interactions must reauthorize and return the established safe missing/revoked-access behavior.

Most habit actions already take a shared user lock before a habit lock; journal/profile actions lock the user exclusively. Follows lock both users in ascending ID order. Preserve those orders. Audit every owner-writing path, including current category creation and recovery, so account deletion cannot race an unguarded insert or expose a database exception. Category creation currently needs a transactional user-existence/lock boundary. Do not acquire a second user lock in arbitrary order while deleting relationships.

After commit, log out the current guard, invalidate its session, regenerate CSRF state, clear remembered cookies, and redirect to a guest confirmation page. A late session write may recreate an orphan row because sessions have no user FK; it must not authenticate without a user. Test this explicitly and ensure normal session cleanup removes orphans. A database failure before commit must roll back every deletion and retain a usable account; do not show success or log out prematurely.

### Persistence limits and release decisions

No new deletion schema is required for immediate hard deletion. No migration rollback can recover deleted account data; say this clearly. Test cleanup with representative large histories and query/memory bounds. If it cannot finish safely as one transaction, stop to design an authorized asynchronous lifecycle rather than silently introducing partial deletion or a queue.

Live application deletion does not erase existing backups or already delivered mail. Before release, document the actual backup retention, restricted restoration procedure, and how a restore avoids making deleted accounts accessible again. Do not promise a retention period or invent a personal-data audit ledger in this feature. Determine any minimal deletion-suppression record with the retention policy before choosing its schema. Logs must never contain deleted content or credentials.

### UI, tests, and dependency

Use a distinct danger section, explicit **Delete my account** wording, a descriptive consequence summary, and a safe default focus on Cancel in any dialog. Require no terminal command or unexplained magic text. Support keyboard confirmation/cancellation, Escape, focus return, pending state, and a single final submission. Success must never leave private account content visible in the application shell.

PHP: wrong password, forged owner, guest, CSRF, throttle, complete graph deletion, rollback injected midway, archives/trash, journals/moods, both follow directions/statuses, system/foreign category preservation, unchanged other-user analytics, no surviving tokens/sessions with authority, and concurrency with habit/journal/category/follow/reset writes. Use real MySQL constraints and controlled transaction barriers for concurrency coverage.

Browser: cancel without mutation; validation failure; successful deletion across two signed-in contexts; a foreign viewer's preloaded profile/heatmap; back navigation; old remember cookie and recovery link; logging in with the deleted identity; other user's continued access. Destructive fixtures use only the isolated test database.

Implement after the credential/session boundaries and pending-request schema are settled, and after the deletion/retention contract is accepted. This is the last account mutation to enable.

## 5. Unsaved-change protection for Settings

### Reuse and frontend changes

Extend the existing editor guard in `resources/js/app.js` to a Settings form marker. Reuse the numeric history-position mechanism, `livewire:navigate` interception, and native `beforeunload` hook. Avoid a new router, dependency, draft table, or storage mechanism. Keep page-specific wording and existing journal/activity/habit behavior intact.

Track dirtiness against the last server-confirmed values, including selects/toggles and returning a field to its saved value. Warn for app links, Settings navigation, Back/Forward, reload, and closing the tab where supported. Native browsers control unload wording and may suppress the prompt; do not promise protection after a crash or process termination.

Clear only the form that successfully saved, and only when the saved response corresponds to its submitted values. Validation/network failure keeps it dirty. Either disable editing during a save or preserve edits made after submission as a new dirty state. Saving Profile must not clear an unfinished password/email form. Cancelling navigation must preserve the current form and restore browser-history position without duplicate handlers after repeated visits.

Password forms keep secrets only in the current form's memory/DOM and clear them on cancellation, completed navigation, or server validation response. Do not copy them into history, local/session storage, flashed old input, or Livewire snapshots. Successful security submissions bypass their own leave warning and load fresh CSRF state. Authentication failure, account deletion, or revocation must force the security transition even with a dirty draft; a navigation guard never grants continued access or restores a previous user's form.

### Backend, accessibility, tests, and dependency

No migration or new save API is required. Existing explicit validation/authorization remains authoritative. For Profile, retain `SaveProfile`, its allowlist, server-confirmed feedback, and current owner boundary. Update the UI specification's explicit no-guard limitation when this behavior ships.

Use the established accessible confirmation pattern with Stay/Discard language, keyboard operation, safe initial focus, and focus return. Keep labelled fields, visible focus, 44px actions, polite Saved status, and the existing error summary. Native unload prompts need no custom modal substitute. Profile changes remain explicit-save only.

PHP: preserve profile allowlist, validation and owner-switch tests; confirm credentials cannot be updated through Profile and password inputs are not flashed. Browser: clean navigation without warning; dirty link navigation; cancelled/accepted Back and Forward; reload; save success; validation failure; edits during pending save; toggles/timezone; repeated navigation; multiple forms; explicit logout and external revocation; account switch; successful password/email redirect; existing journal/activity/habit guard regressions.

The Profile guard can be developed independently after the Settings layout is agreed. Integrate all security forms after their final redirect and session behavior is stable.

## Affected files and implementation sequence

Paths below are anticipated; new paths are proposals, not existing functionality.

| Step | Files/boundaries | Deliverable |
| --- | --- | --- |
| 1. Contracts and foundations | `docs/adr/` (new ADR), product/UI/architecture docs; pending-email migration/model; `app/Actions/Fortify/ResetUserPassword.php`; proposed `app/Actions/Accounts/` | Accept lifecycle/deletion contracts; establish the additive schema before credential actions cancel pending requests; define owner locks, account limits, current-session finalization, and shared revocation tests. |
| 2. Session/password backend | `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, proposed `app/Actions/Fortify/UpdateUserPassword.php`, proposed account actions and request/controller classes | Enable authenticated password updates and other-session revocation with atomic security cleanup. |
| 3. Settings surfaces | `routes/web.php`, existing profile page, proposed Security Blade page/controller, shared Settings navigation | Password form and owner-only session list; preserve existing route and profile behavior. |
| 4. Pending email | New actions/controllers/mail class using the Step 1 schema, Security UI, console schedule if pruning is added | Verified pending-address swap, delivery/retry behavior, credential-race tests. |
| 5. Draft protection | `resources/js/app.js`, Settings views, existing browser guard coverage | Settings dirty-state behavior with security redirects taking precedence. |
| 6. Account deletion | Proposed account page/controller/action and `UserPolicy` owner ability where used; category mutation boundary; affected writer locks | Explicit transactional cleanup and current/remote access revocation. |
| 7. Integration and closeout | `tests/Feature/Auth/`, `tests/Feature/Settings/`, new account tests, `tests/Browser/`, test fixture source, documentation | Full verification and accurate implementation evidence; no generated fixture data/screenshots/logs committed. |

Keep controllers thin and validation at Laravel boundaries. Extract actions for transactionally complex/reused operations. Do not add a repository layer, RBAC system, service API, notification inbox, background queue, or general event/audit framework.

## Migration and deployment plan

The pending-email table is the sole required additive migration in the recommended design. Its `down` removes only that table. An optional measured session index has its own reversible migration. Account deletion uses existing foreign keys; password and draft changes need no schema. No production data backfill or automatic verification of existing addresses is required.

Implement migration up/down/reapply only on the guarded test database. In a future authorized release, migrate before exposing email-change routes. Do not drop the pending table during a code rollback with active requests; preserve it and either keep compatible handling or explicitly cancel the pending feature. Confirm database sessions still use the same connection, mail delivery works over the production configuration, application URLs/signatures use HTTPS, and Secure cookies/proxy settings remain correct. None of these deployment actions is authorized by this planning task.

## Verification and documentation gates

Use the repository's guarded test harness and synthetic owners/viewers with histories, checkpoint snapshots, archives/trash, journals, custom categories, follows, pending email, and multiple sessions. Keep checked-in fixture generator source; exclude generated private data, mail tokens, screenshots, cookies, and logs. No development/production database reset is allowed.

During implementation, run targeted tests first, then the complete PHP suite with `php artisan test --compact`, formatting with `vendor/bin/pint --test`, and `npm run build`. Run `npm run test:e2e` through the repository's documented environment/installed-browser configuration; inspect the runner's guarded database and locking requirements before execution. Any browser runtime libraries required by that configuration are test-environment dependencies, not application runtime dependencies. Do not install them automatically merely to write this plan.

Use real cookie jars for session regressions, normal auth/CSRF middleware, and MySQL for foreign keys/concurrency. A test using only `actingAs` cannot prove browser revocation. Include desktop/mobile layouts, keyboard focus, screen-reader labels/status, axe checks, no horizontal overflow, and no secrets in HTML, Livewire snapshots, logs, or browser persistence. Note any untested browser engines or production transport explicitly.

Acceptance requires all five feature test matrices above, migration rehearsal, existing auth/recovery/profile/social/history/guard regressions, and review against every applicable [design verification](DESIGN_VERIFICATION.md) item. Security and profile changes leave raw historical records byte-for-byte unchanged; deletion removes exactly the authorized owner's graph. Do not mark a race covered by a sequential test.

Update `PRODUCT_REQUIREMENTS.md`, `ARCHITECTURE.md`, `DATABASE_DESIGN.md`, `UI_UX_SPEC.md`, and `ROADMAP.md` for the accepted and implemented scope. Update the testing/command/deployment documentation that exists at implementation time, the auth review's follow-up status, and a closeout containing exact commands/results/remaining limitations. The original planning pass changed only this plan and did not run implementation tests or builds. Phase 1 implementation and current verification are recorded in the linked report.

Suggested documentation commit message, if later requested: `docs: plan account management and settings protection`.
