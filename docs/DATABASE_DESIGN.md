# Database design

Phase 0 implements `users`, `password_reset_tokens`, and `sessions`. Phase 1 extends `users` with the profile fields below. Phase 2 adds categories, aliases, habits, and checkpoint configuration. Phase 3 adds timezone, tracking configuration history, daily entries, and suspension intervals. Phase 5 adds private daily journals; Phase 6 adds directional follows. Habit type and visibility use PHP backed enums with string database columns, boundary validation, and Eloquent enum casts; no database ENUMs.

## Implemented user profile

Migration: `2026_09_18_000001_add_profile_fields_to_users_table.php`.

| Field | Storage and constraints | Application behavior |
| --- | --- | --- |
| `username` | Nullable string, 30 characters, unique index | Null at registration; required when saving settings; 3–30 lowercase letters, digits, or underscores, starting with a letter. |
| `display_name` | Nullable string, 50 characters | Optional; falls back to the registration `name`. |
| `bio` | Nullable string, 160 characters | Optional profile text. |
| `is_private` | Boolean, default `true` | Phase 6 gates all shared activity behind accepted following for private accounts. |
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

Archive preserves dates and configuration. Soft deletion preserves checkpoints and archive state; restore clears only the deletion marker. Categories referenced by trashed habits still cannot be deleted. Permanent deletion and automatic expiry are deferred by user choice, so trash remains recoverable after 30 days. Phase 2 introduced no raw activity, cache, or analytics tables; the Phase 3 additions below now store raw activity.

## Phase 3 tracking schema

Migration `2026_09_27_000005_add_tracking_schema.php` adds `users.timezone` (string 64, non-null, default UTC) and five tables. A separate `000006_backfill_tracking_history.php` installs baselines and known open suspensions. Existing timestamps stay UTC; dates are stored as calendar DATE values without UTC conversion.

| Table | Storage and constraints |
| --- | --- |
| `habit_tracking_versions` | Habit FK with restricted deletion; effective date; type (20); nullable unsigned integer goal and unit (30); start/end dates; unsigned revision default 1; timestamps. Unique `(habit_id, effective_date)` and a valid date-range check. |
| `habit_tracking_checkpoints` | Version FK with cascade deletion; nullable source checkpoint FK with SET NULL; immutable label (80) and unsigned sort order. Unique version/order. No timestamps. Source configuration removal cannot remove snapshots. |
| `habit_entries` | Habit, user, and tracking-version FKs with restricted deletion; entry date; status (20); nullable unsigned integer value, private note TEXT, and private N/A reason (500); unsigned revision default 1; timestamps. Unique habit/date; owner/date/habit index. |
| `habit_checkpoint_entries` | Entry FK with cascade deletion; immutable tracking-checkpoint FK with restricted deletion; completion boolean default false; nullable completion timestamp; timestamps. Unique entry/checkpoint. Historical completion rows refer to snapshots rather than mutable checkpoint configuration. |
| `habit_suspensions` | Habit FK with restricted deletion; archive/trash reason (10); inclusive start and nullable exclusive end; timestamps. Habit/reason/end/start index; end must be null or on/after start. Open-interval uniqueness is enforced within the locked lifecycle transaction. |

Use backed enum casts for status/type, immutable date/datetime casts, and integer/boolean casts. FKs prove existence; scoped actions derive owner/version/checkpoint membership through the parent habit. Independent FKs do not themselves enforce cross-table same-habit ownership.

Before the first entry, configuration edits replace the baseline and increment its revision. Once any entry exists, including N/A, configuration changes produce a version effective the next local day. Past-date backfill uses the historical version. Pending unreferenced changes may be cancelled; the same transaction restores current editable configuration. Entries and effective versions are never rewritten by ordinary configuration editing. Current habit metadata remains mutable; historical names/categories are not snapshotted.

Recording, configuration, cancellation, and lifecycle transactions acquire a shared owner lock, then an exclusive habit lock. The owner lock stabilizes timezone and is a locking read, avoiding a stale repeatable-read snapshot before a habit lock wait. Daily writes compare entry and configuration revisions; a competing create/update becomes a conflict instead of a silent overwrite. Measurements are integers 0–999,999,999; notes/reasons are application-limited to 2,000/500 characters. Notes/reasons are hidden from model serialization and omitted from summary queries. N/A retains existing measurements/completions while excluding them from later performance eligibility.

Archive/trash intervals begin the next local date; unarchive/restore ends them at the current local date. Same-day transitions close an empty interval; overlapping archive/trash periods both exclude dates. Restore closes only the trash interval. Timezone changes never relocate stored dates. Completion timestamps record when a checkbox was saved as checked, not a guessed historical instant.

The query-builder backfill processes each habit transactionally and skips completed baseline work on retry. It preserves Phase 2 rows, snapshots existing checkpoints, and records open intervals from current UTC lifecycle timestamps. Completed pre-rollout lifecycle periods and earlier configurations cannot be reconstructed. The backfill migration's `down()` does not delete history; the schema migration drops tables in reverse dependency order, then timezone. Dropping those tables destroys Phase 3 data, so rollback/reapply verification is restricted to `habit_log_test`.

Apply the upgrade during a maintenance window with writers stopped: schema DDL is not one MySQL transaction. Back up existing data first. If backfill fails, rerun the pending migration; completed per-habit work is retained. If schema creation fails partway before tracking is enabled, inspect the actual schema and repair/reverse only the incomplete additive schema before retrying. Once activity exists, preserve/export that data before any deployment rollback; do not use schema rollback as a routine recovery action.

All ownership relationships use foreign keys with intentional deletion behavior. Index date, owner, lifecycle, visibility, and follow-status paths only as queries require. Do not add heatmap/cache tables initially.

## Phase 4 derived analytics

Phase 4 adds no migrations, score columns, seeders, or cache tables. Reports project existing owner-scoped entries, historical versions/checkpoint snapshots, and suspension intervals. Queries omit private notes and N/A reasons. A consistent nonlocking read transaction prevents mixing related rows from different committed versions. Trash excludes a habit from current analytics membership; restoration recomputes its preserved history while retaining suspension gaps. Formula changes never rewrite activity.

Lifetime streaks require reading historical entries in bounded batches; a date-boundary sweep avoids materializing every unlogged day. Existing owner/lifecycle and entry indexes support these queries; measured query plans and performance belong in the Phase 4 closeout. No index migration was justified by the scoped measurements.

## Phase 5 journal schema

Migration `2026_10_04_000001_create_journals_table.php` adds `journals`: primary ID, required user FK with restricted parent deletion, immutable calendar DATE `journal_date`, required TEXT `content`, nullable unsigned tiny-integer `mood`, unsigned `revision` default 1, and timestamps. Unique `(user_id, journal_date)` supports the owner/date lookup and enforces one journal per day. A check constraint limits mood to null or 1–5. Application validation requires nonblank text up to 10,000 characters; timestamps remain UTC.

Journal save/delete transactions lock the user first, then the owner/date journal. Expected ID plus revision prevents stale edits/deletion and replacement-entry races; fresh timezone comparison prevents writes from obsolete timezone settings. Content/mood are hidden from general serialization and explicitly loaded for the owner editor. Deletion physically removes only the confirmed journal row. No habit data, analytics, cache, history-version, or recovery tables change.

The additive migration does not backfill journals. Its rollback drops only `journals` and therefore destroys journal content; preserve data before deployment rollback. Populated upgrade and rollback/reapply tests run only after checking configured and connected database names are `habit_log_test`. Phase 3 migration tests now select their own migration paths rather than assuming they are the latest migrations.

## Phase 6 follow schema

Migration `2026_10_04_000002_create_follows_table.php` adds `follows`: primary ID; required `follower_id` and `followed_id` user FKs with restricted parent deletion; status string cast to `FollowStatus`; required UTC `requested_at`; nullable UTC `accepted_at`; timestamps. A unique directed pair allows A → B and B → A as separate relationships. Checks prohibit self-follow and require pending/null acceptance or accepted/non-null acceptance at or after the request. Indexes `(followed_id, status, id)` and `(follower_id, status, id)` support both paginated list directions.

Follow creation locks both users in ascending ID order before the pair. Approval/rejection/cancellation/unfollow/removal lock the same users, then recheck the exact row identity and expected state. Pending → accepted is the only in-place transition; all other transitions delete the row. New requests receive new IDs, so stale actions cannot mutate a replacement. Privacy saves take an exclusive owner lock and preserve every existing follow row, including pending requests. No relationship, habit visibility, or activity backfill occurs.

Rollback drops only follows and loses relationship/approval data; prefer reverting shared application routes while retaining the table. The guarded populated upgrade/rollback test verifies prior profiles, habits, configuration, entries, checkpoints, suspensions, and journals remain unchanged. No new analytics cache or habit visibility index is added without measured need.


## Account management Phase 2 schema

Migration `2026_10_26_000001_create_pending_email_changes_table.php` creates `pending_email_changes`: UUID primary key, unique `user_id` FK cascading on user deletion, `new_email` and `source_email` strings (255), `token_hash` CHAR(64), indexed UTC `expires_at`, UTC `last_sent_at`, and timestamps. There is no unique constraint on proposed emails; `users.email` remains the authoritative uniqueness constraint at confirmation. Token hash and both email fields are hidden from general model serialization; the authenticated Settings controller explicitly renders the owner's pending address.

Requests replace the owner's row under an exclusive user lock followed by the pending-row lock. Confirmation uses the same order, verifies the source identity, current password and exact generation/token/expiry, then updates identity and session/recovery state transactionally. Password changes/resets delete pending state. No domain table is rewritten or migrated. The `down()` drops only pending requests: outstanding links stop working, while confirmed identities and saved activity are preserved. Rollback/reapply and constraint tests use configured and connected `habit_log_test` only. Apply the additive migration before serving the new Security page; reverting code is preferable to dropping pending state during ordinary rollback.

## Account management Phase 3 schema

Migration `2026_10_27_000001_add_session_revocation_state.php` adds nullable unique UUID `management_id` and nullable CHAR(64) `origin_hash` to `sessions`. Existing rows receive metadata only, without decoding or altering payloads. `session_revocations` has a CHAR(64) primary session hash, cascading user FK and UTC revocation timestamp. Revocation records persist until account removal so delayed writes cannot outlive a pruning window. No domain records or credentials are backfilled. Current session IDs remain server-side; UUID selectors carry no authentication authority.

User-first locks serialize credential checks, revocation and final authenticated session writes. The existing owner/activity indexes remain; no speculative performance index is added. Deployment requires the default connection's canonical `sessions` table and migration before new code. Rollback removes only the metadata/tombstones but removes their security guarantee: coordinate code rollback and invalidate authenticated sessions before reverting to an older implementation. See [ADR-0010](adr/ADR-0010-SESSION-MANAGEMENT.md).
