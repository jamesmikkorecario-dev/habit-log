# Project structure

- `app/`: conventional models/policies, habit type/visibility enums, shared habit form validation, transactional `Actions/Habits/SaveHabit`, and a deterministic category recommender. No repository or parallel domain layer.
- `bootstrap/`, `config/`, `public/`, `routes/`, `storage/`: standard Laravel runtime/configuration boundaries.
- `database/`: framework/auth and profile schema plus Phase 2 categories, aliases, habits, and checkpoint configuration; factories and the dedicated idempotent category seeder.
- `resources/`: Blade/Livewire single-file pages for dashboard, settings, categories, and habit list/configuration/detail; CSS and JavaScript.
- `tests/`: PHPUnit feature and unit tests.
- `docs/`: product, architecture, workflow, ADRs, and reusable templates.
- `compose.yaml`: MySQL-only local development service.

Do not create parallel domain/repository layers without demonstrated need. Future feature placement should follow the conventions in the installed Laravel version.
