# Heatmap scoring

Normalize eligible daily performance to `0.0` (worst/no successful progress) through `1.0` (best/goal satisfied). Higher always means better; display intensity maps monotonically to this score. N/A and out-of-range habits are excluded.

- Boolean: completed `1.0`; missed `0.0`.
- Count target: `min(actual / target, 1.0)`.
- Duration target: `min(actual_minutes / target_minutes, 1.0)`.
- Checkpoint: completed eligible checkpoints / eligible checkpoints.
- Avoidance maximum: `1 - min(actual / maximum, 1.0)`; zero unwanted behavior is best and at/above maximum is worst.

Overall profile activity is the average of eligible normalized habit scores, not a completion count. No eligible habits means neutral/no data. Raw entries remain authoritative. Formulas may evolve only through an ADR and must never reverse the higher-score-is-better invariant.
