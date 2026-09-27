# Database design

Phase 0 implements `users`, `password_reset_tokens`, and `sessions`. Phase 1 extends `users` with the profile fields below. Phase 2 adds categories, aliases, habits, and checkpoint configuration. Tracking, journal, and follower tables remain planned. Habit type and visibility use PHP backed enums with string database columns, boundary validation, and Eloquent enum casts; no database ENUMs.

## Implemented user profile

Migration: `2026_09_18_000001_add_profile_fields_to_users_table.php`.

| Field | Storage and constraints | Application behavior |
| --- | --- | --- |
| `username` | Nullable string, 30 characters, unique index | Null at registration; required when saving settings; 3–30 lowercase letters, digits, or underscores, starting with a letter. |
| `display_name` | Nullable string, 50 characters | Optional; falls back to the registration `name`. |
| `bio` | Nullable string, 160 characters | Optional profile text. |
| `is_private` | Boolean, default `true` | Stored account preference; follower enforcement arrives in Phase 6. |
| `theme_key` | String, 30 characters, default `terminal-green` | Validated against the sole supported theme, `terminal-green`. |

Profile saves update only the authenticated user's row. Display name and bio are trimmed; empty/whitespace-only values become null, while `"0"` is preserved. The migration's `down()` removes the username index and all five fields. Avatar is optional and not implemented.

Framework tables retain Laravel's standard schema: unique user email, password-reset token keyed by email, and session indexes on `user_id` and `last_activity`. The standard session `user_id` is indexed without a foreign-key constraint; future domain ownership relationships require explicit foreign keys and deletion behavior.

## Implemented Phase 2 schema

Four migrations, `2026_09_27_000001` through `000004`, create the following tables in dependency order. Rollback drops them in reverse order without altering users or profiles. These migrations target the repository's MySQL database.

| Table | Storage and constraints |
| --- | --- |
| `categories` | ID; nullable user FK with restricted deletion; name (60); normalized name (180, binary collation); globally unique slug (100); nullable description (500); `is_system` boolean default false; timestamps. Unique `(user_id, normalized_name)` prevents duplicate custom names. A check constraint requires system categories to have no owner and custom categories to have an owner. |
| `category_aliases` | ID; category FK with cascade deletion; alias (60); normalized alias (180, binary collation); unique `(category_id, normalized_alias)`; no timestamps. |
| `habits` | ID; required user/category FKs with restricted deletion; name (100); nullable description TEXT (application limit 2,000); type/visibility strings (20); visibility default `private`; nullable unsigned integer goal value; nullable goal unit (30); DATE start/end (end nullable); nullable archive timestamp; timestamps and soft deletes. Composite index `(user_id, deleted_at, archived_at, id)` supports owner lifecycle lists; category FK index supports category filtering. |
| `habit_checkpoints` | ID; habit FK with cascade deletion; label (80); unsigned small-integer sort order; timestamps; index `(habit_id, sort_order)`. Ordered positions and distinct normalized labels are validated in the application. |

`CategorySeeder` creates ten system categories and curated aliases using stable system slugs. Repeated seeding preserves IDs and custom rows. The system slug is the seed identity because nullable-owner unique indexes do not enforce uniqueness among system normalized names. Custom slugs use a server-generated UUID. Normalization lowercases Unicode and replaces punctuation/spacing runs with spaces; display names remain separate. Recommendations read only system and the requesting user's categories, including their aliases.

Habit goals are optional for count, duration, and avoidance. Count/duration goals must be positive whole numbers; avoidance permits zero. Maximum accepted value is 999,999,999. Duration uses canonical `minutes`; boolean/checkpoint habits clear numeric goal/unit fields. Dates use `immutable_date` casts; archives use `immutable_datetime`. Phase 2 supports 1–20 configurable checkpoint labels, with no daily completion rows.

The authenticated user supplies ownership through server-side relationships. A category FK alone cannot enforce cross-table ownership: scoped validation plus policies require a system category or one owned by the habit's owner. Habit creation/edit and checkpoint synchronization use a transaction; existing habit writes lock the current row. Lifecycle mutations also lock within a transaction. Checkpoint reorder keeps row IDs; invalid or stale checkpoint references reject the entire save.

Archive preserves dates and configuration. Soft deletion preserves checkpoints and archive state; restore clears only the deletion marker. Categories referenced by trashed habits still cannot be deleted. Permanent deletion and automatic expiry are deferred by user choice, so trash remains recoverable after 30 days. No raw activity, cache, or analytics tables are introduced.

## Planned later domain tables

- **habit_entries:** `id`, `habit_id`, `user_id`, `entry_date`, `status`, nullable `value`, nullable private `note`, nullable `not_applicable_reason`, timestamps; unique `(habit_id, entry_date)`.
- **habit_checkpoint_entries:** `id`, `habit_entry_id`, `habit_checkpoint_id`, `is_completed`, nullable `completed_at`, timestamps.
- **journals:** `id`, `user_id`, `journal_date`, `content`, nullable `mood`, timestamps; unique `(user_id, journal_date)`.
- **follows:** `id`, `follower_id`, `followed_id`, `status`, `requested_at`, nullable `accepted_at`, timestamps; unique pair and no self-following.

All ownership relationships use foreign keys with intentional deletion behavior. Index date, owner, lifecycle, visibility, and follow-status paths only as queries require. Do not add heatmap/cache tables initially.
