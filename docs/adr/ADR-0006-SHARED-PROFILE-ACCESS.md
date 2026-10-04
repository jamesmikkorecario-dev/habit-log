# ADR-0006: Directional followers and shared profile access

- **Status:** Accepted and implemented in Phase 6; see [the closeout](../PHASE_6_CLOSEOUT.md)
- **Date:** 2026-10-04
- **Related:** [Phase 6 plan](../PHASE_6_PLAN.md), [privacy defaults](ADR-0003-PRIVACY-DEFAULTS.md)

## Decision

The user authorized implementation after confirming signed-in browsing only, private-account approval gating all shared activity, and preservation of pending requests when an account becomes public. Follow relationships are directional and grant read access only. Public targets accept new follows immediately; private targets require approval. Existing pending requests never auto-promote. Accepted followers survive privacy changes. Cancel/reject/unfollow/remove delete the relationship; a later request has a new identity. Expected ID and state prevent stale actions on replacement requests.

Owners retain access to their own nontrashed habits. Other viewers must first pass the account gate, then may read public habits and, when accepted followers, followers-only habits. Private habits, notes, N/A reasons, journals, and mood never become shared. Management policies remain owner-only; a separate shared-read ability prevents read access from cascading into edits.

Minimal private profile headers expose username, explicit display name (otherwise username), privacy, and the viewer's relationship state. Permitted profiles additionally expose bio, supported theme, accepted relationship counts, permitted habits, and filtered analytics. Registration names, emails, custom category metadata beyond the associated label, checkpoint labels, and private fields are excluded from shared projections. Relationship lists and pending counts remain owner-only. Both users need a username to start following.

Shared analytics authorize as the viewer and filter habit membership before calculating any counts, scores, streaks, or denominators. Use the subject's timezone and existing normalized scoring. Owner analytics stay unchanged. Shared results contain no links to private editors. Recheck authorization on every Livewire request, including nested components, and pin session identity. A consistent read already in flight may complete from its authorized snapshot; subsequent requests see revocation. No live recall or cross-request cache is promised.

## Integrity and consequences

An additive follows table has restricted user FKs, a unique directed pair, no-self-follow and status/timestamp checks, and indexes for incoming/outgoing state lists. Follow transactions lock both users in ascending ID order before the relationship. Profile saves lock their own user row. No raw activity is changed or backfilled. Relationship removal is not blocking; public activity on a public account stays readable to signed-in users.

Phase 6 activates existing explicit public/followers preferences across stored history while preserving private defaults. Explain this in settings and rollout documentation. This extends Phase 4's owner-only read boundary without changing its formulas or owner write access. No anonymous publication, RBAC package, social feed, notifications, or journal sharing is added.

## Verification and rollback

Test policy/query parity across the full access matrix, private payload canaries, filtered aggregates, stale request identities, ordered-lock races, privacy changes, expired/switched sessions, populated upgrade/rollback, and installed-browser flows. Record measured query behavior and actual verification limits in the closeout. Application rollback restores owner-only routes and leaves follow rows intact; schema rollback loses approvals and is not routine recovery.
