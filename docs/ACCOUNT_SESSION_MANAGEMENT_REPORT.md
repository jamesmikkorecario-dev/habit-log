# Account Management Phase 3 — Session management

Implementation and local verification are complete. Started from clean synchronized `dev` at `197e34a1d293e24d538c2ab4b0f7283cd2f3c3a0` after the email-change PR was merged.

## Requirements and affected boundaries

Add an authenticated session list to Security Settings, owner-scoped individual/all-other revocation with current-password confirmation, current-session retention, immediate rejection of subsequent revoked/stale Livewire requests, responsive accessible confirmation and account-bound rate limiting. Preserve existing password/email protections and all domain data. Account deletion and Settings draft protection are excluded.

Runtime inspection confirms database sessions on the default MySQL connection, table `sessions`, 120-minute idle lifetime and uncached configuration. The new migration adds session metadata and durable revocation hashes. Actions, middleware, the database session handler and Login event protect revocation and final writes under the account lock. Routes/controllers/native Blade forms provide the UI; no client receives raw session IDs, tokens, payloads or IP addresses. [ADR-0010](adr/ADR-0010-SESSION-MANAGEMENT.md) records the accepted individual-revocation/remember-token tradeoff and schema/runtime contract.

## Verification plan

- Focused PHP: owner/current/other/expired sessions, pagination and privacy, password/target validation, rate limiting, repeated revocation, stale session restoration and regenerated IDs, atomic failure, login/remember-cookie and final-write concurrency, clean/populated migration rollback/reapply.
- Focused browser: at least three independent contexts, individual/bulk revocation, remembered-only cookie, preloaded Livewire mutation, current/surviving session saves, CSRF, dialog cancellation and focus, blank secret fields, loading behavior and 1440px/390px captures.
- Sequential full `composer ci:check`, Pint, build, Composer/npm audits, full browser suite and production image/runtime checks. Test data stays in guarded `habit_log_test`; generated artifacts remain ignored.

## Changes

| Area | Files |
| --- | --- |
| Session security | `app/Actions/Accounts/SessionSecurity.php`, `ManageSessions.php`, `app/Auth/RevocableDatabaseSessionHandler.php`, `app/Http/Middleware/RejectRevokedSession.php` |
| Integration | `app/Providers/AppServiceProvider.php`, `FortifyServiceProvider.php`, `bootstrap/app.php`, `ReplacePassword.php`, `FinalizeCredentialSession.php` |
| HTTP/UI | `app/Http/Controllers/Settings/SessionController.php`, `EmailChangeController.php`, `routes/web.php`, Security view and `pages/settings/sessions.blade.php` |
| Migration | `database/migrations/2026_10_27_000001_add_session_revocation_state.php` |
| Tests | `SessionManagementTest.php`, `SessionManagementConcurrencyTest.php`, `SessionManagementMigrationTest.php`, `tests/Fixtures/session-write-worker.php`, `tests/Browser/session-management.spec.js` |
| Docs | Account plan/report, ADR-0010, requirements, architecture, database design, UI specification and roadmap |

## Verification results

- `composer ci:check`: **496 passed, 6,963 assertions**, 147.81 seconds; Pint passed.
- `composer audit --locked`: no advisories. `npm audit`: zero vulnerabilities.
- `npm run build`: passed; no dependency changes.
- `php artisan test tests/Feature/Auth --compact`: **70 passed, 900 assertions**, 30.67 seconds. Includes existing email/password/reset coverage plus session migration and concurrent-write tests.
- Initial focused browser credential regression run: seven existing email/password scenarios passed. The new modal initially lacked its accessible name; explicit title/description associations fixed that issue.
- `npm run test:e2e -- tests/Browser/session-management.spec.js`: **2 passed**, 11.1 seconds, using `HABIT_LOG_E2E=1` and installed Chrome through `E2E_NODE`. Covers multi-browser individual/bulk revocation, remembered-only cookies, stale Livewire mutation, surviving/current sessions, accessible dialog cancellation, CSRF and validation. The final full run also passed the added busy/disabled and single-request assertion.
- Full `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e`: **75 passed**, 7.3 minutes, no retries.
- Initial PHP pagination fixture omitted its cookie between simulated requests; it now preserves the cookie and verifies the actual paginated server projection. No assertion was removed or weakened.
- Desktop session list and mobile confirmation screenshots were visually reviewed. Captures are in ignored `storage/app/session-management/` as `sessions-*`, `confirmation-*` and `validation-*` at 1440px/390px. Automated accessibility and overflow checks passed.

- `docker build --target production -t habit-log:session-management-check .` and `sh tests/Containers/smoke.sh habit-log:session-management-check`: passed. Disposable runtime checks cover non-root execution, runtime-only files, health/login/assets, document root, CSRF and sensitive-URL log privacy. This smoke check uses file sessions and does not replace the database-backed browser suite above.
- `git diff --check`: passed. All generated screenshots, logs and browser data remain ignored.
- Read-only `EXPLAIN` on the isolated browser dataset used `sessions_user_id_index` (two estimated owner rows) with a filesort over the filtered rows. The revocation anti-join considered its primary hash index over 16 test rows. No additional index was justified by this small local dataset; production capacity remains unmeasured.

The migration was applied to guarded local development `habit_log` only after verification. The runner checked the environment, uncached configuration, configured/connected database names and session driver/table/connection, ran only the new migration, then verified both metadata columns and the revocation table. No seeder, credential update or domain-data change ran. Production was not migrated.

The milestone closeout request authorizes committing this implementation, pushing `dev` and opening a `dev` → `main` pull request. Merge and deployment remain excluded. Closeout review covers all implementation, migration, test and documentation changes and reuses the passing checks above; only this report's authorization and commit-message notes changed afterward. Generated screenshots, logs, fixture data and local databases are excluded from the commit. Hosted CI results are recorded on the pull request.

## Operational boundaries

Apply `php artisan migrate --path=database/migrations/2026_10_27_000001_add_session_revocation_state.php` before serving updated code. Runtime requires `SESSION_DRIVER=database`, `SESSION_TABLE=sessions` and the default database connection for session management. No session payload, credential or activity backfill occurs. Metadata is populated for existing session rows. The table added for revocation hashes has a cascading user FK and intentionally retains records until account removal; pruning by elapsed time could permit a sufficiently delayed writer to regain access.

Rollback removes metadata and revocation records. Coordinate code rollback and invalidate authenticated sessions before reverting to an implementation without this security boundary. Do not drop these fields underneath the updated code.

Individual revocation invalidates the account-wide remember token, per the accepted clarification, while preserving other active sessions. Bulk revocation additionally rehashes the unchanged password. These operations preserve pending email requests, password-reset tokens, profile preferences and all domain history. Already-authorized in-flight business operations cannot be recalled; subsequent revoked requests are rejected and their final session writes cannot restore authorization. Browser/device descriptions are estimates, not device identities. Non-database or non-default-connection management is unavailable. Manual screen-reader operation, physical touch devices, non-Chromium engines and production capacity verification remain outside local automated evidence.

Requested commit message: `feat(auth): add secure session management`.

## Design verification

Reviewed against AGENTS, guardrails and the design checklist: authenticated owner boundaries and CSRF stay server-side; individual targets cannot select the current/foreign/expired session; ordinary privacy and raw activity remain unchanged. The additive migration has a user FK, unique public-selector index and safe populated upgrade coverage. User-first transactions protect revocation, credential admission and final session writes. Native forms retain accessible focus, responsive controls and clear scope explanations; Settings has no draft guard. Requirements, schema, architecture, UI, roadmap and accepted ADR document the final behavior.
