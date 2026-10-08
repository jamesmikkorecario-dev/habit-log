# Deployment and recovery

Status: Phase 7 provider-neutral runbook with local rehearsal evidence in [the closeout](PHASE_7_CLOSEOUT.md); target-host verification remains open. This is a provider-neutral procedure, not evidence of a deployed service. Record target-host, TLS, mail and recovery checks in the Phase 7 closeout before launch. Use [database design](DATABASE_DESIGN.md) for migration semantics and [security/privacy](SECURITY_AND_PRIVACY.md) for disclosure rules.

A production Docker image is available; see [the Docker guide](DOCKER.md#production-image) for build/runtime commands and container persistence. The host/TLS/mail/recovery checks here still apply.

## Runtime and release layout

Deploy one Laravel application, a web server/PHP process manager, and MySQL compatible with the locked application. PHP 8.3 or newer within the Composer constraints is required; run `composer check-platform-reqs --no-dev` on the actual runtime to verify extensions and dependency requirements. MySQL 8.4 is the repository's tested database. Node 22 and npm are build tools; neither Vite's development server nor `artisan serve` is a production server.

Use a versioned release directory and an atomic current-release pointer. Serve only its `public/` directory. Persist `.env`/injected secrets and application `storage/` separately from releases; allow the PHP user to write only required storage/cache locations. Keep the database private to the application/network administrators. The local Compose file publishes a development port and contains development credentials; do not use it as a production configuration.

Establish deployment operator, domain, TLS termination/trusted proxy chain, database credentials and backup operator before running this procedure. Keep these details in deployment configuration rather than in the repository. The test account has no production role; never create or copy its credentials into live data.

## Build and preflight

On a clean build checkout of the selected revision:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci
npm run build
```

Ship the exact Composer lockfile/vendor tree and `public/build` manifest/assets together. Development browser tooling is not required by the application. Retain a separately verified prior application release. Do not run `composer setup` in production: it is a development convenience that can generate a key and run migrations without the controlled sequence below.

Provision secret configuration before starting PHP, with at least:

| Setting | Production requirement |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | `production`, `false`, actual HTTPS origin. |
| `APP_KEY` | Generate once for a new installation; preserve on upgrades and restore. Store securely with recovery material. Never regenerate as part of normal release. |
| `DB_CONNECTION`, host/port/name/user/password | MySQL with a dedicated account and intended database. Omit conflicting `DB_URL`; inspect resolved configuration without printing passwords. Use encrypted network transport when required by hosting. |
| `SESSION_DRIVER`, cookies | Database sessions; `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`; host-only domain unless a tested shared-domain requirement exists. |
| `MAIL_*` | Real approved SMTP/transport and sender; verify a controlled password-reset mailbox. Log/array mail is for isolated rehearsal, not live delivery. |
| Logging | A supported channel with restricted access and rotation, debug disabled, appropriate error retention. Do not log request bodies, query bindings, cookies, auth headers, reset URLs or private content. |
| Other services | Preserve synchronous behavior and current cache configuration unless a demonstrated requirement changes them. No queue worker/Redis/WebSocket service is required by the product. |

Configure the actual web server to reject unknown hosts. If TLS terminates at a proxy, explicitly trust only the intended proxy addresses and forwarded headers through Laravel's supported configuration, then verify generated HTTPS links and Secure cookies. Do not use a blanket trust-all proxy setting to make a local test pass. The application supplies no-store, no-referrer, SAMEORIGIN and nosniff headers on web responses. Static assets can have immutable caching at the server; private HTML/Livewire responses must not be cached by a CDN or shared proxy.

A strict content security policy is not claimed by Phase 7 without compatibility verification for installed Livewire/Alpine and all pages. Do not deploy an untested policy that blocks application scripts or style behavior. Document the target host's tested policy and any remaining exposure separately.

## Backup before an upgrade

Coordinate a maintenance window for schema/history upgrades and prevent all writers while the backup/upgrade boundary is established. Existing migrations include MySQL DDL and restart-safe per-habit history backfill; the whole upgrade is not one transaction.

Back up the application database, persistent storage required by the installation, current release identity and secret recovery material (including the application key), with encryption and separate access-controlled storage. Do not put the encryption key beside its backup. Use a database client option file owned/readable only by the operator rather than passwords on the command line. A template database export is:

```bash
mysqldump --defaults-extra-file=/secure/path/database-client.cnf \
  --single-transaction --no-tablespaces --set-gtid-purged=OFF \
  --routines --triggers --events DATABASE_NAME > /secure/path/backup.sql
```

Replace the conspicuous path/database placeholders for the target. Encrypt and transfer the export through the approved backup system, verify completion, and remove transient plaintext according to that system's procedure. `--single-transaction` is not protection against concurrent schema changes; do not run other DDL during export. Include every domain table, not only current habits: historical versions/checkpoints, entries, suspensions, journals and follow approvals are essential.

Before launch, agree on retention, backup frequency, off-host location, key custody, recovery point and recovery time objectives. Proposed starting targets for operator review are daily backups, 30 days of retention, a recovery point within 24 hours and recovery within four hours; these are not measured guarantees or automatic product trash expiry. Choose stricter targets if daily lost activity is unacceptable.

## Fresh install or populated upgrade

Run from the prepared release with production configuration and an independently verified backup for upgrades:

```bash
php artisan down
php artisan migrate:status
php artisan migrate --force
php artisan db:seed --class=CategorySeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The category seeder is idempotent and creates no login. Do not run generic development seeding, `TestAccountSeeder`, `migrate:fresh`, `db:wipe`, or the browser/performance fixtures against a live database. `APP_ENV=production` is a secondary seeder safeguard, not permission to copy test data into production.

For a new installation, generate the application key once before these steps, and record its secure recovery location. For an upgrade, keep the existing key. Inspect migration status and real table state if a migration fails. Retry the Phase 3 backfill only after repairing an incomplete schema; completed per-habit baselines are preserved on retry. Do not invent historical configurations or lifecycle intervals that were never recorded.

Switch the release pointer and gracefully reload the PHP process manager as required by the host to discard old code/configuration. Verify production assets, cached routes/views, database connectivity and logs before allowing traffic:

```bash
php artisan migrate:status
php artisan about --only=environment
php artisan up
```

Inspect only nonsecret output. The host-specific pointer/reload commands must be filled in and rehearsed before deployment. Have the prior code release ready if health checks fail.

## Acceptance after deployment

Use a controlled ordinary account and synthetic records; do not inspect other users' private data for a smoke test.

- Verify HTTPS and `/up`, correct asset loading, redirects, session persistence, Secure/HttpOnly/SameSite cookies and rejection of unknown hosts. `/up` alone does not establish database, mail or product health.
- Log in, create a private habit, record/reload a day, read its heatmap, and save/reload a journal. Check error logs without collecting the private text.
- With a second controlled account, verify private-account gating, request/approval, shared permitted activity, hidden private fields and denied owner editing. Verify access is removed on the next request after revocation.
- Complete a real password-reset delivery to an approved mailbox, use the link successfully, and ensure it cannot be reused. Avoid logging the token or full reset URL at the reverse proxy.
- Check mobile navigation, basic keyboard operation and the cache policy on both HTML and Livewire responses. Confirm `APP_DEBUG=false` error responses reveal no SQL/configuration.

Phase 6 activates explicit public/followers visibility across stored history for permitted signed-in users. Owners should understand current account privacy and habit visibility before rollout. Notes, reasons, journals and mood stay private; pending follows do not auto-accept when an account becomes public.

## Restore drill and rollback

A backup is not verified until restored. Restore to a new isolated database/service with no live traffic or outbound mail. Verify the actual connected database name before importing. Do not point browser/PHPUnit destructive tooling at that environment unless it is the explicitly guarded test database.

```bash
mysql --defaults-extra-file=/secure/path/restore-client.cnf RESTORE_DATABASE_NAME \
  < /secure/path/decrypted-backup.sql
```

Protect/remove the decrypted transient file according to the backup procedure. Start a compatible application release using the restored database and preserved application key, with mail captured locally. Compare table counts/checksums or equivalent complete data checks, then verify login, raw entries, versions/checkpoints, suspensions, journals, follows, analytics and privacy using synthetic/authorized data. Record backup size, export/import times, data comparison and application checks; compare the measured recovery time with the agreed objective. Keep the restore isolated until explicitly authorized for cutover.

For an application regression, stop writers if needed, switch to the prior compatible release, rebuild that release's config/route/view caches, reload PHP and repeat the smoke checks. Keep additive tables and raw data. Do not blindly run `migrate:rollback`: tracking rollback destroys history, journal rollback destroys journals, and follows rollback destroys approvals. If reverting across Phase 6, remove shared routes by using the prior owner-only code while retaining follows. Schema/data changes need a specific forward repair or tested restore plan.

Declare rollback on failed login/persistence, privacy violation, incomplete migration, or unacceptable error/latency regression. If data corruption is suspected, preserve evidence and stop further writes before restoring. A restore can lose writes since the backup; compare the actual recovery point and obtain the operator's cutover decision. Do not claim restoration undoes data already disclosed to another user.

## Operations and evidence

Name an owner for availability, errors, storage/backup failures and database capacity. Collect status, aggregate timing and sanitized error metadata; exclude private content, tokens, credentials and request/SQL bodies. Restrict access, rotate logs and define retention. Phase 7 database exception reporting deliberately omits SQL/bindings/driver messages while retaining severity, SQLSTATE and application operation.

Keep a release record with revision, tested commands, build/runtime versions, migration status, backup/restore evidence, browser/platform coverage, host-specific configuration and unresolved limitations. A local production-like cache/startup and restore rehearsal does not establish real TLS/proxy/mail delivery or a hosted CI run. Do not mark those checks complete without observing them.
