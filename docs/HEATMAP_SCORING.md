# Heatmap scoring

Phase 4 scoring contract, confirmed and implemented on 2026-10-01; see [the closeout](PHASE_4_CLOSEOUT.md) for verification. [ADR-0005](adr/ADR-0005-ANALYTICS-SCORING.md) resolves the earlier avoidance-maximum conflict and defines optional goals, unlogged days, and consistency. [The Phase 4 plan](PHASE_4_PLAN.md) specifies implementation and acceptance checks.

Normalize eligible daily performance to `0.0` (worst/no successful progress) through `1.0` (best/goal satisfied). Higher always means better; display intensity maps monotonically to this score. Use historical configuration and raw entries, not the current mutable habit setup. N/A, out-of-range, suspended, and future dates are excluded.

- Boolean: completed `1.0`; missed `0.0`.
- Count with positive target: `min(actual / target, 1.0)`.
- Duration with positive target: `min(actual_minutes / target_minutes, 1.0)`.
- Checkpoint: completed historical snapshot items / all historical snapshot items.
- Avoidance with a maximum: `1.0` when `actual <= maximum`, otherwise `0.0`. A maximum of zero means abstinence; it is never a divisor.
- Count/duration/avoidance without a goal: recorded assessment completed `1.0`, partial `0.5`, missed `0.0`.
- Past eligible unlogged date: derived `0.0`, without inserting a missed entry. Unlogged today: pending, excluded until recorded or the local day ends.

Explicit N/A overrides retained values and checkpoint completions. Missing required measurements/referenced versions, invalid targets, and zero checkpoint denominators are unavailable data, never zero or success; exclude them from score denominators and show their count. Dates before the first effective configuration are ordinary inactive dates, not missing-version errors. Valid checkpoint configuration has 1–20 items. Goal-based numeric performance does not overwrite the independent recorded assessment. Test full success with exact input comparisons, not rounded display scores.

Overall profile activity is the equal-weight mean of eligible normalized habit scores, not a completion count. Include archived/ended habits' eligible history and exclude currently trashed habits. No eligible scores means neutral/no data. Today's aggregate is provisional while any habit remains pending; disclose pending and contributing counts. Do not round before aggregation.

Consistency is `100 × mean(eligible daily scores)`: partial progress receives proportional credit, past unlogged zeros participate, and neutral/pending/unavailable dates do not. For the profile, average daily profile scores equally by date, not pooled habit-days. The 30-day window is today minus 29 calendar days through today; yearly means the selected calendar year clipped at today. Empty denominators display no eligible data. N/A is counted separately (habit-days in profile totals).

Strict streaks count fully successful eligible days. Scores below 1 break them; N/A and inactive dates neither add nor break, and pending today preserves yesterday's run. Unavailable data interrupts a claimed strict run. Current and longest streaks use all available history through today, independent of the year selector. For overall streaks, every contributing habit must fully succeed; pending habits today prevent extending a successful run, while any recorded failure resets it. A date without eligible scores does not extend a run. The Phase 4 plan defines paused-habit labels and boundary examples.

Raw entries remain authoritative. Formulas may evolve only through an ADR and must never reverse the higher-score-is-better invariant. No persisted analytics cache is planned.
