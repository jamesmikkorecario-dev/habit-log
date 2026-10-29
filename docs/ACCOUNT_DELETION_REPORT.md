# Secure account deletion — Phase 4

Status: application implementation and local verification complete on 2026-10-29. The security-review follow-up patches the Composer advisory and all post-patch checks pass. The implementation is ready for PR review. Backup remediation and production backup/restore prerequisites remain open; this is not production release approval. Work started on clean `dev` at `d95a28f8ec096048b345696f4c8f3b4a2e15e0e7`, verified against remote `dev` and `main`. The PR closeout authorizes two reviewed commits, pushing `dev` to `origin/dev`, and opening a `dev` → `main` PR. Merge, deployment and destructive backup cleanup remain unauthorized.

## Security review follow-up — 2026-10-29

### Composer advisory and minimal remediation

The installed and locked `symfony/mime` **v7.4.18 was vulnerable** to **CVE-2026-45070 / GHSA-vqc8-7275-q272 / PKSA-wtxr-p26d-nn42**, medium severity: Email Header Injection via Non-Token Characters in Mime Parameter Names. The [corrected Symfony maintainer advisory](https://github.com/symfony/symfony/security/advisories/GHSA-vqc8-7275-q272) and current Composer feed list affected branches as `<5.4.55`, `>=6,<6.4.49`, `>=7,<7.4.22`, and `>=8,<8.1.10`; fixed releases are **5.4.55, 6.4.49, 7.4.22, and 8.1.10**. The general GitHub advisory page still displayed older fixed versions when inspected. The maintainer explains that the earlier fix never reached release branches, so those earlier claims are not sufficient evidence of safety.

A synthetic local probe on 7.4.18 confirmed a CR/LF-containing parameter name was accepted and serialized. After upgrading to **7.4.22**, constructor, single-parameter and bulk-parameter paths reject the same invalid name with `InvalidArgumentException`, while a normal charset parameter still serializes correctly. No mail was sent by this probe. Source inspection found no application code constructing user-controlled MIME parameter names; that limits demonstrated application exposure but does not make the vulnerable library acceptable.

`composer update symfony/mime:7.4.22 --dry-run --minimal-changes --no-interaction --no-scripts` resolved exactly one update. The identical command without `--dry-run` updated only the Mime entry in `composer.lock` and installed it in ignored `vendor/`. `composer.json`, all other locked packages, application code, database schema and npm dependencies are unchanged by this review. No advisory ignore, audit suppression, broad dependency update or new dependency was added. Scripts were omitted to avoid unrelated asset publication; Composer regenerated optimized autoload files.

### Sensitive local backup assessment and recommendation

Read-only inspection of `/home/james/projects/habit-log/storage/app/phase3-checks/pre-phase3.sql` found:

- Owner/group `james:james`, 13,832 bytes, mode `0644`; modification time **2026-09-28 13:54:09 +08:00**, approximately **31.08 days old** at inspection on 2026-10-29, exceeding the approved 30-day maximum.
- It is a development `habit_log` export, consistent with the Phase 3 upgrade record. An in-memory parser emitted only aggregate classifications: one user matching the documented test seeder identity, two database sessions, ten categories and 27 aliases; no habit, entry or journal INSERT rows. A password hash is present. No backup values, session payloads, identifiers or credentials were printed or copied into this report.
- The account is a known seeded test identity. The two sessions came from the development database and their provenance is not established as synthetic. Therefore this is **sensitive development backup data, not a verified synthetic fixture**. The seeded identity does not make its credential/session material safe to publish.
- Owner and the `james` group can traverse the path. The file itself permits group/other reads, while `/home/james` is `0750`, blocking ordinary users outside that group through this path. Privileged access and alternate mounts/copies are not excluded. It is outside the configured `public/` document root, no public symlink exposes it, and Docker excludes storage contents. External web-server configurations and other copies were not audited.
- `git check-ignore -v storage/app/phase3-checks/pre-phase3.sql` matches `storage/app/.gitignore:1:*`. `git ls-files --error-unmatch` fails as expected for an untracked file, and `git log --all -- ...` returns no history for this path in available refs. These checks do not prove absence of renamed/copied content or purged remote history. Do not force-add or attach the export to a PR.

**Operator action required:** restrict the file and containing evidence directory to authorized access, assess any sharing/copies and whether the stored session material warrants revocation, and explicitly authorize removal of this expired plaintext export. Encryption now would not reset its age or make continued retention compliant. If an exception is needed, obtain a new retention decision before retaining it. Do not restore it into an accessible environment without deletion reconciliation. On authorization, use the operator's storage-appropriate disposal procedure and verify removal of any copies/snapshots; ordinary unlinking is not a claim of forensic erasure on SSDs or snapshots. No chmod, deletion, overwrite, move, restore or upload of this backup was performed by this review.

To verify Git exclusion again without reading contents:

```sh
git check-ignore -v storage/app/phase3-checks/pre-phase3.sql
git ls-files --error-unmatch storage/app/phase3-checks/pre-phase3.sql
git log --all --format='%h %s' -- storage/app/phase3-checks/pre-phase3.sql
```

Expected results are an ignore rule, a nonzero untracked-file result, and no path history respectively. Recheck `git diff --cached --name-only` before any future authorized commit. Generated logs, screenshots and fixture data must stay excluded too.

### Security review conclusions

The current diff, FK migrations, deletion action/controller, token repository, session writer, category writer and synthetic test guards were re-inspected. Cleanup is child-first and atomic under the owner lock; injected failure rolls back the graph and retains usable authentication. Shared access grants no ownership: foreign independent records and scores survive, both directions of the deleted owner's follows are removed, and malformed cross-owner references fail closed. Session and token cleanup occurs before deleting the user; sign-out follows commit. Missing-owner admission prevents stale session writes and recovery tokens from restoring access. CSRF, current-password verification, explicit acknowledgment, policy, form-owner pinning and account-bound throttling remain intact. Eleven real competing-connection operations exercise stale writes and duplicate deletion. No application-code security defect requiring an additional change was identified in this review.

The guarded PHPUnit and browser fixtures verify the actual connected database is `habit_log_test` before destructive setup. The review does not connect deletion actions to the development database and does not import the backup. All mutation tests use synthetic accounts. No real user account or activity was changed. The backup was inspected read-only; a private digest/metadata comparison confirmed its content, size, modification time, mode and ownership are unchanged.

### Post-patch sequential verification and review readiness

Verification runs sequentially. Logs use the ignored `storage/app/account-deletion-review-*` prefix. The earlier implementation results below are historical baseline evidence, not substitutes for testing the patched dependency.

| Command/check | Post-patch result |
| --- | --- |
| Local MIME parameter probe | Constructor, single and bulk invalid-name paths rejected; valid header preserved. |
| `php artisan test --compact` | **508 passed / 7,288 assertions**, 155.71 seconds. |
| `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e` | **80 passed**, 7.1 minutes, including deletion, mail/recovery, password, sessions, dashboard and sharing. |
| `vendor/bin/pint --test` | **Passed**. |
| `npm run build` | **Passed**. |
| `composer audit --format=json` and `composer audit --locked` | **No security vulnerability advisories** in installed or locked packages; no abandoned packages reported. |
| `npm audit` | **0 vulnerabilities**. |
| `docker build --target production -t habit-log:account-deletion-review .` | **Passed**, built from the patched lockfile. |
| `sh tests/Containers/smoke.sh habit-log:account-deletion-review` | **Passed**: non-root, runtime-only files, health/login/assets, document-root isolation, CSRF and log privacy. |
| `docker run --rm -i --entrypoint php habit-log:account-deletion-review` with the synthetic MIME probe | **Passed**: production image reports Mime v7.4.22 and rejects the invalid parameter name; no DB writes. |
| `git diff --check` and lockfile package comparison | **Passed**; only Mime's package entry changed, Composer content hash unchanged. |
| Backup digest and metadata comparison | **Unchanged** content, size, modification time, mode and ownership. |

**Ready for PR review, not production deployment.** The advisory is remediated and all requested post-patch verification passed. No further application-code change was required by this review. This review changes only `composer.lock`, this report and the account-plan/auth-review/roadmap status references; the Phase 4 implementation and tests remain intact.

Production remains blocked on encrypted/access-restricted backups with automatic expiry/purge, durable minimal deletion-suppression records outside restored backups, and verified restore-time reconciliation before traffic or outbound mail resumes. These controls are **not implemented or verified by this work**. If reconciliation is unavailable, a backup containing deleted accounts must not be restored live. The expired local export also requires explicit operator remediation. Review readiness does not grant deployment or destructive-backup-cleanup authorization.

## Accepted decisions

The user explicitly approved immediate transactional deletion of the owner's live data with no recovery window. Settings → Security is the requested location, superseding the original separate Account-page proposal. Other users' independent records and system categories survive; sharing grants read access, not joint ownership. [ADR-0011](adr/ADR-0011-ACCOUNT-DELETION.md) records the complete contract.

The approved backup policy is a maximum 30-day retention, automatic purge of expired database backups, encryption and access restrictions, if backups are configured. A durable minimal deletion record outside restored backups must support reconciliation before a restored environment becomes accessible. Without reliable reconciliation, live restoration of a backup containing deleted accounts is prohibited. This is an operational prerequisite, not an implemented restore-safety claim. Live deletion is never delayed for the retention window.

## Read-only backup inventory

Initial metadata-only inspection found two local artifacts. The follow-up above subsequently classified the plaintext export in memory without displaying its contents:

| Artifact | Observed metadata | Interpretation |
| --- | --- | --- |
| `storage/app/phase3-checks/pre-phase3.sql` | 13,832 bytes; modified 2026-09-28; mode 0644 | Historical plaintext pre-upgrade export documented in Phase 3. It is older than the approved maximum and does not demonstrate encryption or restricted backup access. Operator remediation is required; this task did not delete or rewrite it. |
| `storage/app/phase7-checks/restore-backup.gpg` | 7,816 bytes; modified 2026-10-05; mode 0600 | Encrypted synthetic test restore artifact. Phase 7 documents destruction of its temporary key, so it is rehearsal evidence, not a usable production backup. |

The repository's Compose services and Laravel console routes define no backup/purge service or schedule. The current user's crontab is empty. Listed system timers include the OS package-database backup (`dpkg-db-backup`), but no identified Habit-Log database backup job. Production hosts, remote backup stores, other users' schedules and external orchestration were not inspected. Therefore backups cannot be claimed absent, and automated 30-day purge, external deletion records and restore protection remain **unverified**. No real account or backup artifact was deleted.

## Implementation boundaries

`DeleteAccount` derives the owner from the authenticated request, validates password/acknowledgment/form identity, checks the supported storage configuration and reauthorizes under an exclusive user lock. Integrity checks reject inconsistent cross-owner references. Explicit set-based deletes preserve FK rules and include archived/trash histories. The current owned email's reset token is removed while the user still exists; pending proposed emails never authorize foreign token deletion. The final user delete cascades pending-email/revocation state and removes stored credentials. The controller signs out only after commit, invalidates the session and CSRF state, clears remembered cookies and redirects to a guest confirmation using the existing habit.log authentication layout.

A dedicated owner ability and per-account rate limit protect the DELETE endpoint. The confirmation dialog explains immediate deletion, sharing consequences and backup limits; requires password and acknowledgment; focuses Cancel by default; resets secrets on cancellation; and disables repeated submission. Errors use a separate bag and focus a safe summary without flashing inputs. Shared-profile authorization safely rejects a missing subject. Livewire persistent route binding runs before component exception handling; shared-profile/habit routes now redirect missing-owner Livewire requests to People rather than leaving stale content behind a 404 overlay. Direct missing-owner visits remain 404.

`CreateCategory` adds a transactional fresh-user shared lock to the existing validated category flow. Other domain writers already use user-first locks, recovery token creation checks fresh identity, and the database session handler serializes authenticated writes and rejects missing users. No database migration or dependency is needed. Existing password/email/session/dashboard behavior and surviving users' raw history are preserved.

## Initial implementation verification results

All destructive test operations use the existing guarded `habit_log_test` harness and synthetic fixtures. No development database reset or real-account deletion is permitted. Fixtures exercise active/archived/trashed shared habits, immutable checkpoints, notes, journals/mood, categories, follows and authentication state. Generated tokens/screenshots/logs remain ignored under `storage/app/` and are not CI artifacts.

Verification is sequential. Recorded results:

- `php artisan test tests/Feature/Auth/AccountDeletionTest.php tests/Feature/Auth/AccountDeletionConcurrencyTest.php tests/Feature/Categories --compact`: **27 passed / 378 assertions**, 13.01 seconds. Includes graph cleanup, foreign-record/score preservation, password/acknowledgment/owner boundaries, rollback, stale credentials, late session persistence, storage rejection, and real MySQL concurrency.
- `php artisan test --compact`: **508 passed / 7,288 assertions**, 154.59 seconds. An earlier full run exposed a factory-versus-database attribute comparison and a same-connection simulated category race. The baseline comparison now uses persisted attributes; the category race now uses a real competing connection committing between the UI precheck and INSERT. No duplicate-handling assertion was removed.
- `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e -- tests/Browser/account-deletion.spec.js`: **2 passed**, 11.4 seconds in the final focused run, after the final visual review switched signed-out success to the established habit.log authentication layout. This rerun includes keyboard assertions, accessibility checks and refreshed screenshots; the only application change after the full suites was that layout selection.

The concurrent deletion test holds an exclusive user lock while separate PHP processes admit stale state and attempt habit, entry, journal, category, follow, profile, reset, recovery-token, login, session-write and duplicate-deletion operations. After deletion commits, waiting writes cannot recreate records or authentication. The separate category race preserves a genuinely committed winning category and returns the expected field error to the loser. These use actual MySQL connections and foreign keys, not sequential calls presented as races.

The 5,000-entry synthetic history check used **19 queries**, approximately **0.07 seconds** in a focused local run, and no measured increase in allocated PHP memory. This is bounded local evidence, not a production-size recovery or latency guarantee.

Browser coverage exercises password and acknowledgment validation, CSRF and owner rejection, Cancel/Escape and focus return, safe initial focus, pending submission, real sessions/remember cookies, stale Livewire writes, direct and already-open shared profile/habit URLs, foreign journal/habit preservation, pending email links, old reset links, failed login and browser Back after deletion. Axe scans and screenshots cover 1440px and 390px. The browser run discovered and verified the missing-owner persistent-route redirect fix described above.

- `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e`: **80 passed**, 6.8 minutes, including the final keyboard assertions and all existing browser regressions.
- `vendor/bin/pint --test`: **passed**.
- `npm run build`: **passed**, Vite production assets generated.
- `composer audit --locked`: **one medium advisory**, [CVE-2026-45070](https://symfony.com/cve-2026-45070), affecting the existing locked `symfony/mime` v7.4.18 (affected 7.4 releases are below 7.4.22). The audit reported it on 2026-10-28. This was the initial failing audit; the security-review follow-up above updates the lockfile and records the remediation. It must not be described as a passing baseline audit.
- `npm audit`: **0 vulnerabilities**.
- `docker build --target production -t habit-log:account-deletion-check .`: **passed**, rebuilt after the final layout change.
- `sh tests/Containers/smoke.sh habit-log:account-deletion-check`: **passed** on that final image: non-root execution, runtime-only files, health/login/assets, document-root boundaries, CSRF and sensitive-URL log privacy. Logs: `storage/app/account-deletion-container-build.log` and `storage/app/account-deletion-container-smoke.log`.
- `git diff --check`: **passed**. At the initial verification checkpoint, `dev` was still at the original starting commit and all implementation changes were uncommitted.

Screenshots refreshed by the final focused run cover Security, confirmation, validation and signed-out success at both widths. Examples: [desktop confirmation](../storage/app/account-deletion/confirmation-1440.png), [mobile confirmation](../storage/app/account-deletion/confirmation-390.png), [desktop success](../storage/app/account-deletion/deleted-1440.png), [mobile success](../storage/app/account-deletion/deleted-390.png). Generated artifacts remain ignored. Full PHP/browser logs are `storage/app/account-deletion-full-php.log` and `storage/app/account-deletion-full-browser.log`.

## Requirement review

| Requirement | Implementation and evidence |
| --- | --- |
| Current password and irreversible acknowledgment | Fresh hash check under owner lock; accepted checkbox validation; feature and browser rejection tests. |
| Explain removed, retained and shared data | Security summary and modal; full graph/foreign-score preservation tests; desktop/mobile screenshots. |
| Approved retention contract | ADR-0011 and deployment prerequisites; read-only inventory above explicitly records unverified restore protection. |
| Atomic cleanup and foreign-user isolation | Transaction, preserved FKs, cross-owner integrity checks; complete graph, malformed-reference and injected rollback tests. |
| Remove authentication state | Session, remember credential, current-address reset token and pending-email cleanup; real browser/session/token tests. |
| Sign out and reject future authentication | Post-commit guest redirect/session invalidation; deleted login, remembered sign-in, old reset and verification link rejection. |
| Repeated, stale, concurrent and unauthorized requests | CSRF, account-bound limiter, policy, owner pin and fresh locks; actual competing MySQL connections for eleven stale operations plus browser checks. |
| Responsive, accessible Terminal Green interface | Cancel default focus, Tab/Shift+Tab, Escape, focus return/error/success, duplicate-submit state, axe and overflow assertions at 1440/390px. |
| Preserve existing account/dashboard behavior | Full 508-test PHP suite and 80-scenario browser suite, including password, email, sessions, dashboard, journal and social regressions. |

No migration, dependency addition or real-account deletion occurred. The initial implementation and security-review passes did not commit or publish changes. The subsequently authorized PR closeout publishes only source and documentation; container checks use a disposable local image without database writes. Hosted CI status is recorded on the PR. Merge, deployment and destructive backup cleanup remain out of scope.

## Design review and limitations

Reviewed against `AGENTS.md`, the guardrails and `DESIGN_VERIFICATION.md`: ownership is enforced by authentication, policy and locked fresh state; destructive confirmation is explicit; domain FK rules remain enabled; cleanup is transactional; raw history remains unchanged for surviving accounts. The feature changes only account deletion and directly required writer/shared-access boundaries. No future Settings draft guard, automatic habit expiry, migration, dependency or deployment is added.

Already delivered content, screenshots and email cannot be recalled. Requests already authorized before deletion may have delivered data; subsequent authentication and writes are rejected. Automated keyboard, accessibility-tree and axe evidence does not replace manual NVDA/VoiceOver testing. Physical devices and non-Chromium engines are unverified. The synthetic 5,000-entry test does not establish production-scale transaction limits. Production backup purge, external deletion capture and restore reconciliation are unimplemented/unverified prerequisites; the local plaintext historical backup requires operator remediation. No production restore-safety claim is made.

Authorized separate commit messages: `feat(auth): add secure permanent account deletion` for the application work and `fix(deps): patch Symfony Mime header injection` for the security update.
