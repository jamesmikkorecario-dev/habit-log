# Registration email verification

Implemented and locally verified 2026-11-14. Target-host deployment and acceptance are separate and have not been performed.

## Behavior and security

Fortify owns registration, verification notification dispatch, authenticated notice/resend/verification routes, signed-link authorization and verification events. Registration leaves `email_verified_at` null and sends Laravel's verification notification. Links expire after 60 minutes by default and are generated from validated canonical `APP_URL`, including the configured HTTPS port, independently of incoming Host/forwarded headers.

Native `verified` middleware protects dashboard, tracking, journal, habits, categories, people and Settings reads/writes. It is explicitly persisted for Livewire updates. The existing route-aware web security middleware applies the same restriction to Fortify's separate password-update/confirmation endpoints. The verification process and logout remain available without verification. JSON application access is denied with 403; HTML navigates to the notice.

The notice reuses Terminal Green's split auth layout, shows only the current owner's escaped email, explains expiry/retry, and provides CSRF-protected resend/logout controls. Resend announces and focuses success, prevents repeated pending submissions, and shares a six-per-minute account limit with verification. Invalid, expired, foreign-owner and changed-email links return safe 403 retry guidance. Valid replay is idempotent: it does not rewrite verification time or dispatch another Verified event. Earlier unexpired resend links remain valid, following Fortify's standard protocol; the existing Settings email-change protocol retains its separate replacement/single-use rules.

The timestamp mutation locks the fresh user and checks email, admitted password hash and immutable recovery UUID. A missing, changed or deleted/replaced identity is rejected. A second admitted completion preserves the first timestamp. Production notification delivery uses the existing safe-mail manager and sanitized exceptions; registration preflights origin/transport after throttling, before account creation. Password reset does not verify an address. Existing email-change, session, deletion and managed recovery controls remain in place.

## Data and deployment

There is **no migration, backfill, production mutation or bulk email campaign**. Existing non-null verification timestamps retain their meaning. Existing null-timestamp owners must sign in and verify their current mailbox before using the application, including Settings. A mail failure after registration can leave an unverified account: sign in and resend instead of registering again. Mistyped or inaccessible mailboxes require an approved support process; do not silently mark addresses verified as a rollout workaround.

Deploy the full release with the normal cached configuration/routes/views and PHP-process refresh. Preserve the stable application key and existing session/recovery settings. Confirm canonical HTTPS `APP_URL`, trusted proxy behavior, allowed mail configuration and upstream logs that omit signatures/credential URLs. Previously verified Gmail delivery is not evidence that this new route and signature work through the deployed ingress. Run the controlled registration/resend/link/replay/gate/legacy-owner smoke checks in [the deployment guide](DEPLOYMENT.md#acceptance-after-deployment).

Only the physically verified `habit_log_test` and `habit_log_recovery_test` databases were used for reset-dependent testing, under existing environment/configuration/identity guards and the shared lock. Browser mail is captured locally for synthetic accounts; helpers follow real signed links and never change verification timestamps directly. Development/production data, existing backup artifacts and unrelated changes were preserved. `docs/ACCOUNT_DELETION_REPORT.md` retains its original SHA-256 checksum, `71f4ba4ff33e9aa6709eb60b279f37dd978d7f56759bf019fc4e9b42567d49e1`. No commit, push, PR or merge was performed.

## Verification

| Command | Result |
| --- | --- |
| `php artisan test --compact --filter='EmailVerificationTest\|ResetDeploymentSecurityTest\|RegistrationTest\|DatabaseCacheTest\|EmailChangeTest\|SessionBoundaryTest'` | **81 passed / 726 assertions**, 6.44 seconds. |
| `composer ci:check` | **Passed**, including Pint and the full **608-test / 9,134-assertion** suite, 427.50 seconds for tests. Local output: `/tmp/habit-log-registration-verification-ci.log`. |
| `composer audit --locked` | **No security vulnerability advisories found**. |
| `npm audit` | **0 vulnerabilities**. |
| `npm run build` | **Passed**. |
| `git diff --check` | **Passed**. |
| Isolated browser registration/auth/verification/isolation specs | **7 passed**, 16.7 seconds. |
| Isolated browser People/social specs | **3 passed**, 34.9 seconds. |

Browser commands use `E2E_BROWSER_EXECUTABLE=/home/james/.cache/ms-playwright/chromium-1254/chrome-linux64/chrome HABIT_LOG_E2E=1 npm run test:e2e --` followed respectively by `tests/Browser/email-verification.spec.js tests/Browser/auth-design.spec.js tests/Browser/auth.spec.js tests/Browser/registration-isolation.spec.js` and `tests/Browser/people-design.spec.js tests/Browser/social.spec.js`. Logs are `/tmp/habit-log-email-verification-browser.log` and `/tmp/habit-log-email-verification-social-browser.log`. The runner's cache-isolation guard also passed. Notice screenshots are ignored local artifacts at `storage/app/email-verification-checks/notice-390.png` and `notice-1440.png`; axe and no-overflow checks passed at 320, 390, 768 and 1440px. Chromium was locally installed; the bundle's expected revision was unavailable. Other engines, physical devices, manual screen readers and target-host SMTP/ingress remain unverified.

Initial feature failures were test-fixture normalization and missing production-mode CSRF tokens; both were corrected without weakening middleware. The initial browser launch lacked the expected executable; the installed executable was selected explicitly. A browser status assertion initially matched both the notice and the shared toast region and was scoped to the form. The first full CI run ended with SIGTERM before completing; its process was confirmed stopped and its lock released before retry, with partial output preserved at `/tmp/habit-log-registration-verification-ci-interrupted.log`.

The former email-change test asserting no global verification gate now uses a verified actor for its existing validation/ownership cases. The new verification suite separately proves unverified Settings requests are denied without mutations. Existing account, session and recovery safeguards were not weakened to obtain passing results. Review against `AGENTS.md`, design guardrails and `DESIGN_VERIFICATION.md` found no remaining implementation violation within this scope; the external rollout and platform coverage limits above remain.

## Modified files

| Area | Files |
| --- | --- |
| Fortify/model/mail integration | `config/fortify.php`, `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`, `app/Providers/AppServiceProvider.php` |
| Gates and error rendering | `routes/web.php`, `app/Http/Middleware/WebSecurity.php`, `bootstrap/app.php` |
| Notice | `resources/views/pages/auth/verify-email.blade.php` |
| Feature tests | `tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/EmailChangeTest.php`, `tests/Feature/Auth/ResetDeploymentSecurityTest.php`, `tests/Feature/DatabaseCacheTest.php` |
| Browser tests | `tests/Browser/support.js`, `tests/Browser/auth-design.spec.js`, `tests/Browser/people-design.spec.js`, `tests/Browser/social.spec.js`, `tests/Browser/email-verification.spec.js` |
| Source-of-truth and deployment docs | `docs/PRODUCT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE_DESIGN.md`, `docs/UI_UX_SPEC.md`, `docs/ROADMAP.md`, `docs/SECURITY_AND_PRIVACY.md`, `docs/TESTING_STRATEGY.md`, `docs/DEPLOYMENT.md` |
| Decision/report | `docs/adr/ADR-0015-REGISTRATION-EMAIL-VERIFICATION.md`, `docs/REGISTRATION_EMAIL_VERIFICATION_REPORT.md` |

The pre-existing account-deletion report changes and four `public/*:Zone.Identifier` files are unrelated and untouched. Suggested commit message, for the user's manual Git operations: `feat: require registration email verification with Fortify`.
