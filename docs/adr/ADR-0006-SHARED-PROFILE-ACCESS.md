# ADR-0006: Directional followers and shared profile access

- **Status:** Accepted and implemented in Phase 6; see [the closeout](../PHASE_6_CLOSEOUT.md)
- **Date:** 2026-10-04
- **Related:** [UI specification](../UI_UX_SPEC.md#phase-6-people-and-shared-activity), [privacy defaults](ADR-0003-PRIVACY-DEFAULTS.md)

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

## Access and disclosure contract

All routes below require authentication. A private profile can show a minimal identity header so a signed-in visitor can request access; it does not show activity or counts before approval. A pending request grants no follower access. Following in the opposite direction grants no access, and following is never automatically reciprocal.

| Viewer relative to subject | Profile presentation | Public habit | Followers habit | Private habit |
| --- | --- | --- | --- | --- |
| Owner | Own full profile and owner summary | Yes | Yes | Yes |
| Accepted follower, either account privacy | Shared profile and permitted summary | Yes | Yes | No |
| Nonfollower or pending requester, public account | Shared profile and permitted summary | Yes | No | No |
| Nonfollower or pending requester, private account | Minimal header and request state only | No | No | No |
| Guest | Sign-in required | No | No | No |

Apply the account gate before habit visibility. Trash is excluded for everyone on shared profile/habit surfaces, including an owner viewing that surface; owners retain their existing private Trash management. Archived and ended habits retain permitted history and clear lifecycle labels. Visibility changes govern access to the entire stored history immediately on the next authorized request; do not introduce historical visibility versions. New habits remain private for all account types.

Implemented field allowlists:

- **Minimal identity:** username, explicit display name (fallback to username for shared labels), account privacy, and the requesting viewer's own relationship state. Do not reveal email, registration name fallback, bio, habit totals, relationship totals, or activity before the private-account gate passes. Keep the owner's existing greeting fallback unchanged; update profile settings copy to distinguish the shared label.
- **Permitted profile:** minimal identity plus bio, supported selected theme, accepted follower/following counts, visible habit count, permitted habit list, and filtered analytics. Pending counts and requests are owner-only. Relationship counts exclude pending requests. Relationship identities/lists are available only in the current user's own social management page; no browsing another user's follower list in this phase.
- **Permitted habit:** name, description, type, visibility, lifecycle, dates, goal/unit, and category display name; yearly activity, normalized results, streaks, and consistency. Read-only day details may show recorded assessment, numeric measurement, and historical goal/unit used to explain performance. No entry note, N/A reason, journal content, mood, pending configuration editor state, or editor links. Project only the associated category label; do not expose a custom category's description, aliases, other habits, or management route. Checkpoint labels and individual completion records stay off the shared surface; normalized checkpoint performance is sufficient here.

The profile's aggregate uses only habits this viewer may read. Private habit IDs, existence, names, counts, eligibility, N/A/pending totals, scores, streak effects, and denominators must not leak through derived data. Paginate and count after filtering. Zero visible habits means a neutral/no-data state; never show the owner's private total or imply how many habits are hidden.

Denied direct shared habit access returns the same not-found response as an absent/mismatched habit. The minimal private profile header is an intentional discoverable surface, not an authorization failure. No account lookup by email and no numeric-ID fallback for username-less profiles.

## Follower state machine

Use one directed row from `follower_id` to `followed_id`; accepted A → B means A can read B's follower-visible activity. Self-follow is invalid at the application and database boundaries.

| Current state | Actor and operation | Result |
| --- | --- | --- |
| None; target public | Follower selects Follow | Create accepted row, set request/accept timestamps |
| None; target private | Follower selects Request to follow | Create pending row, acceptance null |
| Pending | Followed account approves that request | Same row becomes accepted, set acceptance time |
| Pending | Followed account rejects, or follower cancels | Delete that pending row; return to none |
| Accepted | Follower unfollows, or followed account removes follower | Delete that accepted row; return to none |
| Pending or accepted | Follower repeats Follow | Keep current row/state; no duplicate and no implicit pending approval |
| Any | Unrelated user attempts mutation | Deny, with no change |

Rejected/removed relationships have no retained history row, rejection reason, or notification. A new request is possible later, with a new row ID. This phase does not promise blocking. Removing a follower revokes follower access; public activity on a public account remains readable under the matrix.

Approval/rejection/cancellation/removal must target the expected row ID and state, not just a user pair. A stale request after cancellation and recreation must never approve, reject, or remove the replacement row. Absent or changed rows return safe stale-state feedback and refresh controls; duplicate Follow remains idempotent. No toggle endpoint whose retry could reverse a previous result. Owner removal/unfollow use named confirmation dialogs; cancelling a request or rejecting one has an explicit labeled action and cannot affect an accepted replacement.

Public → private preserves existing accepted followers, keeps pending requests pending, and immediately gates nonfollowers on subsequent requests. Private → public preserves accepted followers and pending requests; only genuinely new follows are immediately accepted. A pending requester may cancel and make a new public-account follow explicitly. Privacy toggles do not rewrite any habit visibility, relationship timestamps, or activity.
