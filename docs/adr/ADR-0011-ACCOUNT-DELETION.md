# ADR-0011: Confirmed permanent account deletion

Status: Accepted for application implementation by the Phase 4 request and explicit retention-policy clarification on 2026-10-28. Production backup/restore prerequisites remain unverified.

## Contract

Settings → Security contains an owner-only deletion confirmation. This supersedes the original plan's separate Account page. Final submission requires the current password and an explicit irreversible-deletion acknowledgment. The authenticated guard determines the owner; a form account ID only rejects stale forms from another login. CSRF, an account-bound five-attempts-per-minute limit, fresh credential/session checks and a User policy enforce the boundary. Passwords stay in ordinary server-submitted forms and are never flashed or serialized into Livewire state.

Deletion immediately and permanently removes the owner's live account, active/archived/trashed habits, entries/notes/reasons, tracking versions/checkpoint snapshots/completions, lifecycle intervals, journals/mood, custom categories/aliases and both directions of pending/accepted follows. Public/follower-visible activity disappears rather than becoming anonymous or transferred. Other accounts' independent records and system categories remain. There is no account recovery window; ordinary habit trash remains recoverable for surviving accounts.

## Transaction and concurrency

A single transaction takes the owner lock, rechecks current credentials and session admission, validates cross-owner relationship integrity, and performs explicit child-first deletes. Existing restrictive/cascading foreign keys remain intact. Unexpected cross-owner entries, version/completion/source-checkpoint references or category use fail closed, without deleting or nullifying another owner's independent data. Set-based deletes include soft-deleted habits and avoid loading histories into PHP memory.

Category creation now shares the user-first locking contract already used by habit, journal, profile and security writes. Follow mutations retain ascending user lock order; deletion acquires no second user lock. Bounded deadlock retries preserve atomicity. Missing users reject waiting mutations, recovery-token creation, login admission and late authenticated session writes.

Delete the current owned email's reset token before deleting the user. A pending proposed address never authorizes deleting that address's recovery token. Remove all owner database sessions, pending email changes and user-bound revocation hashes; user deletion removes the password/remember token. Sign out and invalidate the current session/CSRF state only after commit. Any failure rolls back all cleanup. A guest confirmation page contains no private account shell. Existing no-store responses remain in force. Already delivered content cannot be recalled.

Deletion requires the supported database-session and same-connection database-reset-token configuration; unsupported configuration fails closed. No migration, new dependency, queue, general audit ledger or backup infrastructure is introduced.

## Approved backup policy and production prerequisites

The user approved **at most 30 days** of database-backup retention if backups are configured, automatic expiry purge, encryption and restricted access. Live deletion is immediate and does not wait for backup expiry. Previously delivered emails and independently saved copies cannot be recalled.

A restore must never expose previously deleted accounts or their personal data. Before production backup/restore is enabled, establish and verify durable, access-restricted deletion records **outside the backups being restored**, using the minimum necessary identifiers. Reapply all relevant deletions in an isolated restore before allowing access or outbound mail. If reconciliation is unavailable or unreliable, restoring a backup containing deleted accounts into a live environment is prohibited. Do not infer safety from restoring only the application database or its cascaded session-revocation rows.

This application change does not implement or verify that external record, automatic backup purge or restore reconciliation. Operators must select the external store, identifiers, capture/failure contract, retention spanning every restorable backup, and recovery verification before enabling production backups/restores with account deletion. If existing backups are present, reconcile this gate before release. The repository's local inventory and limitations are recorded in [the implementation report](../ACCOUNT_DELETION_REPORT.md).
