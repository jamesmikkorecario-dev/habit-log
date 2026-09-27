# Design guardrails

Stable rules for implementation and review:

- **DG-001:** Darker/stronger heatmap cells always represent better performance.
- **DG-002:** Terminal appearance never requires terminal knowledge.
- **DG-003:** New habits default to private.
- **DG-004:** Journals and mood are private; habit notes are initially private.
- **DG-005:** Social relationships use directional followers.
- **DG-006:** Private accounts require follow approval.
- **DG-007:** Comments are outside current scope.
- **DG-008:** Each habit has exactly one category.
- **DG-009:** Custom category creation recommends matching existing categories deterministically.
- **DG-010:** N/A dates are recorded and neutral.
- **DG-011:** Dates outside a habit's active range are neutral.
- **DG-012:** Overall heatmap performance averages eligible normalized scores, not completion counts.
- **DG-013:** Archive preserves historical data.
- **DG-014:** Confirmed permanent deletion removes historical habit data and relevant caches.
- **DG-015:** Each habit eventually has an individual yearly heatmap.
- **DG-016:** Profiles eventually have an overall yearly heatmap.
- **DG-017:** The application is mobile-first.
- **DG-018:** Goals are optional where appropriate.
- **DG-019:** Checkpoint labels are user configurable.
- **DG-020:** Strict streaks coexist with long-term consistency.
- **DG-021:** Authorization and visibility are enforced server-side using middleware and Policies/Gates.
- **DG-022:** Raw activity records remain the source of truth; derived analytics are not authoritative.
- **DG-023:** Migrations are reversible where practical and protect data with foreign keys and access-pattern indexes.
- **DG-024:** Multi-record state changes use transactions.
- **DG-025:** Behavior, validation, authorization/privacy, and important edge cases receive tests.
- **DG-026:** Prefer Laravel conventions; add Actions/Services only for genuinely reusable, transactional, complex, or independently testable logic.
