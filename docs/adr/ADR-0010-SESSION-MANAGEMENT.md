# ADR-0010: Owner session management and durable revocation

Status: Accepted for Account Management Phase 3; implemented and locally verified.

## Scope and accepted clarification

The Phase 3 request explicitly authorizes individual and bulk revocation, superseding the earlier plan's deferral of individual-session removal. The user accepted Laravel's existing account-wide remember-token limitation: selecting one session signs out that active session and invalidates remembered sign-ins across the account, while other active sessions stay signed in. The confirmation dialog explains this. No per-device remember-token system, account deletion or Settings draft protection is introduced.

Runtime inspection found `SESSION_DRIVER=database`, default MySQL connection, `sessions` table and 120-minute idle lifetime. Management requires this database table on the default connection. Unsupported drivers/connections show an unavailable message and cannot execute management operations; ordinary authentication retains its existing behavior. Deployments must apply the migration before serving this code. No silent driver conversion occurs.

## Security design

Each session row has an opaque UUID `management_id` for owner-scoped UI actions and a SHA-256 `origin_hash`. The UUID grants no authentication authority. Raw session identifiers, payloads, IP addresses and raw user-agent strings are absent from the page. Browser/device names are conservative estimates; last activity uses the owner's timezone. Current-session presentation is derived from the request even before its row is persisted. Other active sessions are paginated and explicitly refreshed.

Revocation locks the fresh authenticated user before session rows, verifies the current password, scopes the selected UUID to that owner, rejects current/expired/missing targets and persists hashed revocation records before deleting rows. Bulk revocation also rehashes the unchanged password. Both operations rotate the shared remember token and retain the initiating browser with a new session/CSRF token. Revocation does not delete activity, pending email requests or recovery tokens.

Deleting a session row alone is insufficient: an already admitted request could later write it back or regenerate its ID. `RejectRevokedSession` checks both the incoming ID and stable origin before authentication middleware/Livewire hydration. The origin survives ordinary ID regeneration; fresh login establishes a new origin. `RevocableDatabaseSessionHandler` extends Laravel's database handler and serializes the final authenticated write under the user lock, rechecking credentials, origin and any remembered admission. A late response cannot persist a revoked session or a stale password/remember-cookie admission. The Login event also checks the admitted user under the same lock and stamps the credential marker immediately.

Password reset/change and email confirmation retain their existing hash/session protections and additionally record revoked origins when deleting database sessions, covering marker-free legacy rows. Existing session payloads are never decoded for listing or migration. User-agent labels are untrusted estimates and raw values are not rendered.

`session_revocations` retains hashes until account removal, with a cascading user FK. There is deliberately no time-based pruning: a sufficiently delayed writer must not become authorized after a tombstone expires. This adds small durable security records, not activity history or an analytics cache. Already authorized in-flight business operations cannot be recalled; revocation rejects subsequent requests and prevents their stale authenticated session writes.

## Migration and rollback

The additive migration adds nullable unique UUID and origin-hash metadata to `sessions`, populates only metadata for existing rows, and creates `session_revocations`. No credentials, payloads or domain records are changed. The migration is reversible, but removing tombstones removes this security boundary. Coordinate rollback with code rollback and invalidate existing authenticated sessions before returning to a version without durable revocation.

## Verification

See [the implementation report](../ACCOUNT_SESSION_MANAGEMENT_REPORT.md) for final commands, results, concurrency/expiry cases, browser screenshots and operational limitations.
