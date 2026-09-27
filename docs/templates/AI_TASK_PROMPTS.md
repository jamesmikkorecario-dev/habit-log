# Reusable AI task prompts

## Plan only
Read the source-of-truth documents and inspect current changes. Produce a bounded plan with requirements, files, database/authorization impact, tests, docs, and risks. Do not edit files.

## Implement
Implement the smallest coherent requested feature according to the source of truth. Add tests, run verification, update affected docs, and do not commit.

## Review
Use the final review prompt in `docs/DESIGN_VERIFICATION.md`; report concrete findings by severity with file references.

## Fix review findings
Fix only confirmed findings, add regression tests, run relevant/full verification, and report remaining issues.

## Database migration
Plan keys, constraints, indexes, deletion behavior, reversibility, rollout, test-database safety, and tests before implementing.

## UI implementation
Implement the specified mobile-first accessible UI with semantic controls, keyboard/focus support, and no terminal knowledge requirement.

## Security/privacy audit
Audit authentication, server-side authorization, privacy defaults, exposure/logging, CSRF, validation, and destructive actions; report only concrete risks.

## Bug fix
Reproduce, identify root cause, add a failing regression test, make the smallest fix, and verify without unrelated refactoring.

## Final verification
Run `docs/DESIGN_VERIFICATION.md`, project tests, formatter, build, migration checks, security checks, and Git diff/status; report exact results and limitations.
