# ADR-0002: Heatmap semantics

- **Status:** Accepted
- **Date:** 2026-09-11

## Context and decision

Positive and avoidance habits otherwise produce ambiguous colors. Darker/stronger cells universally mean a better outcome. Normalize eligible performance from `0.0` worst to `1.0` best; avoidance scoring reverses raw unwanted behavior. N/A/out-of-range data is neutral.

## Consequences

All scoring, legends, labels, tests, and aggregation must preserve monotonic “higher is better” semantics. Raw records remain authoritative. Formula changes require an ADR, regression tests, and documentation updates; rollback restores the prior derived formula without rewriting raw data.
