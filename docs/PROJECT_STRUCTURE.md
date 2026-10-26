# Project structure

- `app/`: conventional models/policies, habit type/visibility/entry-status enums, shared habit form validation, transactional habit/configuration/lifecycle/entry Actions, shared tracking-date eligibility, and a deterministic category recommender. No repository or parallel domain layer.
- `bootstrap/`, `config/`, `public/`, `routes/`, `storage/`: standard Laravel runtime/configuration boundaries.
- `database/`: framework/auth and profile schema plus Phase 2 categories, aliases, habits, and checkpoint configuration plus Phase 3 timezone, effective configuration, activity, suspension schema, and baseline backfill; factories and the dedicated idempotent category seeder.
- `resources/`: Blade/Livewire single-file pages for dashboard, settings, categories, and habit list/configuration/detail and daily tracking/editor/history; CSS and JavaScript.
- `tests/`: PHPUnit feature and unit tests.
- `docs/`: product, architecture, workflow, ADRs, and reusable templates.
- `Dockerfile`, `.dockerignore`, `compose.yaml`, `docker/`: development app/Vite/MySQL services and a production application image, Apache/PHP configuration and database initialization. See [the Docker guide](DOCKER.md).

Do not create parallel domain/repository layers without demonstrated need. Future feature placement should follow the conventions in the installed Laravel version.

Tracking tests live in `tests/Feature/Tracking`; their fixtures use real Actions. `tests/Fixtures/tracking-save-worker.php` is a test-only subprocess for a controlled MySQL lock-wait regression, guarded to `habit_log_test`.

Phase 4 analytics lives in `app/Services/Analytics` (scoring, historical projections, aggregation, and streaks). `app/Livewire/ActivityHeatmap.php` and `resources/views/livewire/activity-heatmap.blade.php` supply the shared authenticated interface; `resources/views/components/activity-heatmap.blade.php` renders its calendar. The dashboard and habit detail embed it. Unit tests are in `tests/Unit/Analytics`; integration/privacy/calendar/concurrent-read tests are in `tests/Feature/Analytics`. No new schema or dependency is added.

Phase 5 adds `app/Models/Journal.php`, `app/Policies/JournalPolicy.php`, `app/Livewire/Forms/JournalForm.php`, and `app/Actions/Journals/{SaveJournal,DeleteJournal}.php`. The journal editor is `resources/views/pages/journal/⚡edit.blade.php`. A new migration and `JournalFactory` supply storage/test fixtures without adding seeded private journals. Tests live under `tests/Feature/Journals`; `tests/Fixtures/journal-write-worker.php` is a guarded concurrency-test subprocess.

`resources/js/app.js` supplies the journal Back/Forward guard using history positions only; it never stores private text or mood.

Phase 6 adds `Follow`, `FollowStatus`, user follow relationships, `FollowPolicy`/`UserPolicy`, a distinct habit shared-read ability, and `SocialAccess` for filtered queries. Transactional Actions live in `app/Actions/Follows` and `app/Actions/Profiles`. `app/Livewire/Concerns/InteractsWithFollows.php` supports the People index/profile components; `resources/views/pages/people` contains relationship management, profile, and read-only habit pages. `resources/views/components/follow-confirmation.blade.php` supplies the removal dialog. Existing analytics/heatmap code handles explicit viewer/subject reads without duplicating scoring.

The follows migration and factory are under `database`; no social seeder is added. `tests/Feature/Social` covers relationships, matrix/payload privacy, shared analytics, migration, concurrency, snapshots, and representative queries. `tests/Fixtures/social-write-worker.php` is a guarded MySQL subprocess fixture. Local browser/query evidence lives in ignored `storage/app/phase6-checks`.

Phase 7 adds `app/Http/Middleware/WebSecurity.php`, `app/Livewire/Concerns/RequiresSameUser.php`, authentication hardening regressions, `tests/bootstrap.php` for the test lock, `tests/Browser` with Playwright configuration, guarded browser/restore fixtures, and the separately invoked `tests/Performance` suite. Test tooling is development-only; no application schema, role layer, queue, or persisted analytics cache is added. Deployment/recovery instructions live in [DEPLOYMENT.md](DEPLOYMENT.md); findings, results and release gates are recorded in [the closeout](PHASE_7_CLOSEOUT.md).

Container runtime verification lives in `tests/Containers/smoke.sh` and [the Docker guide](DOCKER.md#verification-record). CI builds the production target and executes the disposable-image smoke check after application/browser verification.

Account management Phase 1 adds `app/Actions/Accounts/ReplacePassword.php`, Fortify `UpdateUserPassword`, and a bound `PasswordUpdateResponse`. The native Security page and shared Settings navigation live under `resources/views/pages/settings` and `resources/views/components`. PHP/browser regressions and the guarded `password-write-worker.php` fixture cover credential/session concurrency; no migration or new dependency is added.
