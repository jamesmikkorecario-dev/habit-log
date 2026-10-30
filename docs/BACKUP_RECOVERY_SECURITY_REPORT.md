# Backup and recovery security

Status: application implementation and local verification complete on 2026-10-30. Production provisioning and restore acceptance remain blocked as documented below. All changes remain uncommitted.

## Scope and implementation

The authorized Deployment Remediation 2 addresses QA-01 inspection and the application controls for QA-02/03. Hosting is undecided. Use provider-neutral Laravel commands, existing PDO MySQL and PHP Sodium, and operator-provisioned POSIX storage. No external infrastructure is assumed configured. Preserve the existing account-deletion policy recheck; do not publish changes or mutate existing backup artifacts.

Implemented boundaries:

- Encrypted, streaming logical snapshots of the supported MySQL schema; no plaintext export file. Restore requires an empty, migrated schema matching the snapshot. Unsupported schemas and legacy SQL exports fail closed.
- Dedicated restricted backup directory, separate restricted durable deletion-state directory, and separately held encryption/authentication key. Never include the deletion state or key in application database backups. Operators must provide durable atomic rename, fsync and reliable flock semantics, plus independent recovery of this state without rolling it back with the database.
- Add immutable random account recovery UUIDs, backfilled during a writer-stopped migration. Minimal external suppression records contain UUID/status only. Persist pending intent before commit and completion afterward; unknown outcomes block recovery. Reuse the existing transactional graph cleanup and ownership checks.
- Isolated recovery mode blocks HTTP before sessions/authentication. Reconciliation removes suppressed owners and invalidates restored authentication state. Admission requires complete, current external state and explicit operator confirmation after isolation/verification. Switching the active generation revokes the old instance's admission.
- Managed backups expire after 29 days, leaving operational margin before the approved 30-day maximum. A Laravel scheduled purge removes only registered artifacts in the dedicated directory. Scheduler uptime, alert delivery, storage lifecycle and all copies remain operator prerequisites.

Affected areas: recovery configuration/services/Artisan commands/middleware, account deletion and User identity, one additive migration, focused security tests, scheduler, deployment/schema/architecture documentation and an ADR. No UI redesign, analytics change, provider integration or new package was added.

Verification uses synthetic databases and temporary restricted directories only: encryption/tampering/truncation, retention boundaries and safe purge, failure capture, concurrency, pre-deletion snapshot restoration, graph/authentication cleanup, missing/incomplete records and HTTP quarantine. PHP/browser regressions, Pint, build, audits and container checks run sequentially; results below distinguish completed checks from production prerequisites.

## Existing environment evidence

Local and remote `dev` and `main` matched `0efd2a34b464329d7f1fd22ac024031f42747c08` at the start. The pre-existing 22-line modification to `docs/ACCOUNT_DELETION_REPORT.md` is preserved.

Metadata-only inspection on 2026-10-30: `storage/app/phase3-checks/pre-phase3.sql` is 13,832 bytes, mode 0644, UID/GID 1000, modified 2026-09-28 05:54:09 UTC. It exceeds 30 days. `git check-ignore -v` identifies `storage/app/.gitignore:1:*`; tracked-path and available-ref history queries return no entries. Its contents were not opened. No permission, content, location or retention change was made. The separate historical synthetic encrypted artifact `storage/app/phase7-checks/restore-backup.gpg` remains 7,816 bytes, mode 0600, modified 2026-10-05 14:00:34 UTC; its historical destroyed key does not establish usable recovery.

Initial repository inspection found no backup service or purge schedule. PHP Sodium is available locally; MySQL command-line dump/import clients are not installed on the host. The implementation therefore uses existing PDO and an authenticated streaming data format rather than shelling out with credentials. Remote stores, production hosts and their schedules are unverified.

The current user's crontab again reported no configured crontab. A successful read-only system timer query found no Habit-Log timer; the only backup-named unit was `dpkg-db-backup.timer`/`dpkg-db-backup.service`. This is limited local evidence, not an inventory of other users, providers or production hosts.

Operator action remains required for the expired plaintext export: restrict access, inventory copies/snapshots and assess exposure, authorize storage-appropriate disposal, and verify disposition without printing sensitive contents. Encrypting it now does not restart retention. No existing artifact is enrolled into automated purge by this change.

## Implemented architecture and trust boundaries

[ADR-0014](adr/ADR-0014-BACKUP-RECOVERY-SECURITY.md) records the complete format, concurrency, failure and admission contract. The application boundary consists of:

| Area | Files / behavior |
| --- | --- |
| Restricted filesystem and encryption | `PrivateFiles`, `EncryptedArchive`: 0700/0600 ownership/alias checks, exclusive creation, complete writes, fsync, authenticated streaming encryption and mandatory final-frame/EOF validation. No plaintext export file. |
| External state | `DeletionLedger`: authenticated atomically replaced state outside the application/backup directories; explicit initialization, minimal account UUID/status records, backup registration, revision and active generation; exclusive writer lock and no implicit recreation. |
| Capture and cleanup | `DeleteAccount` wraps the existing locked transaction in enabled external capture; `EraseAccount` retains the original child-first ownership checks and cleanup. Capture failure rolls back; post-commit completion failure leaves pending state blocking recovery. |
| Database snapshots | `DatabaseSnapshot`: supported-schema fingerprint, repeatable-read unbuffered export, parent-first prepared inserts with FKs enabled, row/table completion checks and preserved auto-increment positions. |
| Backup lifecycle | `BackupManager`: registry-bound encrypted artifacts, complete-stream authentication before restore, 29-day expiry and purge of only registered managed artifacts/partials. |
| Recovery admission | `RecoveryManager`, `RecoveryAccess`, `RecoveryAdmission`: explicit isolated target, empty schema, import quarantine, current suppression replay and authentication invalidation, generation cutover and early HTTP block. |
| Operator entry points | `RecoveryCommand`, `config/recovery.php`, `.env.example`, `routes/console.php`: native command with explicit attestations, safe fixed failure output, every-minute expiry schedule when enabled. |
| Schema | `2026_10_30_000001_add_recovery_identity.php`, `User`: stable hidden recovery UUIDs and non-authoritative database admission marker. |
| Verification/runtime | Recovery feature tests, temporary-storage helper, separate synthetic MySQL initialization, and production-container quarantine smoke assertions. |

Snapshots omit the live `recovery_state` row. Restoring rows without an independently authorized generation cannot satisfy the enabled middleware. `RECOVERY_MODE=recovery` blocks all application routes before sessions/authentication even without DB/ledger availability; `/up` is intentionally only liveness. Static assets bypass PHP and must contain no private restored data. The platform must block private filesystem/DB access, outbound mail and workers independently.

The master key, ledger, service UID, filesystem and operators are trusted. HMAC detects damage/unauthorized modification without the key; it does not prove that an administrator has supplied the newest complete state. A valid stale copy of the entire ledger can be indistinguishable from historical truth. Production requires an independently durable, current, access-restricted store that is never rewound with DB/host backups, plus freshness evidence before recovery. Without that evidence, **do not admit recovery**. This is an external prerequisite, not a claim implemented by directory permissions.

Rollback compatibility is explicit: the required UUID column needs recovery-aware creation code, and old deletion code would omit external capture. After enablement, retain compatible recovery-aware code/schema or stop writers for forward repair. A generic switch to a pre-remediation release or dropping the migration is unsafe while managed backups remain restorable. The migration has been exercised only on synthetic databases here; it has not been applied to development or production data.

## Operator provisioning and procedures

These are reviewable procedures, not actions performed on production. Replace paths/identity/database names with the approved installation configuration. No hosting environment is currently selected.

1. Assign a backup/recovery operator and approve backup frequency, RPO/RTO, monitoring/alert destination, off-host storage and all-copy lifecycle. Inventory existing provider/host snapshots. The code's 29-day expiry provides one day of margin; an offline purge job cannot enforce a physical 30-day limit. Require expiry/failure alerts and independent lifecycle enforcement covering copies before enabling backups.
2. Provision distinct directories outside the application tree, such as `/srv/habit-backups`, `/srv/habit-deletions`, `/srv/habit-recovery-keys`, owned by the executing service UID with mode 0700. The deletion store must be durable across loss/restoration of the DB and must not be included in a snapshot that will rewind it. Verify effective ACLs, privileged access, mount durability, concurrent flock, atomic replacement and fsync on the actual filesystem. Ordinary ephemeral container storage is insufficient. Mount the same state consistently for every app/command instance.
3. Provision a random 32-byte binary master key in the key directory, mode 0600. Keep it separate from backup files and Git, restrict custody, and test authorized key recovery. Do not print it, put it in command arguments or overwrite an existing key. Key rotation is not automatic; retain access for every unexpired archive under a separately reviewed rotation procedure.
4. Stop writers, apply the additive migration, and configure `RECOVERY_MODE=live`, `RECOVERY_BACKUP_DIRECTORY`, `RECOVERY_LEDGER_DIRECTORY`, `RECOVERY_KEY_FILE`. Keep the initial application inaccessible while configuration is cached and initialization is verified. Run `php artisan recovery:manage initialize --confirm-writers-stopped` only once for an intentionally new store. The command refuses an existing ledger/marker; interrupted initialization requires operator investigation, never deletion of unknown state or silent recreation.
5. Run `php artisan recovery:manage backup --confirm-writers-stopped` in the approved backup window with schema changes stopped. The returned UUID selects an artifact; it is not a decryption key. Confirm command success and independently recoverability-test the encrypted artifact. A failed command returns nonzero and logs a fixed message; partial output is never registered as usable. No database credentials or data are passed to subprocesses.
6. Run Laravel's `php artisan schedule:run` every minute under the configured service identity, using the deployment's scheduler/service mechanism. The registered event calls `recovery:manage purge` only in live mode. Verify an actual run, exact expiry boundary, unavailable storage failure and alert delivery; monitor stale execution well before 30 days. Backup frequency is intentionally not silently selected. Manual `php artisan recovery:manage purge` uses the same restricted registry logic. This task did not execute purge against existing local artifacts or a production store.

For a restore:

1. Keep the original installation and recovery target under operator control. Create a **new, empty, separately named** isolated MySQL database; use the same release/migrations and supported InnoDB schema. Do not point the restore command at a populated database. Stop target workers, scheduled jobs and outbound mail; isolate network and private filesystem access. Use separate target environment/configuration and credentials.
2. Mount the independently current ledger and recoverable key; verify freshness/completeness from the authoritative durable source. Configure `RECOVERY_MODE=recovery`, the three restricted paths and `RECOVERY_TARGET_DATABASE` equal to the actual target database name. Cache target configuration and verify `/login`, `/dashboard`, Livewire and Security return 503. The `/up` endpoint does not establish readiness.
3. Apply migrations to the empty target, then run `php artisan recovery:manage restore --backup=REGISTERED_UUID --confirm-isolated`. An expired, unregistered, damaged, incompatible or incomplete backup, missing ledger, pending deletion, wrong target or nonempty schema fails closed. No automatic wipe/drop occurs. Failed import leaves an `importing` marker that cannot be admitted; inspect and provision another empty isolated target for retry.
4. Verify surviving records and absence of suppressed account graphs. The successful command already reconciles deletions and clears all restored sessions/reset tokens/pending email links/revocation rows/remembered credentials. All users must sign in again. Run `php artisan recovery:manage reconcile --confirm-isolated` if the original remained active during the drill; it rechecks the current ledger. Recovery mode remains inaccessible.
5. Drain and permanently stop the original application's traffic, writers, workers and already admitted requests. Verify the latest authoritative ledger again. Run `php artisan recovery:manage admit --confirm-isolated --confirm-source-stopped`. It replays current deletions while locked, verifies the boundary and publishes a new active generation. The old generation becomes inadmissible. Keep the target isolated if any step fails.
6. Only after approved verification, switch the target to `RECOVERY_MODE=live`, rebuild cached configuration and restart all serving PHP/application processes before testing the new mode; OPcache can otherwise retain the prior cached configuration. Verify controlled login/privacy/deletion behavior, then perform the authorized ingress cutover. Apply the same restart requirement when initially switching an existing process into recovery mode, while platform isolation is already enforced. Never use `RECOVERY_MODE=off` to bypass recovery safeguards. This task authorizes no cutover or deployment.

For an interrupted deletion, use `php artisan recovery:manage resolve-pending --confirm-original-database` only against the admitted **authoritative original** DB after verifying its identity. A present UUID resolves to cancelled; an absent UUID resolves to committed. The restored target is rejected for this operation. If that original DB or reliable outcome evidence is lost, recovery remains blocked. Do not guess, clear the pending map, reinitialize the ledger or restore an older copy of it.

## Local verification record

All destructive checks use `habit_log_test`, separately provisioned `habit_log_recovery_test`, and newly created random temporary directories. Existing backup artifacts and the development database are not test inputs. MySQL initialization now creates/grants the second synthetic database for new local/CI volumes; existing test volumes require the same explicit one-time test-only setup.

- Foundational storage/account cleanup run: **14 passed, 150 assertions**, 4.31 seconds.
- Focused recovery suite: **16 passed, 99 assertions**, 34.75 seconds. Includes a real pre-deletion backup/restore drill, retained foreign graph, cleared auth state, quarantine/admission/old-generation rejection, uncertain intent resolution, actual capture/completion storage failures, reused numeric IDs, encryption/tamper/truncation/key checks, restrictive permissions, lock contention, missing/modified state, failed snapshots, expiry boundaries and symlink/unregistered-file safety. An initial drill fixture omitted the required accepted-follow timestamp; that fixture was corrected before these passing results.
- The first full PHP run found two seeder failures (events disabled) and three historical-schema fixtures using current User defaults. Laravel's native `HasUuids` insertion support now generates UUIDs even without events; the historical fixtures insert their original schema shape. The subsequent combined compatibility/recovery run passed **22 tests / 163 assertions**. Additional late-deletion admission, interrupted-import and populated-migration checks passed **3 tests / 40 assertions**.

Final verification commands and evidence (all logs ignored under `storage/app/backup-recovery-checks/`):

| Command | Result / evidence |
| --- | --- |
| `php artisan test --compact` | **541 passed / 7,577 assertions**, 206.13 seconds; `php-full-final.log`. Covers full PHP suite after compatibility fixes. |
| `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e` | **88 passed**, 7.1 minutes; `browser-full.log`. Existing account-deletion, auth/session/email, privacy, journal, tracking, dashboard and Settings regressions. |
| `php artisan test tests/Feature/Recovery/RecoveryWorkflowTest.php tests/Feature/Auth/AccountDeletionTest.php tests/Feature/Auth/AccountDeletionConcurrencyTest.php --compact` | **23 passed / 388 assertions**, 60.72 seconds; `closeout-focused.log`. Run after the final managed-deletion failure-response refinement: an acknowledged-commit loss no longer falsely promises rollback. Pending state still blocks backup/recovery. |
| `php artisan test tests/Feature/Recovery/StorageSecurityTest.php --compact` | **7 passed / 21 assertions**, 0.65 seconds; `storage-scheduler.log`. Includes actual subprocess execution of Laravel `schedule:run`, disabled-mode preservation and enabled-mode expiry of synthetic registered artifacts. This proves the application schedule, not a provisioned host scheduler. |
| `vendor/bin/pint --test` | **Passed**; `pint.log`. |
| `npm run build` | **Passed**; `build.log`. |
| `composer audit --locked` | **No vulnerability advisories**; `composer-audit.log`. |
| `npm audit` | **0 vulnerabilities**; `npm-audit.log`. |
| `docker build --target production -t habit-log:backup-recovery-check .` | **Passed**; `container-build.log`. Image digest `sha256:742d9bec928f073ad57166df9b5a680fb2e8274e1ee7c5c705482bfb93f4f090`. Runtime source is included; no new package or image dependency was required. |
| `sh tests/Containers/smoke.sh habit-log:backup-recovery-check` | **Passed**; `container-smoke.log`. Non-root runtime, existing host/mail/privacy checks, actual restrictive-file/fsync/ledger/encrypted-round-trip probe, cached recovery mode and 503 on login/dashboard/Livewire/Security with no DB or ledger mounted; `/up` remains liveness. |
| `git diff --check` | **Passed** at review; repeated at closeout. |

The final follow-ups added two focused test cases after the 541-test full run; they are recorded separately rather than claiming they were included in that run. No browser-facing success flow changed after the passing browser suite. Historical migration fixture changes affect tests only. The changed-file scope includes those three fixtures, the production recovery fixture, the smoke script and MySQL's second synthetic database initialization in addition to the implementation table above.

The initial container smoke showed that serving PHP could retain prior cached configuration after a mode switch. The harness now restarts serving processes and refreshes Docker's dynamically assigned host port before asserting recovery quarantine. These requirements are explicit in the operator procedure. No change to production authentication or host/mail protections was needed.

## Requirement and design verification

| Requirement | Evidence and limit |
| --- | --- |
| Audit configuration/storage/deletion relationships | Read-only repository, account graph, DB/session/token configuration, scheduler and sensitive-artifact metadata inspection above. External hosting remains unknown. |
| Encrypted restricted backups | Authenticated streaming round trip, wrong-key/tamper/truncation/EOF checks, permission/alias validation, failed snapshot rejection, bounded records and no plaintext export. |
| Maximum retention and automatic purge | 29-day exact-boundary tests, registry-only/symlink-safe purge, actual Laravel scheduler subprocess; all-copy external expiry and alerting still require provisioning. |
| Durable independent suppression | Separate authenticated state, synchronous atomic replacement/fsync, immutable UUIDs, concurrent lock rejection, new-process/service persistence and injected pending/completion failures. Physical durability/currentness remain operator gates. |
| Safe isolated restore | Guarded second synthetic DB, empty matching schema, FKs enabled, complete-stream preflight, transactional import, failed-import marker and HTTP quarantine. Actual target network/worker/mail isolation is external. |
| Deleted graph and auth state stay removed | Pre-deletion snapshot drill, foreign graph retention, reused numeric IDs, late deletion before admission, shared account cleanup, global restored-token/session/remember invalidation and old-generation rejection. |
| Missing/incomplete/unavailable records fail closed | Missing/tampered state, pending outcomes, unavailable capture store, interrupted import and uncertain commit tests; valid whole-store rollback requires independent freshness evidence and blocks release without it. |
| Preserve sensitive historical artifact | Metadata/exclusion rechecked; no contents opened, no permission/content/location/deletion changes. Disposal remains separately authorized. |
| Framework scope and docs | Native commands/scheduler/PDO/Sodium, no package/provider service, one documented migration, ADR-0014, architecture/schema/deployment/runbook updates and explicit prerequisites. |

Reviewed against `DESIGN_VERIFICATION.md`: server-side owner/password/session/CSRF rules remain; private defaults, journal/mood/note privacy and raw surviving activity are unchanged; FK restrictions and transactions remain enabled; no UI redesign or future roadmap feature was introduced. The migration, rollback compatibility and independent-store trust boundary are documented. Manual production cutover, durability/ACLs, target performance, actual alerts and device/assistive checks are not inferred from automated tests.

## Remaining deployment blockers and operator decisions

QA-01 requires access containment and explicitly authorized disposal of the expired plaintext artifact and assessed copies. QA-02 requires actual durable encrypted storage, recoverable keys, approved frequency/objectives, running expiry automation and demonstrated alerts. QA-03 requires independently current deletion-state custody and an isolated target-host recovery drill. QA-06 requires target configuration/migration/cutover acceptance. Existing ingress/mail/capacity/accessibility/platform gates in the remediation plan remain unchanged.

No production recovery safety, off-host durability, scheduler execution, notification delivery, provider integration or forensic erasure is claimed. Local filesystem HMAC/locking tests do not establish those properties. Unsupported/legacy backups, whole-store rollback, missing pending-commit evidence, unverified freshness and a lost master key remain recovery blockers. The external ledger is retained without automatic pruning; its size and recovery performance must be measured for the approved launch envelope. No commit, push, merge, deployment or existing-backup disposal is authorized.

Suggested commit message, only if later authorized: `feat(security): add encrypted backups and deletion-safe recovery`.

Closeout inspection confirms both historical backup artifacts retain their original size, mode, ownership and modification timestamps; the plaintext export and generated verification logs remain ignored. The pre-existing account-deletion report diff remains exactly 22 added lines. `dev` remains at `0efd2a34b464329d7f1fd22ac024031f42747c08`; nothing is staged and no commit, push, merge or deployment occurred. Shell syntax and final whitespace/diff checks pass.
