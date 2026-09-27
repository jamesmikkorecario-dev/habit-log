# Database design

Phase 0 implements `users`, `password_reset_tokens`, and `sessions`. Phase 1 extends `users` with the profile fields below. The remaining domain tables are planned, not implemented. Prefer Laravel/PHP enums or validated strings initially rather than database ENUMs, with the final choice recorded when implemented.

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

## Planned domain tables

- **categories:** `id`, nullable `user_id`, `name`, `slug`, nullable `description`, `is_system`.
- **category_aliases:** `id`, `category_id`, `alias`, `normalized_alias`.
- **habits:** `id`, `user_id`, `category_id`, `name`, nullable `description`, `type`, `visibility`, nullable `goal_value`, nullable `goal_unit`, `start_date`, nullable `end_date`, nullable `archived_at`, timestamps, soft deletes.
- **habit_checkpoints:** `id`, `habit_id`, `label`, `sort_order`, timestamps.
- **habit_entries:** `id`, `habit_id`, `user_id`, `entry_date`, `status`, nullable `value`, nullable private `note`, nullable `not_applicable_reason`, timestamps; unique `(habit_id, entry_date)`.
- **habit_checkpoint_entries:** `id`, `habit_entry_id`, `habit_checkpoint_id`, `is_completed`, nullable `completed_at`, timestamps.
- **journals:** `id`, `user_id`, `journal_date`, `content`, nullable `mood`, timestamps; unique `(user_id, journal_date)`.
- **follows:** `id`, `follower_id`, `followed_id`, `status`, `requested_at`, nullable `accepted_at`, timestamps; unique pair and no self-following.

All ownership relationships use foreign keys with intentional deletion behavior. Index date, owner, lifecycle, visibility, and follow-status paths only as queries require. Do not add heatmap/cache tables initially.
