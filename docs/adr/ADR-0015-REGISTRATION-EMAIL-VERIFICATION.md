# ADR-0015: Registration email verification

Status: accepted for the authorized registration-verification follow-up, 2026-11-14. Supersedes ADR-0009's registration-gate deferral without changing its verified email-change protocol.

## Decision

Use Fortify's email-verification feature, native authenticated notice/resend/verification routes, Laravel `VerifyEmail` notification and `MustVerifyEmail` model contract. Links use the existing canonical `APP_URL` generator and native expiring absolute signatures (60 minutes by default). The route requires the authenticated owner ID and current email hash. Resending does not revoke earlier unexpired links; after verification, replay is idempotent and cannot change the verified timestamp or emit another verification event.

Protect all application pages/writes with native `verified` middleware and explicitly persist it on Livewire requests. Fortify's separate password-update/confirmation routes receive the same gate through the existing route-aware web security middleware. Verification and logout remain authenticated and available without verification. Ordinary guest recovery remains available. Existing authenticated but unverified owners must verify before using Settings, including email-change and deletion controls.

Verification takes the same exclusive user lock as credential updates/deletion, rechecks admitted email/password state, and rejects a missing or changed account. A concurrently completed verification returns without rewriting its timestamp. No credential rotation, activity mutation, token table, migration or account backfill is introduced. Existing non-null verification timestamps are preserved; null values stay null until mailbox proof succeeds.

Verification and resend share a six-per-minute account limiter. Resend uses CSRF-protected POST. Invalid signatures, expiration, foreign IDs and email hashes return safe 403 retry guidance without exposing link details. Production mail uses the existing allowed-transport and sanitized-failure boundary; registration checks configuration/origin after throttling and before creating an account. A delivery failure after account creation can leave an unverified account: the owner signs in and retries from the notice, rather than registering again. No verification timestamp is granted on failure.

## Verification and deployment

The locked mutation also compares the existing immutable recovery UUID so a request admitted before deletion cannot verify a replacement account even if its numeric ID, email and password hash are reused. This consumes the existing identity; it does not change the recovery ledger, schema or admission protocol.

Tests cover notification delivery/expiry, signed-link ownership and replay, stale identity/password/deletion rejection, application/Fortify/Livewire gates, resend limits, CSRF, legacy unverified accounts, reset independence, canonical origins, safe mail errors, and unchanged account/recovery regressions. Browser tests use signed links captured from the isolated local synthetic mailbox; they never mark accounts verified directly or add an application bypass.

Deploy with the existing HTTPS origin/proxy/mail requirements and refresh cached configuration/routes/views and serving processes. There is no production data migration or bulk mail operation. Communicate the new gate to existing null-timestamp owners. Inaccessible mailboxes require an approved support process; do not mark addresses verified manually as a rollout workaround. Rollback must account for removing this new gate; it is not a reason to disable session, deletion or recovery safeguards.
