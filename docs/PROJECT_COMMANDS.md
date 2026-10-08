# Project commands

The commands below describe the native Ubuntu/WSL workflow. For application, Vite and database containers, use [the Docker guide](DOCKER.md); run Artisan/Composer/npm through `docker compose exec app`.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d --wait mysql
php artisan migrate
```

The defaults create/use `habit_log` and initialize the isolated `habit_log_test` database. If credentials or the forwarded port change, update `.env.testing` consistently. Never point tests at `habit_log`.

## Development

```bash
php artisan serve
npm run dev
composer run dev
```

The first two commands run Laravel and Vite separately. The provided Composer command invokes Laravel's combined development process manager.

## Database

```bash
docker compose up -d --wait mysql
docker compose stop
php artisan migrate
php artisan migrate:status
php artisan db:seed --class=CategorySeeder
APP_ENV=testing php artisan migrate:fresh --force
```

Confirm `DB_DATABASE=habit_log_test` in `.env.testing` before running the destructive test migration command.

After applying the Phase 2 migrations, run `php artisan db:seed --class=CategorySeeder` to install/update only the system-category catalog. It is idempotent and does not create a user.

Run `php artisan db:seed --class=TestAccountSeeder` to create the local test login: `test@example.com` / `password`. This seeder runs only in `local` and `testing` environments and leaves existing accounts unchanged, including passwords and profile fields. The account uses normal private defaults and has no elevated role. `php artisan db:seed` runs both the category and test-account seeders; both are safe to repeat.

## Testing

```bash
php artisan test
php artisan test tests/Feature/Auth/AuthenticationTest.php
php artisan test tests/Feature/Settings/ProfileTest.php
php artisan test tests/Feature/Categories --compact
php artisan test tests/Feature/Habits --compact
php artisan test tests/Feature/Tracking --compact
php artisan test tests/Unit/CategoryRecommenderTest.php --compact
composer ci:check
```

CI starts MySQL with `docker compose up -d --wait mysql` before `composer setup` and `composer ci:check`. The same Compose initialization creates both databases. CI runs on pull requests and pushes to `main` and `master`.

## Formatting

```bash
./vendor/bin/pint
./vendor/bin/pint --test
```

## Build

```bash
npm run build
```

## Useful inspection

```bash
php artisan about
php artisan route:list
docker compose ps
```

Phase 3 adds two migrations: tracking schema/timezone, then baseline history backfill. Run `php artisan migrate` with writers stopped during upgrade; do not reset the development database. Backfill is restart-safe per habit. Read `DATABASE_DESIGN.md` before migration recovery or rollback. The concurrency tests launch a guarded test-only PHP worker and require the existing local MySQL connection; run them serially with the rest of the suite.

Phase 4 focused verification:

```bash
php artisan test tests/Unit/Analytics --compact
php artisan test tests/Feature/Analytics --compact
```

Run these serially with the existing test database. The analytics snapshot test verifies both configured/connected database names before resetting test tables. No Phase 4 migration or new package installation is needed. Complete `composer ci:check`, `npm run build`, and installed-browser acceptance before closeout.

Phase 5 adds one journal migration. Apply it with `php artisan migrate` after preserving existing data; do not reset the development database. Focused verification:

```bash
php artisan test tests/Feature/Journals --compact
php artisan test tests/Feature/Tracking/MigrationTest.php --compact
composer ci:check
npm run build
```

The migration and concurrency tests reset only the guarded test database. Run them serially and finish before browser fixtures. Journal rollback drops journal data; keep the additive table when rolling back application code unless its contents have been preserved.

Phase 6 adds one follows migration, with no data backfill or new dependencies:

```bash
php artisan migrate --path=database/migrations/2026_10_04_000002_create_follows_table.php
php artisan migrate:status
php artisan test tests/Feature/Social --compact
composer ci:check
npm run build
php artisan route:list --path=people
```

Social concurrency/snapshot/migration tests reset only guarded `habit_log_test`; finish them before seeding categories or creating browser fixtures there. Follow-table rollback loses requests/approvals but must not change earlier data. Prefer application rollback retaining the additive table. Shared reads activate existing explicit public/followers preferences; review the Phase 6 privacy ADR and settings copy before rollout.

## Phase 7 hardening verification

```bash
php artisan test tests/Feature/Auth/SessionBoundaryTest.php tests/Feature/Auth/HardeningTest.php --compact
composer audit --locked
npm audit
composer ci:check
npm run build
```

The browser runner requires explicit opt-in because it resets only `habit_log_test`. It pins that database and clears an inherited database URL; its setup helper independently rejects conflicting URLs/cached configuration. An occupied preview port is refused. PHPUnit and browser runs share `storage/framework/testing.lock`; finish one before starting another. It serves production assets on loopback port 8017 with normal session/CSRF middleware, debug off, local mail capture, and a fresh isolated runtime/cache directory per run.

On Linux with a compatible installed Chromium executable:

```bash
HABIT_LOG_E2E=1 E2E_BROWSER_EXECUTABLE=/path/to/chrome npm run test:e2e
```

On this WSL/Windows setup, use installed Windows Node and Chrome:

```bash
HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e
```

The Windows runner selects `C:\Program Files\Google\Chrome\Application\chrome.exe`. Custom browser settings passed to Windows Node require adding the relevant variable names to `WSLENV`; the Linux runner still owns fixture setup and server cleanup. No system library/browser download occurs in application setup or this command. CI explicitly runs `npx playwright install --with-deps chromium` in its own runner before browser tests. `E2E_BROWSER=firefox` or `webkit` selects an available compatible runtime; do not claim cross-browser verification from configuration alone.

Run larger measurements separately from the default suite:

```bash
php artisan test tests/Performance/PerformanceTest.php --compact
```

This guarded test resets the test database, generates synthetic histories, and records scoped measurements in ignored `storage/app/phase7-checks/performance.json`. It shares the same process lock. Browser screenshots/results and local mail/server logs also stay under the ignored evidence directory; CI uploads only synthetic failure screenshots, not logs, traces or authenticated storage. Use [the deployment runbook](DEPLOYMENT.md) for release/restore procedures; these test commands are never production commands.

After a populated test/browser fixture exists, the local Compose recovery drill is:

```bash
HABIT_LOG_RESTORE_REHEARSAL=1 python3 tests/Fixtures/restore-rehearsal.py
```

It takes the shared test lock, exports only `habit_log_test`, encrypts/decrypts the synthetic export with GPG, imports into a newly named `habit_log_restore_*` database, compares all table counts/checksums and application analytics/journal policies, then revokes its temporary read grant and drops only that new database. It requires the local Compose root account and installed GPG; it never exports/resets development data. The temporary encryption key is destroyed after the drill, so the retained encrypted artifact is evidence, not a reusable production backup. Exact measurements appear in `storage/app/phase7-checks/restore.json`.
