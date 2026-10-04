# Security and privacy

Use Laravel session authentication, password hashing, CSRF middleware, validation, output escaping, and authenticated route middleware without weakening defaults. Authorize ownership and visibility server-side via Policies/Gates; hidden controls are never sufficient.

New habits default private. Journals, mood, and habit notes are private. Private-account follower requests require approval. Public/follower-visible activity never exposes private notes. Avoid logging passwords, tokens, journal content, mood, or private notes.

Keep secrets only in ignored environment files and document variable names—not real credentials—in `.env.example`. Use a distinct testing database, validate its name before destructive migrations, and never point tests at the development database. Permanent deletion later requires explicit confirmation and complete related-data removal.

Phase 3 daily routes, history, and Livewire mutations remain owner-only for every stored habit visibility. Notes and N/A reasons are private, hidden in model serialization, and excluded from summary queries. Server-owned IDs and revisions are locked in components and revalidated in transactional Actions; neither hidden buttons nor optimistic tokens grant access. Trashed habits cannot be queried through tracking routes. Recording/configuration/lifecycle writes share the same user-then-habit lock order.

Phase 4 analytics remains owner-only for all visibility/account preferences. The embedded Livewire heatmap authenticates and authorizes on every render/action, locks its habit identity, and validates year/date/page inputs. Query scopes and existing Habit policies exclude foreign/trashed records. Analytics projects no notes or N/A reasons into queries, results, HTML, or hydration state. It performs nonlocking, consistent reads without modifying raw data or introducing public routes.

Phase 5 journal reads/writes require authentication and an owner policy regardless of account privacy. The Livewire editor locks owner/date/entry/revision/timezone state and rejects a hydrated snapshot if the session user changed. Every Action rechecks owner-scoped state, current timezone, and date under a transaction. Expected entry ID plus revision detects deleted/recreated rows. Confirmation is required before the editor calls deletion; the deletion Action always enforces ownership and concurrency independently.

Content and mood are hidden from general model serialization, absent from unrelated views/analytics, and never placed in URLs or browser storage. Only the authorized editor includes those fields in HTML/Livewire state. Save Actions turn database failures into generic validation feedback without logging SQL text/bindings or chaining the private exception. Session/CSRF middleware remains unchanged. Physical journal deletion has no application recovery and does not enable permanent habit deletion.
