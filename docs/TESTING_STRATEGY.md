# Testing strategy

Use the single framework supplied by the Laravel 13 Livewire starter (PHPUnit). Prefer feature tests for HTTP/Livewire behavior and unit tests for isolated scoring or domain rules when those exist.

Phase 0 covers successful and invalid registration, successful and invalid login, logout, guest denial/redirect for the application page, and authenticated access. Database tests use only `habit_log_test`, reset safely, and remain repeatable.

CI runs on pull requests and pushes to `main` or `master`. It starts the repository's MySQL Compose service with a health wait before application setup; the initialization script creates `habit_log_test` alongside `habit_log`. Composer runs migrations for setup, then the test command runs Pint and PHPUnit. Setup also builds frontend assets.

Each later feature tests happy paths, validation, server-side authorization/privacy, lifecycle and date boundaries, destructive behavior, and regressions. Run focused tests while developing, then the full suite, Pint, and production asset build before handoff.
