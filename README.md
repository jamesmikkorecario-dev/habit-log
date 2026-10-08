# habit.log

A mobile-first personal habit tracker for building positive habits and reducing unwanted ones, using a terminal-inspired visual language without terminal-style interaction.

**Stack:** Laravel 13, Livewire 4 (including Alpine.js), Tailwind CSS 4, Vite 7, MySQL 8.4, and PHPUnit. **Phases 0–6** are complete for their current scope. See [the Phase 4 closeout](docs/PHASE_4_CLOSEOUT.md) for delivered behavior, verification, findings, and limits, and [the scoring contract](docs/HEATMAP_SCORING.md) for heatmaps, streaks, and consistency. Phase 5 adds the private daily journal; see [its closeout](docs/PHASE_5_CLOSEOUT.md). Phase 6 adds directional followers and permitted signed-in profiles at `/people`; see [its closeout](docs/PHASE_6_CLOSEOUT.md). Phase 7 hardening is implemented and locally verified; [its closeout](docs/PHASE_7_CLOSEOUT.md) records results and remaining release gates.

Authenticated users can edit their profile at `/settings/profile`, explore system/personal categories at `/categories`, and manage their habit configurations at `/habits`. All five habit types support configuration, with private defaults and owner-only access. Daily recording is available at `/tracking`, with private notes, explicit N/A, and owner-only entry history. Profile settings include a timezone defaulting to UTC. Dated setup and archive/trash intervals preserve historical activity. The dashboard and habit details now show owner-only yearly heatmaps, lifetime strict streaks, and 30-day/yearly consistency derived from historical activity. Private journaling is available at `/journal`, with required text, optional mood, date navigation, and confirmed deletion. People provides follow requests, approvals, and read-only profiles/habits filtered by account privacy and habit visibility. Journals, mood, and notes remain private. Permanent habit deletion/automatic expiry remain deferred.

Docker Compose runs Laravel/Apache, Vite and MySQL for local development. A separate production image includes the application and compiled assets. See [the Docker guide](docs/DOCKER.md) for setup, persistence and deployment boundaries. The native Ubuntu/WSL workflow remains available.

## Docker development

For this already configured checkout:

```bash
docker compose build app
docker compose up -d --wait
```

Open http://localhost:8000. For a fresh checkout, first follow [Docker setup](docs/DOCKER.md#local-development) to create the environment/key and explicitly apply migrations. The same guide covers container tests, dependency updates, persistent data and the production image.

## Native local setup

Use Ubuntu 24.04 under WSL2 and keep the repository on its native Linux filesystem. PHP, Composer, Node/npm, Laravel, and Vite run in Ubuntu. Start only the MySQL Compose service for this native workflow.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d --wait mysql
php artisan migrate
php artisan db:seed --class=CategorySeeder
```

Run tests with `php artisan test`, format PHP with `./vendor/bin/pint`, and build assets with `npm run build`. Development and test data use separate `habit_log` and `habit_log_test` databases.

For a local test login, run `php artisan db:seed --class=TestAccountSeeder`, then sign in at `/login` with **test@example.com** / **password**. The account is created only in local/testing environments. Repeated seeding preserves an existing account, including its password and profile. `php artisan db:seed` also includes this account alongside system categories.

See `docs/PROJECT_COMMANDS.md` for the complete verified command reference.

Documentation precedence is `AGENTS.md`, design guardrails, product requirements, architecture, database design, UI/UX specification, roadmap, then relevant ADRs. AI agents must begin with `AGENTS.md`.
