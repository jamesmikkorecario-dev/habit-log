# Design verification

## Product alignment

- [ ] Behavior matches `PRODUCT_REQUIREMENTS.md`.
- [ ] No `DESIGN_GUARDRAILS.md` rule is violated.
- [ ] No unrelated feature was added.

## Privacy / authorization

- [ ] Authorization is enforced server-side.
- [ ] Privacy defaults are preserved.
- [ ] Journal and mood privacy are preserved.
- [ ] Habit visibility is enforced for every access path.

## Data integrity

- [ ] Foreign keys and access-pattern indexes are present.
- [ ] Migrations are safe and reversible where practical.
- [ ] Transactions protect multi-record changes.
- [ ] Raw activity remains the source of truth.
- [ ] Destructive operations are confirmed and tested.

## Testing and UI

- [ ] Happy paths, validation, authorization, edge cases, and regressions are covered.
- [ ] UI is mobile-first, accessible, keyboard friendly, and has visible focus.
- [ ] Terminal appearance never requires terminal interaction.

## Documentation

- [ ] Schema docs change with schema.
- [ ] Architecture docs change with boundaries.
- [ ] Major decisions receive an ADR.
- [ ] Roadmap status is accurate.

## Reusable final review prompt

Review this implementation against `AGENTS.md`, `docs/DESIGN_GUARDRAILS.md`, and `docs/DESIGN_VERIFICATION.md`.

Report only concrete requirement violations, regressions, authorization/privacy risks, data-integrity issues, missing tests, security problems, and architecture drift.

Do not suggest unrelated features.
