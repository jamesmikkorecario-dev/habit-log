# Planned database design

Phase 0 implements only framework/auth tables. The following logical schema is planned for later phases. Prefer Laravel/PHP enums or validated strings rather than database ENUMs, recording the final choice when implemented.

- **users:** future `username`, `display_name`, `bio`, `is_private`, `theme_key`, optional avatar reference.
- **categories:** `id`, nullable `user_id`, `name`, `slug`, nullable `description`, `is_system`.
- **category_aliases:** `id`, `category_id`, `alias`, `normalized_alias`.
- **habits:** `id`, `user_id`, `category_id`, `name`, nullable `description`, `type`, `visibility`, nullable `goal_value`, nullable `goal_unit`, `start_date`, nullable `end_date`, nullable `archived_at`, timestamps, soft deletes.
- **habit_checkpoints:** `id`, `habit_id`, `label`, `sort_order`, timestamps.
- **habit_entries:** `id`, `habit_id`, `user_id`, `entry_date`, `status`, nullable `value`, nullable private `note`, nullable `not_applicable_reason`, timestamps; unique `(habit_id, entry_date)`.
- **habit_checkpoint_entries:** `id`, `habit_entry_id`, `habit_checkpoint_id`, `is_completed`, nullable `completed_at`, timestamps.
- **journals:** `id`, `user_id`, `journal_date`, `content`, nullable `mood`, timestamps; unique `(user_id, journal_date)`.
- **follows:** `id`, `follower_id`, `followed_id`, `status`, `requested_at`, nullable `accepted_at`, timestamps; unique pair and no self-following.

All ownership relationships use foreign keys with intentional deletion behavior. Index date, owner, lifecycle, visibility, and follow-status paths only as queries require. Do not add heatmap/cache tables initially.
