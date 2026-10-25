# Account management Phase 1: password changes

Implementation handoff: 2026-10-25 on `dev`, before any commit, push, merge, or deployment. The pre-existing untracked account-management plan was preserved and updated with the authorized Phase 1 sequencing.

## Behavior

Settings → Security provides a Terminal Green password form using Fortify's authenticated PUT endpoint. Every submission requires the current password, a new password meeting the existing environment-specific policy, and matching confirmation. Five submissions per minute per user apply across IPs and sessions. Secrets remain in the native form and are cleared after validation; no Livewire password state or persistent draft is introduced.

A successful change hashes the new password, rotates remembered credentials, consumes old reset tokens, and removes other database sessions in a transaction. The current browser receives a fresh session ID and CSRF token and stays usable, including subsequent Profile saves. Its remembered cookie and prior password-confirmation timestamp are cleared. Old session cookies, remembered-only logins, and stale Livewire requests cannot restore access.

Password change and recovery serialize on a fresh exclusive user lock. Both reject a stale admitted credential hash; recovery also rechecks and consumes its token under the lock. The shared replacement action hashes literal input explicitly. Password updates reject NUL and bcrypt input over 72 bytes. Failed validation or transactional cleanup leaves the account unchanged.

No migrations or dependencies were added. Habits, notes, checkpoint snapshots/completions, tracking versions, suspensions, journals/moods, social relationships, profile/privacy settings, and other users' records remain unchanged. The pending-email table is not a strict password-change dependency because email-change state does not exist. Email changes, session-management UI, deletion, and Settings draft protection remain deferred.

## Changed files

| Area | Files |
| --- | --- |
| Credential actions | `app/Actions/Accounts/ReplacePassword.php`, `app/Actions/Fortify/UpdateUserPassword.php`, `app/Actions/Fortify/ResetUserPassword.php` |
| Fortify and HTTP | `app/Http/Responses/PasswordUpdateResponse.php`, `app/Http/Middleware/WebSecurity.php`, `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `routes/web.php` |
| Settings | `resources/views/pages/settings/security.blade.php`, `resources/views/pages/settings/⚡profile.blade.php`, `resources/views/components/settings-navigation.blade.php` |
| PHP regressions | `tests/Feature/Auth/PasswordChangeTest.php`, `tests/Feature/Auth/PasswordChangeConcurrencyTest.php`, `tests/Feature/Auth/RecoverySecurityTest.php`, `tests/Fixtures/password-write-worker.php` |
| Browser regressions | `tests/Browser/password-change.spec.js` |
| Documentation | Account-management plan and this report; `ADR-0008-AUTHENTICATED-PASSWORD-CHANGE.md`; product requirements, architecture, UI specification, security/privacy, testing strategy, project commands/structure, roadmap, and auth-review follow-up |

## Verification

All database-mutating tests use guarded `habit_log_test` and the repository's mutual-exclusion lock. Resource-intensive commands run sequentially. No development database was reset, no browser libraries installed, and no private production data used. Installed Windows Chrome is driven from WSL through the existing runner.

| Check | Result |
| --- | --- |
| Focused PHP: `php artisan test tests/Feature/Auth/PasswordChangeTest.php tests/Feature/Auth/PasswordChangeConcurrencyTest.php tests/Feature/Auth/RecoverySecurityTest.php --compact` | 25 passed / 300 assertions. |
| Focused Chrome: `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- password-change.spec.js` | 3 passed; success/revocation, unsuccessful changes/CSRF/throttling, mobile keyboard/pending behavior. |
| `composer ci:check` | Pint passed; 471 PHP tests / 5,420 assertions passed in 117.43 seconds. |
| `npm run build` | Passed. |
| `composer audit --locked` / `npm audit` | No advisories / zero vulnerabilities. |
| Full Chrome suite: `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e` | 61 passed, no retries/skips, in 6.6 minutes. |
| `docker build --target production -t habit-log:account-password-check .` | Passed; local test image only. |
| `sh tests/Containers/smoke.sh habit-log:account-password-check` | Passed: non-root/runtime-only image, health/login/assets, document-root isolation, CSRF and log privacy. Disposable container removed. |
| JavaScript syntax / `git diff --check` / source hashes | Passed; all 16 implementation/test sources match the completed suites. |

Early focused runs exposed test-harness issues: comparisons used factory-memory attributes instead of database-loaded attributes; a same-process PHP redirect assertion lost flashed errors; browser expectations assumed the dashboard instead of Fortify's intended destination and waited for locator navigation during a deliberately held request. Assertions were corrected. Real browser validation rendering, error focus, cleared passwords, and the full session boundary are verified independently. A sandbox MySQL connection failure was retried with authorized local database access.

## Screenshots and accessibility

Eight screenshots are in ignored `storage/app/account-password-checks/`: `form`, `wrong-current-password`, `validation`, and `success`, each at 1440px and 390px widths. These states passed selected WCAG 2 A/AA and 2.1 AA axe checks, no horizontal overflow, and minimum 44px submit height. Keyboard checks cover navigation into Security, all password fields, visibility-control tab stops, submission, and focus on success/error feedback. Additional widths 320, 375, 768, and 1024px were checked for overflow. Desktop error and mobile form/success captures were visually inspected.

- [Mobile form](../storage/app/account-password-checks/form-390.png)
- [Desktop validation](../storage/app/account-password-checks/wrong-current-password-1440.png)
- [Mobile success](../storage/app/account-password-checks/success-390.png)

Generated screenshots, synthetic reset mail, cookies, fixture data, and logs remain excluded from source changes. The test worker is source code only. Command logs and tested-source hashes are kept in the same ignored evidence directory.

Implementation and verification took about 22 minutes of active work (about 27 minutes elapsed, including approval/tool waits).

## Design review and limits

Reviewed against `DESIGN_VERIFICATION.md`: server-side ownership/authentication, CSRF, private defaults, immutable activity, transactional cleanup, focused validation/concurrency tests, ordinary accessible controls, and documentation boundaries are preserved. Existing foreign keys/indexes are unchanged; no migration rehearsal is needed for this phase. No destructive account/history operation is exposed.

No known blocking implementation issues remain. Revocation prevents subsequent access; it cannot recall a request already authorized before the change. Atomic session/token cleanup assumes the existing same-database configuration. Real production TLS/proxy settings and compromised-password service availability are deployment concerns, not exercised by local browser checks. Other browser engines, physical mobile devices, and manual screen-reader testing are not verified. Pending-email cancellation must be added when email changes are implemented.

Milestone closeout: the user authorized committing and pushing this implementation and opening a `dev` → `main` PR, without merging or deploying. All 28 changed/new source and documentation files were reviewed; the 16 implementation/test file hashes still match the completed suites, so verification is reused. Screenshots, logs, temporary data, and secrets remain excluded. Both the account-management plan and this report are preserved.

Authorized commit message: `feat(account): add secure password management`.
