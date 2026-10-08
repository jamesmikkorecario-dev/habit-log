# ADR-0001: Initial stack

- **Status:** Accepted; native-only runtime choice superseded by [ADR-0007](ADR-0007-CONTAINER-RUNTIME.md).
- **Date:** 2026-09-11

## Context and decision

The product needs a maintainable, interactive, mobile-first web foundation with minimal operational complexity. Use Laravel 13, Livewire, Alpine.js, Tailwind CSS, Vite, and MySQL as a modular monolith. Laravel tooling runs natively in Ubuntu WSL and Docker runs MySQL only.

## Rationale and consequences

The stack follows Laravel conventions, provides server-driven interaction and accessible HTML, and avoids a separate SPA/API. Team knowledge must cover Laravel/Livewire. Scaling begins within the monolith. Reconsidering the boundary requires an ADR, migration plan, security review, and full regression testing.
