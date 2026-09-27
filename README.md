# habit.log

A mobile-first personal habit tracker for building positive habits and reducing unwanted ones, using a terminal-inspired visual language without terminal-style interaction.

**Stack:** Laravel 13, Livewire 4 (including Alpine.js), Tailwind CSS 4, Vite 7, MySQL 8.4, and PHPUnit. **Phases 0–2** are complete for their current scope; Phase 3 daily tracking has not started. See [the Phase 2 closeout](docs/PHASE_2_CLOSEOUT.md) for implementation, verification, and remaining limits, and [the plan](docs/PHASE_2_PLAN.md) for scope decisions.

Authenticated users can edit their profile at `/settings/profile`, explore system/personal categories at `/categories`, and manage their habit configurations at `/habits`. All five habit types support configuration, with private defaults and owner-only access. Archive, trash, and restore preserve configuration. Permanent deletion/automatic expiry are deferred; daily tracking, journals, analytics, and sharing belong to later phases.

Development tooling runs natively in Ubuntu under WSL; keep the repository on the Linux filesystem. MySQL alone runs in Docker Desktop via WSL integration.

## Local setup

Use Ubuntu 24.04 under WSL2 and keep the repository on its native Linux filesystem. PHP, Composer, Node/npm, Laravel, and Vite run in Ubuntu. Docker Desktop with WSL integration runs MySQL only.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d --wait
php artisan migrate
php artisan db:seed --class=CategorySeeder
```

Run tests with `php artisan test`, format PHP with `./vendor/bin/pint`, and build assets with `npm run build`. Development and test data use separate `habit_log` and `habit_log_test` databases.

For a local test login, run `php artisan db:seed --class=TestAccountSeeder`, then sign in at `/login` with **test@example.com** / **password**. The account is created only in local/testing environments. Repeated seeding preserves an existing account, including its password and profile. `php artisan db:seed` also includes this account alongside system categories.

See `docs/PROJECT_COMMANDS.md` for the complete verified command reference.

Documentation precedence is `AGENTS.md`, design guardrails, product requirements, architecture, database design, UI/UX specification, roadmap, then relevant ADRs. AI agents must begin with `AGENTS.md`.
