# Account Management Phase 2 — verified email change

## Scope and decisions

Started from clean, synchronized `dev` at `938225d4bcc49e373fdf48cdf264121fd89aa55f`, matching remote `dev` and `main`. This implements only verified email changes and the credential/session/recovery protections they require. Session-management UI, account deletion, Settings draft protection, general notifications and a registration-wide verification gate remain excluded.

The user explicitly chose identical request/resend behavior for occupied and unoccupied addresses, with uniqueness checked at confirmation after mailbox control is proved. This supersedes the plan's original immediate availability check and is recorded in [ADR-0009](adr/ADR-0009-VERIFIED-EMAIL-CHANGE.md). Email format is validated immediately; a case-only variant of the current address is rejected. The existing database email collation/index remains authoritative for duplicate and concurrent claims.

## Behavior and security boundaries

- Settings → Security keeps the current login/recovery email active while a separate pending address awaits confirmation. Request and resend require the current password; neither changes the current identity or its verification timestamp.
- One owner-scoped pending generation stores only the SHA-256 hash of a 256-bit random token. The signed URL binds owner, generation, token and a 60-minute expiry. GET is read-only and supports intended login using the old address. A different signed-in owner receives no pending-address disclosure.
- Confirmation requires authentication, CSRF and the current password. Under exclusive user-then-pending locks, it rechecks the admitted credential hash/source identity, generation, token and expiry. A lost uniqueness race rolls back safely and preserves the original identity.
- Confirmation updates email/verification timestamp, rehashes the unchanged password, rotates remembered access, removes other database sessions, consumes pending state and deletes reset tokens for the old and newly acquired addresses atomically. It never clears another owner's tokens merely because their address was proposed. The confirming browser receives a fresh session ID, CSRF token and committed hash marker; it can immediately submit protected forms.
- Replaced, resent, cancelled, expired and used links cannot mutate identity. Resend rotates generation/token; stale request/resend/cancel forms cannot overwrite a newer request. Five sensitive email submissions per minute and one request/resend per minute are account-bound across IP/session changes, with a second send-interval check under the user lock.
- Password change/reset cancels pending email state. Recovery token creation/deletion and reset submission recheck identity under the user lock, preventing late operations against a reassigned address. Laravel's broker, token hashing/expiry, response parity and Fortify controllers remain in use.
- Verification mail is synchronous after commit. Failed delivery leaves a retryable pending request and truthful failure feedback. Production blocks log/array transports and nested log fallbacks. A token-free old-address alert follows successful confirmation; alert failure does not roll back or misreport success.
- Passwords/tokens/signatures are not flashed. Query failures retain the existing safe metadata logging; Apache's existing private access format excludes queries. The confirmation page has no third-party assets and uses existing no-store/no-referrer headers. External proxy logs must also omit sensitive queries.

No habits, entries, journals, relationships, profile preferences, privacy settings, tracking versions or analytics are written by the email workflow. Requests already authorized before revocation cannot be recalled; subsequent requests using stale markers are rejected. No session list or persistent draft is introduced.

## Changed files

| Area | Files and purpose |
| --- | --- |
| Pending state | `database/migrations/2026_10_26_000001_create_pending_email_changes_table.php`, `app/Models/PendingEmailChange.php`: one pending generation per owner, token hash and expiry. |
| Credential transactions | `app/Actions/Accounts/EmailChanges.php`, `FinalizeCredentialSession.php`, `ReplacePassword.php`, `app/Actions/Fortify/ResetUserPassword.php`: owner/password/generation checks, atomic confirmation, pending cancellation and reusable session finalization. |
| Recovery races | `app/Auth/AccountPasswordBrokerManager.php`, `AccountTokenRepository.php`: narrowly extend the native database token repository to recheck identity under the user lock. |
| HTTP/delivery | `app/Http/Controllers/Settings/EmailChangeController.php`, `app/Notifications/VerifyEmailChange.php`, `EmailChanged.php`, `app/Providers/FortifyServiceProvider.php`, `app/Http/Responses/PasswordUpdateResponse.php`, `routes/web.php`, `bootstrap/app.php`: signed/authenticated routes, limits, mail handling and secret-flash protection. |
| UI | `resources/views/pages/settings/security.blade.php`, `email-change.blade.php`, `verify-email-change.blade.php`, `resources/views/components/email-change-errors.blade.php`, `email-change-form.blade.php`: native accessible forms, pending state and confirmation/recovery views. |
| PHP verification | `tests/Feature/Auth/EmailChangeTest.php`, `EmailChangeConcurrencyTest.php`, `EmailChangeMigrationTest.php`; adjusted `PasswordChangeTest.php`, `PasswordChangeConcurrencyTest.php` and `tests/Fixtures/password-write-worker.php` for the new pending cancellation/locked recovery boundary. |
| Browser verification | `tests/Browser/email-change.spec.js`, `password-change.spec.js`, `tests/Containers/smoke.sh`, `tests/Fixtures/email-change.php`, `email-write-worker.php`, `browser-setup.php`: synthetic lifecycle, multi-session, accessibility and concurrency fixtures; deterministic observation of password-form loading before native navigation. |
| Documentation | Account plan/report, ADR-0009, product requirements, architecture, database design, UI specification and roadmap. |

## Migration and operations

The additive migration creates only `pending_email_changes`, with UUID primary key, unique cascading user FK, proposed/source email strings, hash, expiry index and send/timestamps. There is no proposed-address uniqueness constraint or activity backfill. Rollback drops pending state and invalidates outstanding links; confirmed email changes and all domain records remain intact. Isolated tests exercise populated upgrade, FK/unique/expiry-index constraints, cascade, rollback and reapply.

Apply this migration before serving the updated application code: the Security page and password replacement both access pending state. Use `php artisan migrate --path=database/migrations/2026_10_26_000001_create_pending_email_changes_table.php`. Database sessions and reset tokens must remain on the current default database connection for the documented atomicity guarantees. Production requires a working transactional mail transport and trusted application URL/HTTPS configuration. SMTP delivery, proxy-log redaction and target-host configuration are separate release checks; test mail is synthetic and local only.

The migration was applied to local development `habit_log` after verification, through a temporary runner that first checked the local environment, uncached configuration, MySQL connection and actual database name. Only this migration ran; no seeder or domain-data update ran. The pending table was confirmed present. Production was not migrated or deployed. Schema rollback must accompany rollback to code that does not require pending state.

## Verification

Database and browser checks use isolated `habit_log_test`. Browser checks use installed Chrome, the guarded test runner and production assets with the original Vite marker restored afterward. The full PHP suite, dependency audits, production build and full browser run were executed sequentially.

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Auth --compact` | 61 passed, 771 assertions. |
| `composer ci:check` | 487 passed, 6,834 assertions; Pint passed. Final run: 141.09 seconds. Includes controlled concurrency and populated migration upgrade/rollback/reapply checks. |
| `vendor/bin/pint --test` | Passed. |
| `composer audit --locked` | No security advisories. |
| `npm audit` | Zero vulnerabilities. |
| `npm run build` | Passed. No dependency or lockfile changes. |
| `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e` | 73 passed in 10.7 minutes; no retries. |
| `docker build --target production -t habit-log:email-change-check .` and `sh tests/Containers/smoke.sh habit-log:email-change-check` | Passed: non-root runtime, runtime-only files, health/login/assets, document root, CSRF and log privacy, including verification-token/signature canaries. Disposable container removed automatically. |
| `git diff --check` | Passed. |

An initial focused browser run passed 9 scenarios. After correcting the password test's circular wait described below, `npm run test:e2e -- tests/Browser/password-change.spec.js tests/Browser/email-change.spec.js` passed all 7 scenarios in 57.0 seconds with the same test environment prefix. Four email-specific scenarios cover cross-browser confirmation and revocation, pending lifecycle, mobile keyboard/CSRF/cooldown behavior and isolated form validation. Automated accessibility checks cover WCAG 2 A/AA and 2.1 AA rules; viewport checks assert no horizontal overflow.

Focused testing caught and fixed an expired-link rendering issue: an HTTP 422 app-layout response omitted Livewire's auto-injected assets and left mobile navigation uninitialized. Invalid-link GETs now render a normal read-only recovery page; invalid confirmation POSTs redirect to Settings feedback. A password concurrency fixture was updated to create its reset token before taking the competing user lock, because reset-token creation now correctly participates in that lock. No security assertion, timeout or retry policy was weakened.

The first full browser run passed 72 scenarios and exposed a circular wait in the existing password loading-state test: DOM inspection waited for the intercepted native navigation, which waited for the assertion to finish. The test now captures the form's busy/disabled state at submission before navigation starts, retains the single-request assertion, and still verifies successful completion and responsive layouts afterward. Application password behavior was unchanged.

Screenshots at 1440px and 390px are retained locally in ignored `storage/app/email-change/`: `form-*`, `pending-*`, `confirmation-*`, `success-*`, `expired-link-*` and `validation-*`. Desktop pending/expired views and mobile confirmation/validation views were visually reviewed. Generated signed-link fixture data, screenshots, build output and logs remain ignored; only fixture source is part of the change. Manual screen-reader operation, physical touch devices and non-Chromium engines remain unverified.

## Design verification and remaining scope

Owner checks, auth/CSRF, privacy defaults and query filtering remain server-side. The new FK, expiry index and short transactions protect only account security state; raw activity and all history remain authoritative. UI follows Terminal Green, plain-language feedback, keyboard focus and responsive controls. No theme, scoring, social relationship or original roadmap phase changes. The accepted ADR and product/UI/schema/architecture docs record the new contract.

The milestone closeout authorizes a commit on `dev`, push to `origin/dev`, and a `dev` → `main` pull request. Merge and deployment remain excluded. Commit message: `feat(auth): add verified email changes in security settings`.

Closeout review confirmed the additive migration, clean-database setup, populated upgrade/rollback/reapply, owner and credential checks, confirmation-time uniqueness, expiry/replay protection, recovery races and session handling against the implementation and passing verification above. No application or test changes were needed during closeout, so those results are reused. Generated screenshots, signed-link fixture output, logs, credentials and local database files remain excluded from version control. Hosted CI results belong to the pull request; production delivery and infrastructure checks remain outstanding as documented above.
