# ADR-0003: Privacy defaults

- **Status:** Accepted
- **Date:** 2026-09-11

## Context and decision

Habit and journal data can be sensitive. Habits default private; journals and mood are private; habit notes remain private; private accounts require approval before following. Enforcement is server-side.

## Consequences

Every read/write path needs authorization tests, and public output must exclude private fields. Relaxing a default requires explicit product approval, an ADR, migration/backfill analysis, security review, tests, and user-facing documentation. Rollback returns visibility to the more restrictive state.
