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
APP_ENV=testing php artisan migrate:fresh --force
```

Confirm `DB_DATABASE=habit_log_test` in `.env.testing` before running the destructive test migration command.

## Testing

```bash
php artisan test
php artisan test tests/Feature/Auth/AuthenticationTest.php
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
