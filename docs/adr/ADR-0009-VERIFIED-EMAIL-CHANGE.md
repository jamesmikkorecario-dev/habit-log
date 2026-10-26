# ADR-0009: Verified login-email changes

Status: Accepted for Account Management Phase 2 (2026-10-26).

## Decision

An authenticated owner requests a new email using their current password. The old login/recovery email and its verification timestamp stay active until the proposed mailbox is verified and the owner supplies their current password again. No global verification gate or generic Fortify profile-update endpoint is enabled.

The user explicitly selected identical request/resend behavior for occupied and unoccupied addresses. Availability is therefore checked only on confirmation after mailbox control is proved; `users.email` remains the final case-insensitive uniqueness authority, including concurrent registration or confirmation. Pending requests do not reserve addresses. Input uses registration's string/email/255-character rules and normal request trimming; stored casing is preserved, and the existing database/login semantics handle case variants. An address differing only by case from the current address is rejected.

One pending row per owner stores a UUID generation, proposed/source emails, a SHA-256 hash of a 256-bit random token, expiry, last-send time and timestamps. Signed links include generation, owner and token and expire after 60 minutes. Only hashes are stored in the table. Replacement/resend creates a new generation and invalidates all prior links. Request, resend and cancellation compare the expected generation to reject stale forms. Cancellation requires authentication and CSRF. Resend also requires the current password.

A GET renders a confirmation or safe recovery page without mutation. A logged-out owner signs in using the existing address and returns to the link. A foreign owner sees no pending address. The final POST requires authentication, CSRF, a valid signature, and the current password; under user-then-pending locks it rechecks the admitted credential hash, source identity, owner, generation, token hash and expiry. Unique-address conflicts preserve the old identity and pending request, with guidance to choose another address.

Confirmation changes the email and verification timestamp, rehashes the same password, rotates the remember token, removes other database sessions and deletes old/new owned-address reset tokens and pending state in one transaction. The fresh hash rejects stale authenticated payloads written back after revocation. The confirming browser gets the committed hash marker, a new session ID and CSRF token; remembered access and previous password-confirmation timestamps are cleared. Already-authorized in-flight work cannot be recalled. No activity, relationship or privacy setting is modified.

Password updates and resets cancel pending requests through `ReplacePassword`. A narrow extension of Laravel's database token repository locks/rechecks the account before reset-token creation/deletion. A stale recovery lookup cannot recreate authority for a surrendered email or delete the replacement owner's token. The reset action also rechecks source email under its existing user lock. Laravel/Fortify still own recovery responses, token format, hashing, expiry, validation and controllers. This design requires the existing default-connection database sessions and reset-token store; moving either store requires revisiting atomicity.

## Delivery and abuse boundaries

Account-bound limits allow five email operations per minute and one request/resend per minute, across IP/session changes. The pending row adds a one-minute send interval under lock. Delivery happens synchronously after the transaction; failure leaves the original login active with a retryable pending request and truthful feedback. Production refuses log/array transports, including those nested in failover/round-robin configurations, so verification tokens do not enter application logs through mail fallback. Development log mail is used only with synthetic test accounts in the browser harness.

A token-free alert is sent to the old address after success. Its failure reports that the change succeeded but the alert was not delivered; identity is never reverted after commit. These are narrowly scoped transactional security emails, not general product notifications.

The existing Apache private access format omits query strings, which contain the verification token and signature. Existing query-exception redaction, non-flash rules for passwords/tokens/signatures, no-store and no-referrer headers remain in force. The confirmation page loads no third-party assets. Any external reverse proxy or production log collector must likewise omit sensitive queries; production SMTP delivery and infrastructure log configuration remain release checks.

## Consequences

One additive reversible migration is required. Rollback removes only pending requests, invalidating outstanding links; confirmed emails and domain history are retained. Expiry is enforced on every confirmation without a scheduled pruning task. Session-management UI, account deletion and Settings draft protection remain deferred. Verification and limitations are recorded in [the implementation report](../ACCOUNT_EMAIL_CHANGE_REPORT.md).
