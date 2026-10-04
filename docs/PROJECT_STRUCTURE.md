# Project structure

- `app/`: conventional models/policies, habit type/visibility/entry-status enums, shared habit form validation, transactional habit/configuration/lifecycle/entry Actions, shared tracking-date eligibility, and a deterministic category recommender. No repository or parallel domain layer.
- `bootstrap/`, `config/`, `public/`, `routes/`, `storage/`: standard Laravel runtime/configuration boundaries.
- `database/`: framework/auth and profile schema plus Phase 2 categories, aliases, habits, and checkpoint configuration plus Phase 3 timezone, effective configuration, activity, suspension schema, and baseline backfill; factories and the dedicated idempotent category seeder.
- `resources/`: Blade/Livewire single-file pages for dashboard, settings, categories, and habit list/configuration/detail and daily tracking/editor/history; CSS and JavaScript.
- `tests/`: PHPUnit feature and unit tests.
- `docs/`: product, architecture, workflow, ADRs, and reusable templates.
- `compose.yaml`: MySQL-only local development service.

Do not create parallel domain/repository layers without demonstrated need. Future feature placement should follow the conventions in the installed Laravel version.

Tracking tests live in `tests/Feature/Tracking`; their fixtures use real Actions. `tests/Fixtures/tracking-save-worker.php` is a test-only subprocess for a controlled MySQL lock-wait regression, guarded to `habit_log_test`.

Phase 4 analytics lives in `app/Services/Analytics` (scoring, historical projections, aggregation, and streaks). `app/Livewire/ActivityHeatmap.php` and `resources/views/livewire/activity-heatmap.blade.php` supply the shared authenticated interface; `resources/views/components/activity-heatmap.blade.php` renders its calendar. The dashboard and habit detail embed it. Unit tests are in `tests/Unit/Analytics`; integration/privacy/calendar/concurrent-read tests are in `tests/Feature/Analytics`. No new schema or dependency is added.

Phase 5 adds `app/Models/Journal.php`, `app/Policies/JournalPolicy.php`, `app/Livewire/Forms/JournalForm.php`, and `app/Actions/Journals/{SaveJournal,DeleteJournal}.php`. The journal editor is `resources/views/pages/journal/⚡edit.blade.php`. A new migration and `JournalFactory` supply storage/test fixtures without adding seeded private journals. Tests live under `tests/Feature/Journals`; `tests/Fixtures/journal-write-worker.php` is a guarded concurrency-test subprocess.

`resources/js/app.js` supplies the journal Back/Forward guard using history positions only; it never stores private text or mood.
