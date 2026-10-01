# Architecture

habit.log is a Laravel modular monolith. PHP, Composer, Node/npm, Vite, and the Laravel process run natively in Ubuntu WSL; Docker hosts only development MySQL.

Livewire components own UI state and interaction, with Alpine.js for small client-side behavior. Blade renders views but does not contain substantial business logic. Validation occurs at Laravel/Livewire boundaries. Policies/Gates and authentication middleware enforce authorization server-side.

Use controllers/components, Eloquent models, form validation, and policies before adding layers. Extract an Action/Service only for reusable, transactional, complex, or independently testable work—not every CRUD operation. Use database transactions for multi-record state changes.

MySQL uses foreign keys and indexes driven by real queries. Migrations should be reversible where practical. Activity source records remain authoritative; normalized heatmaps and analytics are derived until profiling justifies a cache, with explicit invalidation rules.

Phase 3 uses transactional habit/entry Actions for configuration versions, cancellation, lifecycle intervals, and recording. `TrackingDate` is shared eligibility/version resolution used by policies, pages, and saving. `HabitEntryForm` supplies boundary validation reused by the entry Action. Reads remain scoped Eloquent queries; daily summaries eager-load only the relevant date's latest setup and entry. No repository, event-sourcing system, analytics cache, or API layer is added. See [ADR-0004](adr/ADR-0004-DAILY-TRACKING-HISTORY.md) for calendar/history semantics.

Phase 4 derives analytics through `HabitScore`, `ActivityAnalytics`, and `StreakCalculator` under `app/Services/Analytics`. The owner-only `ActivityHeatmap` Livewire component is reused on the dashboard and habit detail page. Blade renders prepared results; Alpine handles calendar focus and details. Reports use a single MySQL repeatable-read transaction with nonlocking reads, including owner timezone, configuration, entries, and checkpoint completions. A two-connection regression verifies that a concurrent committed edit cannot mix old parent and new child values.

History is processed in 50-habit and 500-entry batches. Configuration/suspension boundaries and entry overrides form date segments; absent days are not individually materialized across lifetime history. All history is read for lifetime streaks; only the selected year's daily cells and selected-year entry details are retained for rendering. The same segments calculate trailing/calendar-year consistency and profile daily means. No cache, new dependency, repository, queue, or analytics schema is introduced.
