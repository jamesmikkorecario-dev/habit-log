# Email verification investigation

## Application-level resolution

The user authorized relative registration verification signatures after the initial investigation below. The implementation now signs the relative path/query, prefixes validated canonical HTTPS `APP_URL` for email delivery, and validates with native `signed:relative`. Registration verification works over the internal HTTP hop with an empty trusted-proxy list. Canonical hostname admission, expiry, authenticated account/email matching, locked identity checks and existing safeguards remain required. Password reset and signed email-change behavior are unchanged. See [ADR-0016](adr/ADR-0016-RELATIVE-VERIFICATION-SIGNATURES.md).

Fortify's installed route file hardcodes `signed` without a signature-mode configuration hook. The application now uses `Fortify::ignoreRoutes()` and owns the enabled route declarations with native Fortify controllers. This gives `verification.verify` its relative middleware at registration, without duplicate route names, late middleware edits or a global signature-alias override. The route-parity regression compares all enabled Fortify route metadata to the package, allowing only the relative verification middleware difference.

The original support-dependent option and its verification records are preserved below as investigation history. Provider support is no longer a prerequisite for this registration verification fix. General proxy/IP/HTTPS redirect configuration and the unchanged absolute email-change flow retain their separate deployment requirements. Deployment and live acceptance have not been performed.

## Relative-signature changes and validation

Source changes are limited to the registration notification callback in `app/Providers/AppServiceProvider.php`, explicit Fortify route ownership in `app/Providers/FortifyServiceProvider.php` and `routes/fortify.php`, and regression tests in `EmailVerificationTest`, `FortifyRouteRegistrationTest` and the existing canonical-URL assertion in `ResetDeploymentSecurityTest`. `CredentialUrls.php`, proxy/Host middleware, the global `signed` alias, password-reset/email-change generation and validation, and all account/session/recovery actions remain unchanged. Documentation updates cover architecture, security, deployment and the ADR-0015/0016 decision transition. Previous worktree changes, including the frozen-clock test correction and initial investigation, are retained. No migration is required.

Verification uses the same WSL `Ubuntu-24.04` temporary Linux-filesystem copy at `/tmp/habit-verification.YlyOjE`, with application/routes/tests compared against the Windows worktree and the same locked dependencies/configuration. The test database remains the explicit local `habit_log_test`, with guarded `habit_log_recovery_test` for restore tests; later nonzero row counts are synthetic fixtures from these runs. Browser setup independently checks its explicit opt-in, database identity and uncached configuration before resetting. No production account, database or credentials are used.

- Before application edits, `php artisan test --filter=test_absolute_signature_scheme_mismatch_is_solved_by_relative_signing` passed five assertions reproducing the absolute failure and relative success with an untrusted HTTP backend request.
- Focused `php artisan test --filter=Auth`: 160 passed / 1,684 assertions before the final additional tampering cases. The filter also selects a few authentication-related tests outside the Auth directory.
- `php artisan route:cache --env=testing`, then `php artisan test --filter=EmailVerificationTest`, followed by `php artisan route:clear --env=testing`: **59 passed / 335 assertions**, including valid, expired, signature/path/query-tampered, missing-signature, wrong-account/email, wrong-host/subdomain, trusted/untrusted reverse-proxy, replay, old-link/resend, CSRF and stale-identity cases. Cached and uncached Fortify route metadata matches. The first whole-table string comparison differed only because caching assigns generated names to otherwise unnamed framework routes.
- The first relative-signature `composer ci:check` run had **622 passed, one failed / 9,223 assertions**. The unchanged social lock-wait test received its expected MySQL error 1205 but measured 0.1957 seconds against its 0.8-second minimum. Its focused retry (`--filter=test_absent_pair_creation_waits_for_user_lock`) passed all eight assertions. No social code, test assertion or database setting was changed to obtain a pass.
- Final `composer ci:check` on the identical Linux-filesystem source: **623 passed / 9,224 assertions**, 394.21 seconds, exit 0; Pint passed all **233 files**. This includes the unchanged social concurrency test. It ran after browser coverage released the shared test database. The first-run timing failure remains recorded above.
- `composer audit --locked` and `npm.cmd audit`: clean. `npm.cmd run build`: passed.
- `HABIT_LOG_E2E=1 E2E_BROWSER_EXECUTABLE=/home/james/.cache/ms-playwright/chromium-1254/chrome-linux64/chrome npm run test:e2e`: cache-isolation unit check and **89 browser scenarios passed** (8.5 minutes). This includes real registration verification, email change, password reset/change, session revocation and account deletion with ordinary CSRF/session middleware and synthetic mail capture.
- `docker build --target production -t habit-log:email-verification-local .` from the Windows worktree via WSL: passed. `sh tests/Containers/smoke.sh habit-log:email-verification-local`: passed runtime-only/non-root packaging, Host checks, cached config/routes/views, CSRF, credential/mail/log privacy and recovery quarantine. The disposable container has no database access or production secrets.
- Final `git diff --check`: passed. The branch remains `fix/email-verification-win`; no commit, push, PR, merge or production deployment was performed. Existing worktree changes were preserved.

Review against `DESIGN_VERIFICATION.md`: the authorized signature-mode change preserves owner/email matching, expiry, exact canonical hostname admission, verification gates, limits, private defaults, raw records, transactions and stale-identity/deletion protection. No UI, schema, recovery policy or unrelated credential security changes were introduced. Native relative signing replaces scheme-dependent signature input; it does not treat forwarded headers as trusted or replace external HTTPS enforcement. The explicit route declarations and their upgrade/parity-test maintenance requirement are documented in ADR-0016.

Suggested commit message for manual use: `fix: use relative signatures for registration verification`.

## Evidence

On 2026-11-14, a credential-free GET to `https://habit-log.ethiodeploy.app/email/verify` returned 302 with `Location: http://habit-log.ethiodeploy.app/login`. A separate `/dashboard` probe returned 503; that availability result does not identify the verification failure. No production account, database, credentials or signed links were accessed.

The owner reports `APP_URL=https://habit-log.ethiodeploy.app` and no explicitly configured `TRUSTED_PROXIES`. The repository defaults to an empty trusted-proxy list. Outgoing registration verification uses `CredentialUrls`, a reset clone of Laravel's URL generator pinned to that HTTPS origin. Fortify's `signed` middleware checks the absolute incoming URL before its request checks authenticated account ID/current email hash. The model then checks fresh recovery identity and credentials under the existing user lock. The retry message intentionally covers all 403s; it alone is not signature-failure evidence.

The observed HTTP redirect establishes incorrect scheme reconstruction on the public HTTPS verification-notice request. The local regression exercises the resulting signature mismatch: an HTTPS-signed notification arriving over backend HTTP fails with no trusted peer, succeeds through the explicitly trusted TLS ingress, and still rejects expired/tampered links. Production backend headers and peer addresses remain unknown; the exact ingress configuration must be confirmed before changing deployment settings.

EthioDeploy's public [Laravel deployment guide](https://ethiodeploy.com/deploy/laravel), checked on 2026-11-14, independently documents proxy trust as necessary for HTTPS URL generation and says configuration is cached on startup, so environment changes require redeployment. Its generic example uses wildcard proxy trust; that conflicts with this project's bounded-trust requirement in ADR-0013 and is not an acceptable patch here. The reviewed Laravel, environment-variable and full documentation pages do not supply the immediate ingress IPs/CIDRs needed by this application. The provider's [FastAPI guide](https://ethiodeploy.com/deploy/fastapi) also describes TLS termination at the platform proxy with HTTP forwarding and `X-Forwarded-Proto`; that platform description corroborates the observed scheme defect but does not prove this project's exact headers or network isolation.

## Initial proxy-configuration option (historical)

Ask EthioDeploy support for the following, without sending a `.env`, SMTP settings, application key, session cookies, query strings or verification links:

- Does this service terminate TLS at an ingress and forward HTTP to Apache on port 8080?
- What immediate peer IPs or bounded CIDRs does PHP receive in `REMOTE_ADDR`, and are those peers isolated from untrusted clients?
- Does that ingress overwrite client-supplied `X-Forwarded-Proto` and `X-Forwarded-Port` with `https` and `443` for this domain, preserve `Host: habit-log.ethiodeploy.app`, and preserve the original path/query?
- Are there multiple ingress hops or replicas, and is this header contract consistent across them?

Set `TRUSTED_PROXIES` to only the confirmed immediate trusted ingress IPs/CIDRs (comma-separated). Keep `APP_URL=https://habit-log.ethiodeploy.app`. Do not guess Cloudflare ranges from the public response: Cloudflare may not be PHP's immediate peer. Do not use `*`, arbitrary private networks, or change `APP_KEY`. If headers are absent or incorrect, the provider must correct ingress forwarding; trusting an address cannot create missing HTTPS metadata.

Refresh deployment configuration caches and restart serving processes using the provider's normal release workflow. Check a guest HTTPS `/email/verify` request now redirects to HTTPS login, then use one synthetic account and a newly delivered link to confirm successful verification through the real ingress. Check expired/tampered links still fail. Do not publish signed query strings or cookies in logs/screenshots. No migration, account backfill, signature bypass, global forced scheme, credential rotation, or change to session/deletion/recovery safeguards is needed.

At this initial stage, the worktree added regression coverage and deployment guidance and could not apply the hosting setting without the verified ingress contract. The subsequent relative-signature resolution above removes that dependency for registration verification. Production deployment/acceptance still belongs to the operator.

## Initial investigation changes and verification (historical)

Starting state: clean `fix/email-verification-win` at `25354d9`, Windows worktree `C:/Users/James/orca/workspaces/habit-log/fix-email-verification-win`. WSL distribution: `Ubuntu-24.04`. No commit, push, PR, merge or deployment was performed.

Changed files are `tests/Feature/Auth/EmailVerificationTest.php`, `docs/DEPLOYMENT.md` and this report. The test adds notification-generated HTTPS verification through an explicit proxy, covering valid, expired, tampered and untrusted-peer requests. Existing direct-link, ownership, replay, stale credential/deletion, CSRF and access-gate cases remain. The registration expiry test now freezes its clock: the first focused run exposed a one-second assertion race (48 passed, one failed), unrelated to production signature validation. All 49 verification cases pass in the final full run.

Before reset-dependent tests, read-only checks confirmed the local `127.0.0.1:3306` server has distinct `habit_log_test` and `habit_log_recovery_test` schemas, with zero users/habits/journals in both, and zero sessions/activity entries in the primary test schema. Checked `.env.testing`, PHPUnit overrides, the pre-reset guard in `Tests\TestCase`, and the recovery target's explicit database-name guards. No development database was selected for tests; no production database or credentials were accessed.

Commands/results:

- `composer install --no-interaction --no-scripts --prefer-dist` via WSL: installed the locked dependencies, without setup/migration scripts.
- `php artisan test --filter=EmailVerificationTest` on the Windows mount: all four new proxy cases passed; the existing clock assertion race above was corrected before final CI.
- `composer ci:check` on the Windows mount: Pint passed all 231 files; the test run was interrupted after concurrency failures. Identical source was rerun on the Linux filesystem without changing test timeouts.
- `wsl -d Ubuntu-24.04 --cd /tmp/habit-verification.YlyOjE -- composer ci:check`: **passed**, 612 tests / 9,163 assertions, 366.28 seconds; Pint passed all 231 files. This temporary copy uses the same checked local synthetic test databases. Application, tests, configuration, database, routes, resources and bootstrap source were compared against the Windows checkout; Composer lock and PHPUnit configuration hashes also match. Dependencies were installed from the same lock into the temporary copy.
- `composer audit` via WSL: no security vulnerability advisories.
- `npm.cmd ci --ignore-scripts`, `npm.cmd audit`: successful, zero vulnerabilities.
- `npm.cmd run build`: passed after PHP dependencies supplied Flux's stylesheet. An earlier build before those dependencies finished failed for that missing asset.
- `git diff --check`: passed.

Review against `DESIGN_VERIFICATION.md`: product access/ownership checks, private defaults, raw activity, transactions, CSRF, session/deletion/recovery safeguards and UI are unchanged. There is no migration or schema/boundary change requiring a new ADR. Regression tests and deployment troubleshooting guidance are the only repository changes. The production ingress correction and live acceptance are outstanding, rather than represented as completed by local tests.

Suggested commit message, for the owner to use manually: `test: cover email verification behind trusted TLS ingress`.
