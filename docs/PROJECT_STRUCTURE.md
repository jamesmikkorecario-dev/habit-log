# Project structure

- `app/`: Laravel application code; later organize by conventional models, policies, actions, and Livewire components as needed.
- `bootstrap/`, `config/`, `public/`, `routes/`, `storage/`: standard Laravel runtime/configuration boundaries.
- `database/`: migrations, factories, and seeders; framework/auth schema plus the Phase 1 user-profile extension.
- `resources/`: Blade/Livewire views, CSS, and JavaScript.
- `tests/`: PHPUnit feature and unit tests.
- `docs/`: product, architecture, workflow, ADRs, and reusable templates.
- `compose.yaml`: MySQL-only local development service.

Do not create parallel domain/repository layers without demonstrated need. Future feature placement should follow the conventions in the installed Laravel version.
