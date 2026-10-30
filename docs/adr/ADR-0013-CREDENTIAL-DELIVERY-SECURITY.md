# ADR-0013: Canonical credential links and private production mail failures

Status: Accepted implementation scope under Deployment Remediation 1, 2026-10-30. Target-host/mail acceptance remains unverified.

## Problem

Laravel's default reset notification and the application's signed email-change generator used the current request origin. `APP_URL` alone did not override it. Production could also select Laravel's log mailer for reset notifications, and composite transports could log transport exceptions containing private message/SMTP diagnostics. The previous email-change-only guard did not cover reset notifications or all resolved transport configuration overrides.

## Decision

Use a small shared `CredentialUrls` helper to validate `APP_URL` and clone/reset the framework URL generator with that origin and scheme. Production requires HTTPS; local/testing can use configured HTTP origins and ports. Credentials, paths other than `/`, queries, fragments and malformed origins are rejected. Register Laravel's reset-URL callback and generate the existing absolute email-change signature through that helper. Do not globally force ordinary application URLs or enable registration-wide email verification.

Enable Laravel's exact trusted-host middleware, without implicit subdomains. Its local/testing exception preserves development aliases. Configure proxy IPs/CIDRs explicitly; no automatic platform-based trust or wildcard is enabled. Accept forwarded client IP, scheme and port only; ingress must preserve canonical Host. The container health probe sends the configured hostname to its local listener. Static-file rejection and actual ingress/TLS configuration remain operator requirements.

Extend Laravel's mail manager at its transport boundary. Validate the effective configuration using Laravel's own URL parsing, reject non-delivering/unknown/missing/legacy configurations and unsafe or cyclic composite members, and retain supported delivering transports. Wrap production transport failures in a fixed exception without a previous exception. Use a null internal logger for composite transports so failed attempts cannot publish message/debug data; report only terminal failure as a fixed event. Local/test transports keep their established behavior. No dependency or provider integration is added.

Recovery preflight runs after rate limiting but before user lookup/token creation: unsafe configuration yields the same generic 503 for known/unknown accounts. Direct reset notification dispatch also enforces the boundary and removes private diagnostics from notification/rendering failures. Delivery failures remain visible as sanitized 503 responses; tokens already created retain normal expiry/throttling. Successful known/unknown/throttled request feedback remains uniform. Email-change request/resend retains retryable pending-state feedback and old-address alert failure never rolls back a confirmed identity change.

## Consequences and limits

`APP_URL` becomes a security-sensitive, required deployment setting; changing it can invalidate existing absolute signed email links. Production log/array mail capture is unavailable: use local/testing or an isolated real SMTP sink for synthetic production rehearsals. Future custom transports need review before adding them to the allowlist. Composite failed-attempt details are intentionally unavailable in application logs; use separately restricted provider metrics and the fixed terminal-failure event. Restart workers/processes and refresh cached configuration after changes.

No migrations, account data changes, UI redesign, CSRF/session weakening or backup changes are required. Server-side authorization, token hashing/expiry/single-use checks and account transaction rules remain unchanged. Tests with synthetic transports prove boundaries, not actual mail delivery or provider-side privacy. Real TLS, ingress, mailboxes, external diagnostic collectors and backup/deletion reconciliation remain release gates.
