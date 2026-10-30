# Deployment remediation 1 — password-reset security

Status: application remediation, local verification and final security review complete on 2026-10-30. Authorized for the dev → main PR closeout; not production deployment approval. QA-04/05's observed application exposures are resolved by the changes below, while target ingress/mail/log verification remains open.

Started on synchronized `dev` at `030f24fb0a66bafaf2ebe25005ba779bcf2a0687`, matching remote `dev` and `main`. The implementation phase introduced no migrations, dependencies, domain data changes or backup mutation and performed no publication. The subsequent closeout explicitly authorizes the reviewed security changes and supporting audit/remediation documents to be committed, pushed to `origin/dev` and opened as one dev → main PR. The unrelated account-deletion policy recheck remains local and uncommitted. Merge, deployment and backup deletion remain unauthorized.

## Root causes and resulting behavior

**QA-04:** Laravel's default reset notification used the request URL generator; signed email-change links used the same request authority. Setting `APP_URL` alone was insufficient, and trusted hosts were not configured. `CredentialUrls` now uses a validated configured origin and a separate cloned/reset Laravel generator for reset and signed email-change links. Production requires HTTPS; local/test HTTP and ports work. The helper does not modify the shared request URL generator. Current signed email links retain Laravel's absolute signature, expiry and owner checks. No active registration-verification flow exists to change; password change/session/account-deletion flows send no additional credential links.

Laravel's trusted-host middleware now admits only the exact configured hostname outside local/testing. Explicit `TRUSTED_PROXIES` addresses/CIDRs may supply scheme, port and client IP; Host, forwarded Host/prefix and standard Forwarded cannot substitute an arbitrary credential-link origin. Unknown Host is rejected by Laravel even if a forwarded host claims the approved domain. The container health probe uses the configured Host against loopback. Web-server/static-file host rejection and actual proxy/TLS behavior still require deployment verification.

**QA-05:** Reset notifications lacked the email-change-only production mail guard. Mail URLs can override configured transport names; Symfony composite transports also log failed-attempt exceptions/debug. `SecureMailManager` now validates the resolved transport graph, including `MAIL_URL`, nested failover/round-robin, empty/missing/cyclic configurations and unsupported transports. Production log/array delivery is rejected. Safe delivering composites remain supported; their internal exception logging is disabled. `PrivateTransport` replaces failures with a fixed `ProductionMailFailure`, without a previous exception or copied diagnostic text. The application logs only a fixed terminal error, not transport details or mail content.

Reset preflight runs after existing throttling and before user lookup/token creation. Unsafe mail configuration yields the same generic 503 for known and unknown addresses and creates no token. A later delivery failure is a sanitized 503; an already-created reset token remains governed by existing expiry/throttle rules. Direct reset notifications are guarded as well, including rendering/notification failures. Successful known/unknown/recently requested feedback is unchanged. Email request/resend retains its existing retryable delivery error and pending generation; a failed old-address alert does not reverse a confirmed change. Local/test log/array workflows remain available for synthetic data.

## Files and architecture

- `app/Auth/CredentialUrls.php`, `AppServiceProvider`, `EmailChanges`: canonical reset and signed confirmation URLs.
- `app/Mail/{SecureMailManager,PrivateTransport,ProductionMailFailure}.php`, `User`, `EmailChangeController`, `WebSecurity`, `bootstrap/app.php`: production transport validation, preflight, safe failure reporting and host/proxy boundaries.
- `config/trustedproxy.php`, `.env.example`: explicit ingress configuration with no wildcard or implicit platform trust.
- `Dockerfile`, `docker/healthcheck.sh`, `tests/Containers/smoke.sh`, `tests/Fixtures/production-mail-security.php`: health-probe compatibility and database-free production runtime regression.
- `tests/Feature/Auth/ResetDeploymentSecurityTest.php`, `EmailChangeTest`: malicious Host/forwarded headers, URL/signature checks, unsafe mail graphs, privacy canaries, safe failover, throttle/feedback and local compatibility.
- QA report, remediation plan, deployment/Docker/security/architecture docs and [ADR-0013](adr/ADR-0013-CREDENTIAL-DELIVERY-SECURITY.md): current behavior, evidence and target prerequisites.

No database or authorization-policy changes. Existing authentication, CSRF, ownership, session revocation, account deletion and private activity boundaries remain in force. The approved heatmap and UI are unchanged.

## Verification

All destructive fixtures use guarded `habit_log_test` and synthetic accounts; commands run sequentially. Mail privacy tests capture in-memory records and print only assertions/outcomes, never generated secrets or message bodies. Container fixtures use unsaved synthetic users without database access or external mail. Evidence logs remain ignored under `storage/app/reset-security/`.

The initial focused check exposed an early bootstrap configuration lookup; it was corrected to use Laravel's runtime proxy-config lookup. The subsequent focused suite passed **33 tests / 459 assertions** in 7.70 seconds. It covers reset/email/recovery behavior and twelve initial deployment-security scenarios. The first full run exposed a test-isolation issue: a prior production test left Symfony's trusted-host state set, so the direct hostile-request URL probe failed before exercising the helper. Resetting static request trust at the test boundary corrected the probe; application protections were not relaxed. A follow-up adds a thirteenth scenario exercising signed email confirmation through an explicitly trusted TLS proxy; the 24-test email/security run passed 372 assertions in 3.95 seconds.

| Final sequential command | Result |
| --- | --- |
| `php artisan test --compact` | **522 passed / 7,435 assertions**, 170.31 seconds. |
| `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e` | **88 passed**, 496.39 seconds; 0 skipped, unexpected or flaky. Runner cache-isolation check passed. |
| `vendor/bin/pint --test` | **Passed**. |
| `npm run build` | **Passed**, unchanged frontend asset hashes (`app-CCp2_e7D.js`, `app-D4SpvuKy.css`). |
| `composer audit --locked` | **No security vulnerability advisories found**. |
| `npm audit` | **0 vulnerabilities**. |
| `docker compose config --quiet` | **Passed**. |
| `docker build --target production -t habit-log:reset-security .` | **Passed**; image manifest `sha256:5eb6f4133ee4d7253e2d513b78daa0abe1bce8cce21fb100990224cf06476574`. |
| `sh tests/Containers/smoke.sh habit-log:reset-security` | **Passed**: non-root/runtime-only image, health/login/assets/document-root/CSRF, unknown-host 400 and spoofed-forwarded-host behavior, cached config/routes/views, canonical HTTPS reset URL, rejected production log/array/failover and container log privacy. No DB access or external mail; disposable container removed. |
| `git diff --check`, shell syntax and local documentation links | **Passed**, including whitespace checks for new files. |

| Requested requirement | Evidence |
| --- | --- |
| Trusted configured reset origin | Malicious direct/forwarded host, scheme, port and prefix probe asserts configured HTTPS hostname/port/path; the shared URL generator remains unchanged. |
| Related email credential links | Signed email-change URL uses the same helper; signature substitution fails, and confirmation succeeds through an explicit TLS proxy. Registration-wide verification remains disabled. |
| Trusted hosts/proxies and local compatibility | Unknown/subdomain Host receives 400 in production; forwarded Host cannot substitute authority. Explicit proxy HTTPS works. Configured local HTTP/port and local alias behavior remain available. |
| No production log-mail token disclosure | Unsafe graphs/URL overrides are rejected before reset-token creation, direct reset dispatch is guarded, and transport failures containing synthetic message/credential canaries produce only fixed diagnostics. |
| Clear production failure behavior | Known/unknown unsafe-config requests have identical 503 JSON, no token rows, and the existing 21st-attempt throttle still returns 429. Successful known/unknown/recent-request feedback remains uniform. |
| Safe mail and local/test preservation | In-memory delivering transport succeeds; safe failover recovers without logging its failed member; all-failed transport diagnostics are sanitized; local log/testing array capture works. No external email is sent by these tests. |
| Existing account/authentication integrity | Full PHP and 88-scenario browser suites pass for reset, password/email changes, sessions, deletion, concurrent operations, Settings drafts and domain privacy. |
| No backup mutation | Metadata-only comparison against the prior inventory confirms size, mode, owner/group and modification timestamps unchanged for both artifacts. No contents were opened or hashed. |

Reviewed against `AGENTS.md`, design guardrails and `DESIGN_VERIFICATION.md`: privacy defaults, owner policies, CSRF/session protections and raw-history transactions remain intact; no UI, scoring, migration or dependency change was introduced. The source changes are limited to the two findings and the directly required configuration/runtime tests. The full local suites and disposable image provide the evidence above; hosted CI status is reported on the PR rather than inferred from local checks.

## Final security review and publication scope

The closeout rechecked the complete source/configuration/test diff and supporting documentation. No additional application change was required. Canonical reset and signed email-change links remain independent of inbound authority; production host rejection and explicit TLS-proxy confirmation are covered. Mail validation uses Laravel's resolved URL configuration recursively, rejects unsafe graphs, and preserves allowed delivery/local workflows. Cached production configuration is exercised by the container check. Failure reporting retains no original transport exception, credentials or message content, and request throttling/CSRF/session boundaries remain in force.

Fresh `php artisan test tests/Feature/Auth/ResetDeploymentSecurityTest.php tests/Feature/Auth/EmailChangeTest.php tests/Feature/Auth/RecoverySecurityTest.php --compact` passed **34 tests / 465 assertions**, 7.75 seconds; `vendor/bin/pint --test` passed. The unchanged application code retains the full 522-PHP/88-browser/build/audit/container evidence above. Whitespace and documentation-link checks pass. Only source, test generators, configuration and documentation are eligible for publication; backup files, screenshots, local logs, generated databases, runtime secrets and other ignored evidence are excluded. The account-deletion policy recheck is not part of this commit.

## Production requirements and residual findings

Set a validated HTTPS `APP_URL` with optional port and no credentials/path/query/fragment. Preserve its hostname at ingress; supply only the real ingress IPs/CIDRs in `TRUSTED_PROXIES`, preserve HTTPS/port forwarding and verify signed email confirmation. Unknown hosts must also be rejected for static requests at the web server. Keep debug disabled and Secure/HttpOnly/SameSite cookies configured. Rebuild caches and restart processes when configuration changes.

Use a reviewed delivering transport, credentials, TLS and sender configuration. Approved transport identifiers do not install provider SDKs or prove delivery. Verify controlled real reset/email-change/old-address-alert delivery and failure behavior, as well as sender authentication and provider/upstream log privacy. Production SMTP capture requires a real isolated SMTP sink; log/array is deliberately rejected. A message already delivered to a provider cannot be recalled, and provider logs are outside this implementation's guarantees.

QA-04/05 application fixes do not establish overall deployment readiness. QA-01 expired SQL backup, QA-02 encrypted retention/purge, QA-03 independent deletion records/reconciliation, QA-06 target migration/configuration, QA-07 capacity, QA-08 manual accessibility and QA-09 operational evidence remain open as described in the [remediation plan](DEPLOYMENT_REMEDIATION_PLAN.md). The sensitive SQL backup is not opened, changed or deleted by this task.

Authorized commit message: `fix(security): harden password reset URLs and mail delivery`. Authorized PR title: `fix: harden password reset URLs and mail delivery`.
