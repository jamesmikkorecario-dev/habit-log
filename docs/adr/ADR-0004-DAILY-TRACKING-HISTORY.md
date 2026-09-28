# ADR-0004: Daily dates and historical tracking configuration

- **Status:** Accepted for Phase 3 implementation
- **Date:** 2026-09-27

## Context and decision

The user selected an editable timezone defaulting to UTC and preservation of historical setup while allowing future changes, then authorized Phase 3 implementation. Follow the daily contract in [the Phase 3 plan](../PHASE_3_PLAN.md).

Store calendar entry dates without UTC conversion, and timestamps in UTC. Timezone changes never relocate existing dates. Save one owner-only entry per habit/date; retain raw measurements, private notes, and explicit N/A records. Numeric outcomes are the owner's assessment; checkpoint outcomes follow checked items. No normalized scoring formula is chosen here.

Store effective-dated type/goal/unit/date configurations and immutable checkpoint snapshots. Before activity, edits replace the baseline; after any entry, edits take effect next local day. Old-date backfill uses that date's configuration. Source checkpoint removal may clear provenance but cannot delete historical snapshots or completions.

Archive/trash suspends dates starting the next local day; resume ends suspension at the current local date, exclusive. Intersect configured eligibility with all stored suspensions. Current lifecycle markers still control lists/access; trash prevents all normal entry access. Same-day transitions create empty intervals. Preserve raw entries through every lifecycle change.

## Rationale and alternatives

Locking all configuration after first entry conflicts with the user's chosen future-edit behavior. Per-entry snapshots alone cannot determine the old setup for an as-yet-unlogged historical date. Effective configurations and suspension intervals supply that information without event sourcing or cached analytics.

## Integrity, privacy, and testing

Use owner policies, scoped relationships, shared user locks followed by exclusive habit locks, unique daily keys, optimistic entry/configuration revisions, and transactions for multi-row saves. Notes and N/A reasons are excluded from summaries and serialization. Test timezone/daylight-saving boundaries, backfill, configuration/lifecycle races, all five types, N/A transitions, and cross-owner denial.

## Migration and rollback

Add timezone and tracking tables, then restart-safe baseline backfill in a separate migration. Prior completed archive periods and prior configuration edits cannot be reconstructed. Restrict physical history deletion. Prove up/down/reapply only on the isolated test database; deployment rollback requires preserved data, not destructive schema rollback. Update database/UI/testing/command/structure documentation and the phase closeout with observed evidence.

## Unchanged decisions

No privacy default is relaxed. No scoring formula changes; avoidance maximum, optional-goal scoring, and unlogged-day analytics remain the documented Phase 4 conflict. Permanent deletion/automatic expiry remain deferred.
