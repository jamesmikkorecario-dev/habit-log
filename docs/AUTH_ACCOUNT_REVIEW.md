# Authentication and Account Settings inspection

The inspection below is historical. The subsequently authorized authentication security fixes are documented in the implementation section at the end of this file.

Reviewed 2026-10-25 on `dev`, commit `3f6d051f81f19a80ec83dd48e4e440be1e1d9d43`. Local `dev` and remote `dev`/`main` matched when inspection began. The worktree was clean. This report is the only added non-ignored file; application code, permanent tests, schema and dependencies were not changed. Nothing was committed, pushed, merged or deployed.

## Feature inventory

| Feature | Current state | Evidence / limits |
| --- | --- | --- |
| Registration | Implemented | Fortify registration; required name, unique email, confirmed password; immediate session login; private account, Terminal Green and UTC defaults. Username is chosen later in Profile Settings. |
| Login | Implemented | Email/password, Remember me, generic credential error, session rotation, loading feedback and intended destination. Five requests per minute per normalized email/IP. |
| Logout | Implemented | Authenticated, CSRF-protected POST; session invalidation and CSRF regeneration through Fortify. Browser access to Settings redirects after logout. |
| Forgot/reset password | Implemented with defects below | Captured mail, successful reset/login, 60-minute token expiry, one-use token and 60-second per-account resend throttle. Public POST endpoints also have a 20/minute route/IP limit. Actual external mail delivery remains a deployment gate. |
| Password confirmation | Incomplete | GET route exists but returns 500. JSON POST works and changes confirmation status; no attempt limiter is applied. No current application route requires recent password confirmation. |
| Profile Settings | Implemented | Username, optional display name/bio, account privacy, IANA timezone and Terminal Green. Server validation, atomic allowlisted save, locked owner identity, focused error summary, loading lock and saved feedback. |
| Settings unsaved changes | Deliberately unsupported | UI specification explicitly preserves no navigation guard and provides “Save your changes before leaving this page.” Browser navigation discarded an edited bio without a prompt; saved data remained. This is a documented limitation, not a regression. |
| Signed-in password change | Missing | `Features::updatePasswords()` is disabled; no password settings page or update endpoint. A user currently needs to log out and use recovery. |
| Login email / registration-name changes | Missing | Profile saves allowlist only six profile/preference fields. Display name is separate from registration name. No identity-change workflow exists. |
| Account deletion | Missing | No UI, action or route. Domain ownership FKs such as `habits.user_id` restrict deletion; a future feature needs explicit transactional handling of activity, relationships, sessions and reset tokens. Directly deleting a user is not a completed deletion design. |
| Session/device list and revoke other sessions | Missing | Database sessions exist, but no account-security UI or revocation action exists. Password-reset session defect below is separate. |
| Email verification, 2FA, passkeys | Configured off / unsupported | Fortify enables only registration and password reset; User does not implement email verification or two-factor behavior. The unused recovery-code Blade component does not constitute a working 2FA feature. No product decision promising these features was found; do not describe them as implemented or intentionally excluded forever. |
| Avatar / additional themes | Intentionally outside current requirements | Avatar is optional; current profile contract permits only Terminal Green. |

## Confirmed findings, in severity order

### High — AUTH-01: password recovery leaves existing sessions authorized

Reproduction: create a synthetic account, sign into a second independent browser context, log out of the first context, request and complete a password reset there, then revisit Settings in the second context. The second context returned **200**, rendered Settings and successfully saved a changed bio. This was a genuine cookie/database session, not PHPUnit `actingAs` state.

Impact: resetting a compromised password does not remove an already signed-in intruder's access. The old Remember me cookie *without* its session was rejected (302 to login), so persistent-cookie rotation works but active-session revocation does not.

Cause: `app/Actions/Fortify/ResetUserPassword.php` updates the hash; Fortify's `CompletePasswordReset` rotates the remember token. Neither deletes existing session records. `bootstrap/app.php` and the authenticated route group do not apply password-hash session authentication (`auth.session`).

Recommendation: define reset recovery as invalidating existing sessions, implement it using framework-native session authentication and/or explicit revocation appropriate to the supported session driver, and cover existing sessions, remembered sessions and stale Livewire requests. Avoid assuming token rotation revokes database sessions.

### Medium — AUTH-02: password-confirmation GET returns HTTP 500

Signed-in `GET /user/confirm-password` returned **500** with the generic error page at both widths. The server reported that `Laravel\Fortify\Contracts\ConfirmPasswordViewResponse` is not instantiable. `app/Providers/FortifyServiceProvider.php:50` binds the four guest views but no confirmation view. Fortify registers confirmation routes independently of optional feature switches.

Recommendation: provide a complete confirmation screen and bind its response, or deliberately remove the unused surface. The screen is not linked from today's Settings, which limits current user impact; it still must not be treated as implemented.

### Medium — AUTH-03: password-confirmation POST lacks throttling

Using an authenticated synthetic session and valid CSRF token, **21 consecutive incorrect passwords all returned 422**, with no 429. A correct password returned 201 and changed `/user/confirmed-password-status` from false to true. Fortify's route has authentication but no throttle; `WebSecurity` throttles only registration and the two recovery POSTs.

Impact: someone holding a session can repeatedly guess that account's password. This is not an unauthenticated login bypass. Add an account/session-aware limiter and regression checks before relying on confirmation for sensitive actions.

### Medium — AUTH-04: recovery reveals whether an email is registered

A nonexistent synthetic address received “We can't find a user with that email address.” A registered address received “We have emailed your password reset link.” The difference is directly observable without authentication. Existing rate limits reduce request volume but do not remove this email-membership disclosure.

Recommendation: normalize public recovery responses for known and unknown accounts, while retaining internal delivery/error handling and throttles. Registration's duplicate-email validation also reveals membership; review it as part of the same privacy decision. This is a hardening finding, not a claim that the product specification already mandates anonymous registration responses.

### Low — AUTH-05: recovery error clears the entered email

After an unsuccessful reset-link request, the email input was empty. Fortify flashes the email back, but `resources/views/pages/auth/forgot-password.blade.php:12` does not bind `old('email')`. Preserve the value so users can correct it. Password inputs correctly remain blank after validation errors.

### Low — AUTH-06: recovery screens lag the rest of the interface

Forgot/reset use `layouts/auth/simple.blade.php`, displaying the Laravel logo instead of habit.log's split-layout identity. They lack a main landmark/skip link; reset has no direct return-to-login link. Their primary controls measure **40px** high, versus the larger login/registration actions; the reset visibility toggles measure **32px**, and the forgot-screen login link is approximately **36 × 19px**. These are usability/consistency observations, not automatically WCAG 2.1 failures.

Recovery success text has no status/live-region semantics, and the forms lack the explicit pending/duplicate-submit feedback used by login/register. Full-page redirects and inline field errors still work. Reuse the established Terminal Green auth layout and feedback conventions; verify keyboard focus and announcements after doing so.

## Authorization, validation and sensitive data

- Route inventory confirms `web` middleware on Fortify routes, guest restrictions on login/register/recovery, and authentication on logout, confirmation and Settings. Normal CSRF middleware remains enabled; a browser logout POST without a token returned **419**, while the valid request returned **204**.
- Settings pins and rechecks `ownerId` on hydration and save. Validation uses uniqueness excluding only the authenticated user, username format/length limits, 50/160-character optional text limits, a timezone allowlist and the single allowed theme. `SaveProfile` locks the user row in a transaction and intersects the validated keys with its allowlist. Existing tests cover foreign-user isolation, logout/session switching, invalid-update atomicity and escaped output.
- Login browser attempts returned **422 × 5**, then **429**. PHP tests verified 20/minute public-auth limits, another IP's independence and expiry of the limiter. Public throttles are applied inside `WebSecurity`, so they do not appear as standalone route middleware in the route-list output.
- Passwords use the model's hashed cast and are hidden with remember tokens from model serialization. Laravel excludes current password, password and confirmation from flashed input. Reset-token expiry/reuse tests passed. Production password defaults specify 12 characters, mixed case, numbers, symbols and an uncompromised check; local browser fixtures use the non-production defaults. The production breach-check network path was not exercised.
- Session and remember cookies were HttpOnly and SameSite=Lax. XSRF-TOKEN was intentionally JavaScript-readable. Secure was false on this HTTP loopback rehearsal; production requires HTTPS and Secure cookies per `docs/DEPLOYMENT.md`. Default session lifetime is 120 minutes and confirmation lifetime is three hours. Actual idle expiry and live TLS/proxy configuration were not timed or validated.
- Sensitive-page no-store, no-referrer, nosniff and SAMEORIGIN checks passed. SQL/binding redaction tests passed. Test mail was captured only by the isolated harness; no real email was sent, and token-bearing logs/traces were not added to the report or Git. Production transport and upstream log handling remain deployment checks.

## UI and accessibility observations

Login and registration present the established habit.log brand and Terminal Green styling. Settings uses the shared page alignment, desktop columns and stacked mobile cards. Field errors, save success, timezone/privacy persistence and duplicate-save prevention passed. The settings keyboard tests verified focus below the sticky navigation, keyboard disclosure/checkbox operation and focused validation summary. Guest Tab traversal found visible focus styling on the inspected controls.

The review captured **30 distinct state/viewport screenshots**, each at either **1440 × 960** or **390 × 960** before full-page expansion. No document horizontal overflow occurred. Axe scans of those states found **zero violations in the selected WCAG 2 A/AA and 2.1 AA tags**. This does not prove complete accessibility: missing landmarks and smaller targets remain observations, and a generic 500 screen can pass an automated scan while being functionally broken. Representative desktop/mobile normal, validation, confirmation-error and success screenshots were visually inspected.

## Recommended implementation order

1. Fix active-session revocation after password reset and add a real two-session regression.
2. Complete or remove the confirmation surface, including rate limiting, and test it before adding sensitive account actions.
3. Normalize recovery disclosure and fix email retention; bring recovery branding, navigation, feedback and touch targets into line with login/register.
4. Scope signed-in password change and login-email changes, with current-password/recent-confirmation requirements, verification where appropriate and explicit session invalidation behavior.
5. Design confirmed account deletion and its transactional data/session/token cleanup; add device/session revocation controls. These are new feature work, not small visual fixes.
6. Make explicit product decisions on email verification, 2FA/passkeys and optional Settings draft protection. Preserve current documented behavior until a change is authorized.

## Verification and artifacts

All database-mutating checks used the existing guarded `habit_log_test` harness and its mutual-exclusion lock, synthetic accounts and normal middleware. No development/production database was reset. Installed Windows Chrome was used from WSL; no browser libraries were installed.

| Command / check | Result |
| --- | --- |
| `php artisan test tests/Feature/Auth tests/Feature/Settings --compact` | **66 passed / 336 assertions**; `storage/app/auth-account-review/php-tests.log` |
| `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- --config storage/app/auth-account-review/playwright.config.js` | Existing auth/settings tests plus guest inspection passed. Two temporary inspection scenarios initially timed out on incorrect selectors; those were harness errors, not app failures. |
| Same browser command with `--grep 'inspection: real-session\|inspection: settings'` | Both corrected scenarios passed; `browser-followup.log`. Passing here means the inspection recorded current behavior, including the defects above. |
| Same browser command with `--grep 'inspection: auth layout'` | Passed; `browser-layout-final.log`; restored guest geometry/keyboard observations after an earlier worker restart replaced the collection. |
| `vendor/bin/pint --test` | Passed; `pint.log` |
| `npm run build` | Passed; `build.log` |
| Git diff / status | No application or pre-existing tracked file changes; only this report is untracked. Generated artifacts are ignored. |

**Five distinct browser scenarios completed successfully across these runs**, with no skipped scenarios. The temporary inspection source/config and merged observations remain in ignored `storage/app/auth-account-review/`; existing tracked tests were unchanged. `observations.json` records statuses, geometry, selected axe results and keyboard details without cookie values or reset tokens. It contains repeated measurements where states were revisited; count distinct state/width pairs when checking the 30-state total.

Screenshot directory: `storage/app/auth-account-review/`. Every following basename has both `-1440.png` and `-390.png`:

- `login`, `login-error`, `register`, `register-error`
- `forgot-password`, `forgot-unknown`, `forgot-success`, `forgot-throttled`
- `reset-password`, `reset-error`, `reset-success-login`
- `confirmation-route`
- `settings`, `settings-error`, `settings-saved`

Representative images: [desktop login](../storage/app/auth-account-review/login-1440.png), [mobile recovery](../storage/app/auth-account-review/forgot-password-390.png), [confirmation failure](../storage/app/auth-account-review/confirmation-route-390.png), [desktop settings](../storage/app/auth-account-review/settings-1440.png), [mobile settings error](../storage/app/auth-account-review/settings-error-390.png).

Limits: focused inspection, not full application acceptance or a production penetration test. Other engines, physical devices/on-screen keyboards, manual screen readers, timed session expiry, real mail delivery and production HTTPS/proxy behavior were not verified. Source-of-truth review found no conflicting instruction requiring a stop. The identified bugs remain unfixed by design because this task authorizes inspection only.

## Authentication security fixes — implementation

The subsequent authorized task fixes AUTH-01 through AUTH-06 without adding password/email changes, account deletion or a session-management UI. The original inspection above is preserved as historical evidence.

| Root cause | Implemented correction | Main files |
| --- | --- | --- |
| Reset changed credentials but left active sessions; no session password-hash verification | Transactional password/remember-token update and deletion of that user's database sessions; Laravel AuthenticateSession in the web group, including Livewire. Invalid resets leave sessions unchanged. | `app/Actions/Fortify/ResetUserPassword.php`, `bootstrap/app.php` |
| Fortify registered a confirmation route with no view binding; POST had no throttle | Bound Terminal Green confirmation view; five requests/minute per user across IPs; password string validation; existing confirmation timestamp, timeout and intended destination retained. | `app/Providers/FortifyServiceProvider.php`, `app/Http/Middleware/WebSecurity.php`, `resources/views/pages/auth/confirm-password.blade.php` |
| Distinct known/unknown/resend-throttled responses revealed email membership | Fortify response bindings give the same HTML redirect/status or JSON 200/message for these normal link-request outcomes. Unknown-email and invalid-token reset failures also match. Valid-account mail delivery and broker validation/throttles/timebox remain. Unexpected errors and mail exceptions propagate. | `app/Http/Responses/RecoveryLinkResponse.php`, `app/Providers/FortifyServiceProvider.php` |
| Recovery ignored flashed email and used the starter layout | Bind old email, retain blank passwords, use existing split auth layout, habit.log identity, main/skip navigation, return links, error summaries, status feedback and disabled pending actions. | `resources/views/pages/auth/forgot-password.blade.php`, `resources/views/pages/auth/reset-password.blade.php` |

Regression source: `tests/Feature/Auth/RecoverySecurityTest.php` adds ten checks for HTML/JSON enumeration resistance, invalid-token parity, preserved email/no flashed password, legacy and ordinary session deletion scoped to one user, failed-reset preservation, stale Livewire read/write rejection, confirmation authentication/validation/expiry/intended flow, per-user throttling and unsuppressed transport failure. `tests/Browser/auth-security.spec.js` adds three scenarios exercising actual independent browser contexts, old remembered cookies, stale snapshots, fresh-login usability, recovery UI and confirmation. The existing auth browser scenario now expects the generic link-request message.

Documentation changes: this review plus `ARCHITECTURE.md`, `SECURITY_AND_PRIVACY.md` and `UI_UX_SPEC.md`. No schema, dependency, ownership-policy, habit/activity, journal or Settings behavior changes were required. No migrations are needed.

The effective inspected local session configuration is `database`, table `sessions`, on the default database connection. Database deletion covers sessions created before password-hash markers were introduced. Hash checking also rejects stale sessions if an in-flight request writes an old session back. Changing the session driver or moving sessions to another connection requires rechecking rollout revocation and cross-connection transaction behavior; this change does not claim atomic transactions across separate databases.

Requests already authorized before reset and data already delivered cannot be recalled. Fresh requests and stale Livewire updates after reset must reauthenticate. The browser regression accepts 419 (deleted session loses its CSRF state) or 401 for stale updates and asserts that no private data is returned or changed; the PHP hash-mismatch regression specifically proves 401 before hydration, independently of CSRF.

Recovery response parity covers normal registered/unregistered and resend-throttled outcomes. The existing broker timebox is retained; slow synchronous mail may exceed it, so constant-time network delivery is not claimed. Mail exceptions remain real failures and are tested as such. Registration's existing duplicate-email behavior is unchanged. Production SMTP/TLS/proxy checks, other browser engines, physical devices and manual screen-reader acceptance remain deployment/verification limits.

### Fix verification results

All resource-intensive runs were sequential, using the existing guarded `habit_log_test` database and normal middleware. Existing work was preserved. Results on 2026-10-25:

| Verification | Result |
| --- | --- |
| `php artisan test tests/Feature/Auth tests/Feature/Settings --compact` | 75 passed / 426 assertions before the additional mail-exception regression; that regression subsequently passed in full CI. |
| Focused installed-Chrome browser run | Five distinct scenarios passed across the initial run and a targeted rerun. Two new status locators initially matched both feedback and the Flux toast; narrowing the test locator fixed those harness failures without application changes. |
| `composer ci:check` | Pint passed; **456 PHP tests / 5,213 assertions passed**, including all ten new PHP regressions. |
| `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e` | **58 passed**, no retries or skips, in 5.3 minutes. Includes all three new security scenarios and existing login/registration/logout, journals, tracking, ownership, shared privacy and focus checks. |
| `npm run build` | Passed. |
| `composer audit --locked` and `npm audit` | No advisories / zero vulnerabilities. |
| `docker build --target production -t habit-log:auth-security-check .` | Passed; separate local test image, not deployed. |
| `sh tests/Containers/smoke.sh habit-log:auth-security-check` | Passed: non-root, runtime-only files, health/login/assets, document-root isolation, CSRF and log privacy. Disposable container removed by the harness. |
| JavaScript syntax / `git diff --check` / source hashes | Passed; verified implementation and regression files unchanged after the completed suites. |

Final screenshots are in `storage/app/auth-security-fixes/`. There are **14 screenshots**: each of `forgot-password`, `forgot-validation`, `forgot-success`, `reset-password`, `reset-validation`, `confirm-password`, and `confirm-error` at both `-1440.png` and `-390.png`. All 14 captured states passed document-overflow, main-landmark, primary-action-height (at least 44px), and selected WCAG 2 A/AA + 2.1 AA axe checks. Representative screenshots were visually inspected; this is not a manual screen-reader certification.

Examples: [mobile recovery validation](../storage/app/auth-security-fixes/forgot-validation-390.png), [desktop generic recovery feedback](../storage/app/auth-security-fixes/forgot-success-1440.png), [mobile reset validation](../storage/app/auth-security-fixes/reset-validation-390.png), [desktop confirmation](../storage/app/auth-security-fixes/confirm-password-1440.png), [mobile confirmation error](../storage/app/auth-security-fixes/confirm-error-390.png).

Command logs and tested-source hashes remain in ignored `storage/app/auth-security-fixes/`; generated mail, tokens, screenshots and logs are not source changes. The test harness stopped its preview server. No packages or browser libraries were installed. Hosted Actions was not triggered because committing/pushing was forbidden; the local CI commands, full browser suite, dependency audits and container checks passed.

Suggested future commit message: `fix(auth): revoke reset sessions and harden account recovery`.
