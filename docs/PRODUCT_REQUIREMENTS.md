# Product requirements

## Product and platform

habit.log is a private-by-default, mobile-first web application for daily habits users want to build and behaviors they want to reduce or stop. GitHub contribution graphs, developer tools, and terminals inspire appearance only; every interaction uses ordinary accessible controls and requires no terminal knowledge.

## Planned tracking

- Types: boolean, count, duration (stored consistently in minutes), configurable checkpoint, and avoidance.
- Goals are optional when meaningful: numeric for count/duration, configured checkpoints for checkpoint habits, and an acceptable maximum for avoidance.
- Each habit has one category, start date, optional end date, visibility, individual yearly heatmap, and active/archived/trashed lifecycle.
- Entries support completed, partial, missed, and not applicable. N/A is recorded with an optional reason, excluded from success/failure and consistency, does not break strict streaks, and is counted separately.
- Dates outside the active range are neutral. Strict current/longest streaks coexist with 30-day and yearly consistency.
- Raw entries remain authoritative. Darker heatmap cells always mean a better day.

Phase 4 planning decisions (2026-10-01): avoidance succeeds at/below its configured maximum, including abstinence for a zero maximum. Goal-free numeric habits use completed/partial/missed assessments scored as 1/0.5/0. Past eligible unlogged dates score zero and break streaks; unlogged today stays pending. Consistency averages normalized performance so partial progress receives proportional credit; strict streaks count fully successful eligible days. See [ADR-0005](adr/ADR-0005-ANALYTICS-SCORING.md) and [the scoring contract](HEATMAP_SCORING.md). These decisions preserve raw entries; implementation evidence is recorded in [the Phase 4 closeout](PHASE_4_CLOSEOUT.md).

## Journal, categories, and social

One private journal per user/date stores text and optional private mood (initially 1–5). Habit notes are private even when activity is visible. System and custom categories are planned; deterministic aliases, normalized names, and curated keywords recommend an existing category before custom creation, while preserving user choice.

Phase 5 planning decisions (2026-10-04): journal text is required and mood is optional; mood-only entries are rejected. The owner can explicitly confirm permanent deletion of the selected date's journal and mood. This does not authorize habit permanent deletion or automatic expiry. See [the Phase 5 plan](PHASE_5_PLAN.md) and [implementation closeout](PHASE_5_CLOSEOUT.md).

Followers are directional. Public accounts may accept immediately; private accounts require approval. Habit visibility is private (default), followers, or public and is enforced server-side. Comments, direct messages, and a social feed are excluded.

Profiles eventually show username, display name, bio, privacy, counts, selected theme, overall normalized heatmap, and permitted habits. Avatar is optional. Profile activity averages eligible normalized habit scores, excluding N/A and out-of-range habits; no eligible habits means neutral/no data.

Archive stops future tracking but preserves history. Trash is recoverable for about 30 days. Confirmed permanent deletion removes the habit, checkpoints, entries, notes, and related cache data and invalidates future cached profile activity.

## MVP exclusions

No native app, PWA, reminders, notifications, public API, AI features, gamification, React/Vue, Redis, WebSockets, queues without a demonstrated need, microservices, comments, or messaging.
