# Local habit demo data

Explicit developer tool; never called by normal seeders, migrations, startup or deployment. Only `APP_ENV=local` is accepted. No schema, dependencies, analytics, account credentials, security settings or sessions are changed.

Populate an **existing, explicitly selected** account:

```sh
php artisan demo:habits --email=test@example.com
```

The command uses `HabitForm`, `SaveHabit` and `SaveHabitEntry`, including their validation, policies, historical versions and checkpoint snapshots. It requires the existing system categories. All writes occur in one transaction under the selected account lock. No database reset or account creation occurs.

Ten private habits have `[Demo]` names and a unique synthetic marker in their descriptions: two each of Yes/No, Count, Duration, Avoidance and Checkpoints. Nine months of history use staggered start dates, daily/weekday/weekly practices, explicit N/A rest days, unlogged days, misses, partial progress and successful runs. This represents schedules using the existing N/A semantics; it adds no recurring-schedule feature. Recent successes and six recorded habits today make the dashboard useful while remaining habits stay pending. Existing habits still contribute to aggregate statistics.

Outcomes are deterministic from habit index and calendar date, anchored to the account's local date on first execution. The private manifest fixes that date and records the exact created graph's checksum. A rerun does nothing; it does not append new days or overwrite edits. Cleanup followed by population on the same local date recreates the same outcomes, with new database IDs/timestamps and a new marker.

Safely remove the demo data:

```sh
php artisan demo:habits --email=test@example.com --cleanup
```

The command asks for confirmation. For an intentional noninteractive cleanup, append `--yes`. **This permanently deletes the demo habits and their history.** It does not delete the account, existing habits, categories or any unrelated records. Cleanup was tested only against isolated synthetic test data; the populated local dataset has been left in place for inspection.

Manifests and locks live under `storage/app/private/demo-habits/`, outside version control. Account identity includes ID, creation timestamp and the recovery UUID when available, so older local schemas need no migration. The manifest uses a database-specific key. Keep it with this local database: copying or losing it prevents automatic cleanup. The command refuses unmatched/changed graphs, including added entries, edited habits or cross-habit references; it never adopts records by name or offers a destructive override. Do not delete or edit the manifest to bypass a refusal. Review modified demo data manually before deciding what to retain. If a DB commit or manifest operation is interrupted, retain the manifest and inspect the outcome; the next run fails closed on a mismatch.

## Local population and verification — 2026-10-31

The local `habit_log` database had one account, `test@example.com`, matching `TestAccountSeeder`; its ID/email were discovered rather than assumed. It already contained one habit, three entries and five sessions. Those records were preserved.

- Created **10 habits, 2,418 entries and 2,008 checkpoint-completion rows**, anchored to **2026-10-31**. History begins 2026-01-31, with later starts for some habits.
- Rerunning the exact populate command reported no changes and the same counts.
- One-way hashes verified every pre-existing row in every local database table remained identical, including the account, existing habit/entries, categories and sessions. No other account existed locally; focused tests also verify preservation of a separate account and its habit.
- The actual account report contains all five intensity bands `0`–`4`, plus neutral dates, zero unavailable data, a **14-day longest aggregate streak**, **50.80% selected-year consistency** and **59.03% 30-day consistency**. Its current aggregate streak is zero because existing unlogged activity still participates; individual demo habits retain recent successful streaks. No original records were filled in to change these results.
- Dashboard, today's daily overview, all ten habit detail pages and all ten entry-history pages rendered successfully using the actual local data and in-memory authentication/session storage.

Final focused test, formatting, build and visual verification results are recorded below when complete. Generated manifests, row hashes, HTML, screenshots and logs remain ignored; no credentials or raw existing records are included in this document.

Changed source files: `app/Console/Commands/DemoHabitsCommand.php`, `tests/Feature/Demo/DemoHabitsTest.php`, and this guide. The pre-existing account-deletion documentation edit is preserved. No commit, push, PR, merge or deployment was performed.

Suggested commit: `feat: add explicit local habit demo command`.
