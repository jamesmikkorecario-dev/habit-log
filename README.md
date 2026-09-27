# habit.log

A mobile-first personal habit tracker for building positive habits and reducing unwanted ones, using a terminal-inspired visual language without terminal-style interaction.

**Stack:** Laravel 13, Livewire 4 (including Alpine.js), Tailwind CSS 4, Vite 7, MySQL 8.4, and PHPUnit. **Phase 0 — Project Foundation** and **Phase 1 — Profile + Theme Foundation** are complete for their current scope. Phase 2 has not started. See [the closeout report](docs/PHASE_1_CLOSEOUT.md) for verification results and limits.

Authenticated users can edit their username, display name, bio, account privacy, and Terminal Green theme preference at `/settings/profile`. The shell includes desktop/mobile navigation and a greeting using the preferred display name. Habits, journals, analytics, and followers belong to later phases.

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
```

Run tests with `php artisan test`, format PHP with `./vendor/bin/pint`, and build assets with `npm run build`. Development and test data use separate `habit_log` and `habit_log_test` databases.

See `docs/PROJECT_COMMANDS.md` for the complete verified command reference.

Documentation precedence is `AGENTS.md`, design guardrails, product requirements, architecture, database design, UI/UX specification, roadmap, then relevant ADRs. AI agents must begin with `AGENTS.md`.
