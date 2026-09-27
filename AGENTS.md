# habit.log agent guide

Read this file before making changes. Source-of-truth precedence is:

1. `AGENTS.md`
2. `docs/DESIGN_GUARDRAILS.md`
3. `docs/PRODUCT_REQUIREMENTS.md`
4. `docs/ARCHITECTURE.md`
5. `docs/DATABASE_DESIGN.md`
6. `docs/UI_UX_SPEC.md`
7. `docs/ROADMAP.md`
8. Relevant accepted ADRs

If sources conflict, stop and report the conflict; never silently choose. Verify completed work against `docs/DESIGN_VERIFICATION.md`.

## Working rules

- Follow Laravel conventions and keep a modular monolith. Prefer framework-native solutions.
- Keep scope to the requested roadmap phase. Do not anticipate future features or add abstractions without a current need.
- Enforce authentication, ownership, and visibility server-side with middleware and Policies/Gates. New habits default private; journals, mood, and habit notes are private.
- Preserve raw activity records. Derive analytics initially; cache only after profiling.
- Use foreign keys, access-pattern indexes, reversible migrations where practical, and transactions for multi-record state changes.
- Validate at Laravel/Livewire boundaries. Keep business logic out of Blade and extract Actions/Services only when reusable, transactional, complex, or independently testable.
- Add focused tests for behavior, validation, authorization/privacy, and important edge cases. Run the full relevant suite, Pint, and frontend build.
- Inspect `git status` and diffs before work. Preserve unrelated changes. Never commit or push unless explicitly requested.
- Never expose secrets, weaken CSRF/session protections, log credentials, or rely on hidden UI for authorization.

## AI workflow

Follow `docs/AI_WORKFLOW.md`: identify requirements, affected files, database and authorization impact, tests, and documentation before coding. Implement the smallest coherent change. Report behavior, files, migrations, commands, results, limitations, docs, and a suggested commit message afterward.
