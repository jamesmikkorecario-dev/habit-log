# ADR-0005: Phase 4 normalized scoring and consistency

- **Status:** Accepted and implemented in Phase 4; see the closeout for verification
- **Date:** 2026-10-01
- **Related:** [ADR-0002](ADR-0002-HEATMAP-SEMANTICS.md), [ADR-0004](ADR-0004-DAILY-TRACKING-HISTORY.md), [Phase 4 plan](../PHASE_4_PLAN.md)

## Context

The product defines avoidance goals as acceptable maxima, but the original scoring document assigned zero performance at that maximum. A maximum of zero would cause division by zero in that formula; optional numeric goals and unlogged-day analytics lacked definitions. Phase 3 deliberately recorded raw measurements and independent personal assessments without resolving these analytics questions.

## Confirmed decisions

The user explicitly selected these four rules during Phase 4 planning:

1. Avoidance with a configured maximum scores 1 when actual is at/below the maximum, otherwise 0. With maximum zero, zero occurrences succeeds and any positive value fails.
2. Count, duration, and avoidance without a goal score from their recorded assessment: completed 1, partial 0.5, missed 0.
3. Past eligible dates without entries score 0 and break strict streaks; today without an entry stays pending until recorded or the local day ends.
4. Consistency means average normalized performance, giving partial progress proportional credit. Strict streaks require full success.

Keep boolean completed/missed scores, positive count/duration ratios capped at 1, and checkpoint completion ratios. Explicit N/A remains neutral, counted separately, excluded from denominators, and does not break strict streaks. Inactive and future dates are neutral. No eligible score means no data, not a failed day.

This supersedes only the gradual avoidance formula in the original scoring document and fills the previously undefined cases. It preserves ADR-0002's higher-is-better invariant: more unwanted behavior can never improve the score. At or below an acceptable limit, avoidance scores tie; the visual does not imply that more occurrences are better. The absence of a goal remains distinct from a maximum of zero.

## Planned derivation and presentation

Use the date's historical setup and raw entries; never rewrite assessments, measurements, checkpoint snapshots, or stored dates. For goal-based numeric habits, performance can differ from the owner's assessment. Explain both separately in the UI.

Overall daily performance is the equal-weight average of contributing habit scores. Consistency averages the contributing daily scores within the named window; overall consistency averages daily profile scores rather than pooling habit-days. Missing past entries contribute derived zeros without creating rows. Today's pending habits are excluded from its provisional score; show pending counts. The detailed streak, eligibility, window, accessibility, and query rules are implementation choices specified in the Phase 4 plan, not additional user-confirmed answers.

## Alternatives and consequences

The user chose an acceptable-limit rule over retaining the gradual avoidance formula and renaming the goal a failure threshold. This intentionally provides no partial credit for exceeding an avoidance limit. Goal-free assessment scoring was chosen over excluding those habits. Past unlogged failure was chosen over excluding past missing dates. Average performance was chosen over the percentage of fully successful days.

Add regression cases for limit equality/zero, goal-free assessments, contradictory assessment versus goal performance, pending-to-unlogged transitions, partial consistency credit, and neutral exclusions. Preserve ownership/privacy and derive analytics without a cache or schema change. No public profile access is authorized.

## Rollback and documentation

Update the scoring/product documents and Phase 4 plan together. Historical closeouts remain historical. No raw-data migration is required. A code rollback removes derived behavior without deleting activity; a subsequent formula change needs a new ADR and regression tests rather than silently restoring the contradictory old formula.
