# ADR-0008: Authenticated password changes

- **Status:** Accepted for account management Phase 1
- **Date:** 2026-10-25
- **Authorization:** The user requested implementation of password changes from the account-management plan, with other account features deferred.

## Decision

Use Fortify's password-update contract and native Blade form at Settings → Security. Every update requires the current password and the existing confirmed-new-password policy. Apply a user-bound five-per-minute limit and normal authentication/CSRF middleware. Password values never enter Livewire state or flashed input.

Acquire an exclusive user lock, reload credentials, and reject an admitted stale hash before verifying the current password. Replace the hash and remember token, consume reset tokens, and remove other database sessions atomically. Share this replacement with password recovery; recovery revalidates its token and admitted hash after acquiring the same lock. Explicit hashing treats hash-looking strings as literal passwords. The password-update boundary rejects NUL and bcrypt's unsupported lengths instead of silently truncating a chosen password.

Keep the initiating browser by storing the committed hash marker and regenerating its session ID/CSRF token. Clear its old confirmation timestamp and remembered cookie. Other remembered logins are invalidated by token rotation. Laravel's web-wide `AuthenticateSession` rejects stale authenticated session payloads on reuse, including Livewire requests. Requests already authorized before revocation cannot be recalled.

## Consequences

No schema or dependency changes. Atomic cleanup assumes the configured database sessions and reset-token repository use the user database connection. No activity, journal, social, privacy, or tracking-history mutation occurs. Pending email cancellation becomes required when that future state exists; it does not require a speculative migration now. Session-management UI, email changes, account deletion, and draft protection remain deferred.

Fortify retains its standard success events and duplicate post-action token cleanup. A full redirect after success supplies fresh CSRF state. Tests use actual cookie contexts, controlled independent MySQL writers, rollback failures, and preserved domain records; see [the report](../ACCOUNT_PASSWORD_CHANGE_REPORT.md).
