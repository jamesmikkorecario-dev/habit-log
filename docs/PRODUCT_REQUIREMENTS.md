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

## Journal, categories, and social

One private journal per user/date stores text and optional private mood (initially 1–5). Habit notes are private even when activity is visible. System and custom categories are planned; deterministic aliases, normalized names, and curated keywords recommend an existing category before custom creation, while preserving user choice.

Followers are directional. Public accounts may accept immediately; private accounts require approval. Habit visibility is private (default), followers, or public and is enforced server-side. Comments, direct messages, and a social feed are excluded.

Profiles eventually show username, display name, bio, privacy, counts, selected theme, overall normalized heatmap, and permitted habits. Avatar is optional. Profile activity averages eligible normalized habit scores, excluding N/A and out-of-range habits; no eligible habits means neutral/no data.

Archive stops future tracking but preserves history. Trash is recoverable for about 30 days. Confirmed permanent deletion removes the habit, checkpoints, entries, notes, and related cache data and invalidates future cached profile activity.

## MVP exclusions

No native app, PWA, reminders, notifications, public API, AI features, gamification, React/Vue, Redis, WebSockets, queues without a demonstrated need, microservices, comments, or messaging.
