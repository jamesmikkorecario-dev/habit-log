# Project commands

Run commands from the repository root in Ubuntu WSL. MySQL runs through Docker Desktop WSL integration.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d --wait
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
docker compose up -d --wait
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

CI starts MySQL with `docker compose up -d --wait` before `composer setup` and `composer ci:check`. The same Compose initialization creates both databases. CI runs on pull requests and pushes to `main` and `master`.

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
