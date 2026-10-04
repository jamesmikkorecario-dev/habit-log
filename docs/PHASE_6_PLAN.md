# Phase 6 implementation plan — Followers and permitted profiles

Status: implemented and verified; see [the closeout](PHASE_6_CLOSEOUT.md). Implementation was authorized by the user on 2026-10-04. Prepared 2026-10-04 against Phase 5 commit `a11e6a1`, starting from a clean worktree. This planning task replaces the retired Phase 5 plan at the user's request. The [Phase 5 closeout](PHASE_5_CLOSEOUT.md), [database design](DATABASE_DESIGN.md), and [UI specification](UI_UX_SPEC.md) retain its implemented contract. The subsequent implementation request authorizes Phase 6 code, migrations, and verification. The user subsequently requested a Phase 6 commit and findings report. Push and deployment have not been requested.

## Sources, decisions, and scope

Follow [AGENTS.md](../AGENTS.md), [design guardrails](DESIGN_GUARDRAILS.md), [product requirements](PRODUCT_REQUIREMENTS.md), [architecture](ARCHITECTURE.md), database design, UI specification, [roadmap](ROADMAP.md), and relevant accepted ADRs in that order. Apply [privacy defaults](adr/ADR-0003-PRIVACY-DEFAULTS.md), [date/history semantics](adr/ADR-0004-DAILY-TRACKING-HISTORY.md), and [scoring semantics](adr/ADR-0005-ANALYTICS-SCORING.md), plus [security](SECURITY_AND_PRIVACY.md), [testing](TESTING_STRATEGY.md), [AI workflow](AI_WORKFLOW.md), and [design verification](DESIGN_VERIFICATION.md). Earlier phases' statements that sharing is unavailable describe those phases, not a prohibition on the roadmap's Phase 6.

The user confirmed these Phase 6 choices on 2026-10-04:

1. Browsing public profiles and public habits requires sign-in. Anonymous browsing is out of scope.
2. Private-account approval gates all shared activity, including habits marked public. Only the owner and accepted followers pass that account gate.
3. Switching a private account to public keeps existing requests pending until explicitly approved or cancelled. There is no automatic acceptance of those requests.

The remaining details below were adopted as implementation contracts, not additional user-confirmed answers. No unresolved source conflict was found. Recheck the sources when implementation begins; report any new conflict before coding.

Included: directional following; public-account immediate acceptance for new follows; private-account requests; approval/rejection, cancellation, unfollowing, and follower removal; owner relationship management; username-based profile access; permitted habit lists and read-only habit activity; viewer-filtered profile heatmaps, streaks, and consistency; server-side visibility enforcement and regression verification.

Excluded: comments, messages, social feed, notifications, email alerts, recommendations, broad user directory/search, blocking/moderation systems, anonymous access, public API, avatars/new themes, new scoring formulas, caches, queues, role/permission packages, journal sharing, habit permanent deletion, and automatic trash expiry. Follow relationships confer read access only. Existing registration/authentication remains unchanged; this is ownership and relationship authorization, not RBAC.

## Repository findings that shape the implementation

- No `follows` table, model, policy, profile-view route, or follow controls exist. The database document has only a planned table outline. Users already have nullable unique usernames, display names, bio, privacy, theme, and timezone.
- `HabitVisibility` already stores `private`, `followers`, and `public`, but all current management routes are owner-only. `HabitPolicy::update`, `archive`, `delete`, and `record` depend on its current owner-only `view`. Broadening `view` alone would broaden write access. Keep these ownership checks explicit and unchanged; introduce a distinct shared-read ability.
- `ActivityAnalytics::report(User $user, ...)` treats its argument as both subject and authorized owner, calls `Gate::forUser($user)`, and includes all that user's nontrashed habits. Calling it with another profile's owner would expose private aggregate data. Shared reporting needs a separate explicit viewer/subject boundary and filtering before aggregation.
- `ActivityHeatmap` derives its subject from the logged-in user. Its Blade view says only the owner can see the activity, and analytics cells generate links to owner tracking/edit routes. Shared views must have explicit identities, suitable copy, and no owner editor links in their response payloads.
- `User` hides passwords/tokens, but general serialization still includes email and registration fields. Never serialize whole users into shared profile or relationship state. The current owner-only `displayName()` falls back to registration name; define shared labels deliberately.
- Profile privacy settings currently perform a validated user update without a social transition boundary. Follow creation must synchronize with privacy changes, and profile help text must stop describing sharing as a future feature.
- Phase 5 verification recorded 294 tests / 2,126 assertions and 37 Chrome checks. Those are baseline evidence, not Phase 6 acceptance results. Existing migration tests use explicit phase paths; retain that isolation.

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

## Schema, transactions, and migration

Add one additive MySQL migration after the journal migration. Do not change prior migrations or backfill relationships from visibility preferences.

| Field / constraint | Storage and purpose |
| --- | --- |
| `id` | Primary key; identifies a particular request across delete/recreate races |
| `follower_id`, `followed_id` | Required user FKs, restricted parent deletion to match existing owned records |
| `status` | String with PHP backed enum: `pending`, `accepted`; validate allowed values in a DB check |
| `requested_at` | Required UTC timestamp |
| `accepted_at` | Nullable UTC timestamp; null exactly when pending, required when accepted, not before request time |
| timestamps | Standard created/updated UTC timestamps |
| unique `(follower_id, followed_id)` | One directed relationship and indexed pair checks |
| check `follower_id <> followed_id` | Prevent self-following |
| `(followed_id, status, id)` | Incoming requests/accepted followers, paginated by ID |
| `(follower_id, status, id)` | Outgoing requests/accepted following, paginated by ID |

Use a normal `Follow` model, `FollowStatus` enum, relationships on User, factory, Policies/Gates, and small transactional Actions. Derive the actor from the authenticated session and reauthorize under the transaction; do not mass-assign actor, status, timestamps, or identity from browser input. A separate revision is unnecessary if pending → accepted is the only in-place transition and all other transitions delete the row; compare expected ID and state.

Follow mutations lock the two user rows in ascending numeric ID order, then the directed follow row, and read current target privacy under those locks. This also serializes creation when a pair has no row. The unique key remains the final integrity constraint. Keep transactions short and acquire no habit or journal locks. Privacy saves take the existing user's exclusive row lock in a focused profile-save transaction; they never lock a peer or bulk-convert pending requests. This coordinates Follow with a privacy change without changing the established user-before-habit/journal lock order. Verify opposite-direction follows and privacy races using independent MySQL connections.

No seeded followers or changes to the login test account are needed. Migration up/down tests must guard both configured and connected database names as `habit_log_test`, populate pre-Phase-6 profiles/habits/activity/journals, add follows, roll back only this migration, and prove all earlier rows unchanged. Down drops only follows and loses relationship/approval data; document that consequence and prefer application rollback leaving the additive table intact. Never reset development data. Do not add a habit visibility index until representative query plans justify one.

## Authorization and analytics architecture

Add a profile policy for full shared profile access, a follow policy for each transition, and a distinct `viewShared` habit ability. Keep existing management `view` and every mutation explicitly owner-only; audit all policy delegation so follower read access cannot flow into writes. Use one narrowly scoped visibility query/predicate shared by policies, list queries, and analytics; test parity against the complete matrix. Do not add repositories, a general permission engine, or role tables.

Introduce an explicit `reportForViewer(User $viewer, User $subject, int $year, ?int $habitId)` boundary, or an equivalently clear dedicated method. Preserve the owner report contract for the dashboard and existing callers. Reuse scoring, segmentation, and streak calculations; do not copy the analytics engine. Within one consistent nonlocking read transaction, reload viewer/subject identity, subject privacy/timezone, relationship status, allowed habit membership, and historical records. Authorize as the actual viewer, never by impersonating the subject through `Gate::forUser($subject)`.

Filter the initial habit query before loading versions, entries, or forming event segments. All counts, list totals, day details, windows, and lifetime streaks derive from that same permitted set. The subject's timezone defines today and year limits; the viewer's timezone never shifts another user's calendar. Formula weights, N/A rules, unlogged/pending handling, historical setup, archive gaps, and raw records remain unchanged. Use all permitted habits for analytics independently of the visible list page.

Shared cells contain no owner-only tracking URLs, even if Blade would hide the link. Give the shared interface read-only date details and shared habit links. Reuse the calendar rendering and accessibility behavior; refactor presentation only as necessary to pass server-derived labels/context. Do not accept a browser-controlled `isOwner` or `canViewPrivate` flag. Keep private notes/reasons and journal fields out of queries/projections, HTML, JSON, Livewire snapshots, logs, and browser storage.

Lock component viewer ID, subject ID, and optional habit ID; compare the viewer to the current authenticated session on hydration. Every render and action, including nested heatmaps, pagination, year changes, and day details, rechecks current authorization. Relationship or privacy changes revoke access on the next request; reject or replace stale results with a safe unavailable state. A consistent in-flight response may finish from the snapshot authorized when its read began; data already delivered cannot be recalled. Never use a cross-request authorization/result cache. Do not persist private report arrays as mutable public Livewire state.

Before implementing the shared boundary, record the confirmed matrix, disclosure allowlists, relationship transitions, filtered aggregates, and revocation semantics in a Phase 6 ADR. Existing private defaults and owner-only journal/notes contracts remain intact. The ADR should explicitly describe how Phase 6 extends Phase 4's owner-only reads without broadening management permissions.

## Routes and interface

Authenticated routes:

| Route | Purpose |
| --- | --- |
| `/people` | Exact username lookup plus the current user's Following, Followers, Incoming requests, and Sent requests tabs |
| `/people/{user:username}` | Minimal private header or permitted profile, Follow/request state, filtered habit list and overall heatmap |
| `/people/{user:username}/habits/{habit}` | Read-only permitted habit detail and yearly activity; bind habit to the resolved subject |

Exact lookup accepts a normalized username with the existing 3–30 character rules, and opens that profile. No all-user directory, prefix search, or email discovery. A user without a username can use their private dashboard and social management, but has no shared profile URL. Both actors need a username before creating a new follow/request so the recipient can identify the requester. Prompt them to set a username; never expose their numeric ID as a substitute. Relationships use user IDs so renaming does not destroy them. Recheck that a mounted shared subject still has a usable username; direct old URLs become not found and newly rendered links use the current username, with no alias system.

Use one People navigation link and a My profile link within that page when a username exists. The owner can inspect their own profile, with private habits clearly marked and a notice that other viewers see a filtered subset. Own follower/following lists and request tabs use stable 20-row pagination and explicit empty states; incoming requests name the requester and provide Approve/Reject. Accepted counts exclude pending rows. Shared habit lists are also paginated and include archived/ended permitted history with labels.

Controls distinguish Follow, Request to follow, Request pending / Cancel request, Following / Unfollow, and owner Remove follower. Approval/rejection updates the correct list and counts, announces feedback, and handles stale tabs without implying success. Confirmation dialogs identify the person, support Cancel/Escape, and restore focus or move it to the remaining list heading if a row disappears. Keep normal CSRF/session middleware, disabled pending buttons, keyboard focus, reduced motion, and escaped output.

Update profile privacy, habit visibility, and analytics copy at rollout. Explain sign-in requirements, the private-account gate, pending request behavior, sharing of historical activity, and that notes/journals/mood are never shared. Existing explicitly chosen public/followers visibility values become meaningful when Phase 6 is enabled; audit that transition and document it in rollout notes, without rewriting defaults or publishing private habits. A switch to public must clearly describe the resulting exposure before saving.

Check 320px/375px layouts, tablet widths, and 1024px/desktop widths after adding People. Browser verification found overflow at the old 768px desktop switch; implementation uses 1024px instead. Reuse accessible heatmap keyboard controls, text cues, and paginated day list. No new browser library or application dependency is expected; use available installed tooling and record verification limits.

## Likely affected files

| Area | Files / changes |
| --- | --- |
| Schema and models | New follows migration, `app/Models/Follow.php`, `app/Enums/FollowStatus.php`, `database/factories/FollowFactory.php`, User relationships |
| Authorization | New profile/follow policies, distinct shared-read ability in `app/Policies/HabitPolicy.php`, narrow reusable habit visibility query; audit `HabitEntryPolicy` and all callers |
| Mutations | `app/Actions/Follows/` for request/approval/removal transitions; focused profile-save Action and existing profile settings component |
| Analytics | `app/Services/Analytics/ActivityAnalytics.php`, `app/Livewire/ActivityHeatmap.php` or a thin shared component, its view and reusable calendar component as needed |
| Pages/navigation | `routes/web.php`, new `resources/views/pages/people/` pages, app layout, profile settings and habit visibility help text; preserve owner journal/tracking paths |
| Tests | New `tests/Feature/Social/` for state, matrix, privacy, schema, migration and concurrency; extend Analytics, Habits, Tracking, Journals, Settings, and real Livewire session regressions |
| Documentation | Phase 6 ADR, product/database/architecture/UI/security/testing/commands/structure, README, roadmap, and `PHASE_6_CLOSEOUT.md` after implementation verification |

These are placements, not a requirement to create one class per UI button. Extract only transactional, reused, complex, or independently testable boundaries.

## Implementation sequence and acceptance gates

1. Recheck the worktree, sources, and this plan when implementation is requested. Record the Phase 6 privacy ADR and final field projection. Verify no privacy defaults or write abilities are being relaxed.
2. Add follow schema/model/enum/factory and guarded migration/constraint tests. Demonstrate populated upgrade and targeted rollback before changing access.
3. Implement transactional relationship transitions and profile privacy coordination; prove direction, actor ownership, duplicate/stale behavior, and concurrent writes.
4. Implement shared policies and the filtered query. Pass the account × relationship × visibility matrix for direct reads and every existing write endpoint before exposing shared pages.
5. Extend analytics with explicit viewer/subject semantics and filtered aggregates; preserve all owner formula/snapshot tests and remove private links/projections from shared reports.
6. Build profile lookup, relationship management, read-only profile/habit pages, and responsive navigation. Handle null/changed usernames, empty states, revocation, and shared copy.
7. Run focused tests, full serial MySQL suite/Pint, production build, and installed-browser acceptance. Measure representative filtered queries without adding a cache.
8. Review every applicable design-verification item, update durable docs and rollout/rollback guidance, write the closeout with actual results, and only then mark Phase 6 complete. Commit or push only when requested.

## Test matrix and verification

- **Relationships:** public immediate follow, private pending/approve/reject, cancellation, unfollow/removal, reverse-direction independence, self-follow, duplicate submission, unrelated actors, timestamps/counts, pagination and no GET mutations. Private/public switches retain accepted followers and pending requests exactly as specified.
- **Visibility:** table-driven owner/accepted/pending/nonfollower/guest × public/private account × private/followers/public habit × active/archived/ended/trashed state. Compare policy, SQL list, direct shared route, nested heatmap, and analytics behavior. Owner editors/actions must still reject followers for reads of private notes and all mutations.
- **Payload privacy:** distinct canaries in journal text, mood, entry note, N/A reason, private habit name, email, registration-only name, category description/aliases, and another user's request state. Inspect HTML, JSON/snapshots, serialized projections, errors, and denied requests. No private habit contribution to any visible aggregate or total.
- **Analytics:** mixed-visibility fixtures whose filtered averages/streaks differ from owner results; hidden success, failure, N/A and pending habits; all five tracking types; zero visible habits; more than one habit-list page; timezone/DST/year boundaries; archived/trash/restore; tiny positive/full-success precision. Owner analytics and raw activity/journals remain unchanged by follow and privacy mutations.
- **Reactive authorization:** forged subject/viewer/habit IDs, username/habit mismatch, changed visibility/privacy/follow state between mount and action, direct child-component calls, logout, session-account swap, stale tabs and browser Back navigation. New requests must never return previously authorized hidden report data.
- **Concurrency:** independent connections for duplicate follow creation, opposite-direction follows, Follow versus privacy save, approve versus cancel, approve/reject versus delete/recreate, unfollow versus removal, and a visibility/relationship change during report reading. Verify legal committed outcomes, no silent replacement-row mutation, consistent snapshots, and no next-request access after revocation. Short worker timeouts and test-database guards are mandatory.
- **Schema/migration:** unique directed pair, no self-follow, both FKs, status/timestamp checks, targeted rollback/reapply preserving populated earlier tables, and prior phase migration tests with the new migration present.
- **Browser acceptance:** two real signed-in accounts exercise follow/request/approval/rejection/cancellation/removal and changed access; public/private toggle with pending request; permitted profile and habit heatmaps; absence of owner links; stale session/tabs; labels/focus/dialogs, mobile menu/overflow, reduced motion, and normal CSRF rejection. Verify existing journal draft guards and owner tracking navigation after shell/shared-view changes.
- **Queries:** record EXPLAIN/query counts and scoped timings for both relationship-list directions and mixed-visibility profiles across multiple list/chunk pages. Watch for per-habit follower lookups and per-cell queries. Keep current bounded history processing and nonlocking analytics; optimize only from measurements.

Verification commands used during implementation:

```bash
php artisan test tests/Feature/Social --compact
php artisan test tests/Feature/Analytics tests/Feature/Journals tests/Feature/Tracking --compact
composer ci:check
npm run build
php artisan route:list --path=people
git diff --check
```

`composer ci:check` includes Pint and the full suite, including owner/settings/auth regressions. Run database-resetting tests serially on `habit_log_test`; never concurrently with browser fixtures. Apply a development migration only as part of authorized implementation, inspect its status, and record the exact command. The initial documentation-only planning change was verified through its diff, references, and scope; subsequent implementation required the complete verification described here.

For closeout, record commands, observed test/assertion counts, browser/version/viewports, query measurements, migration effects, files, and any unverified limits. Map evidence to [design verification](DESIGN_VERIFICATION.md): product scope, server authorization, private defaults/fields, raw-data preservation, constraints/transactions, confirmed removal, accessibility, regression coverage, docs/ADR, and accurate roadmap status. Do not substitute baseline Phase 5 results for this evidence.

## Risks and rollout limits

The primary risks are accidentally broadening write policies, including private habits in aggregate denominators, confusing viewer and subject, leaking full Eloquent objects or cached Livewire state, stale approval of a replacement request, and inconsistent privacy/relationship snapshots. The explicit matrix, projections, ordered locks, identity checks, and tests above address these risks.

Phase 6 turns existing sharing preferences into actual signed-in read access; document this behavior prominently. Private defaults remain unchanged, and journals, mood, notes, and reasons remain owner-only. No anonymous publication is introduced. Relationship removal is reversible through a new request/follow but is not a block. Access already displayed cannot be remotely erased, and no live revocation push is promised. Browser testing proves only the environments actually exercised; broader hardening remains Phase 7.

Application rollback must restore the previous owner-only read boundary, remove shared routes, and leave activity/journals intact. Preserve follow rows rather than dropping approvals as routine rollback. The initial planning change created no runtime behavior. Subsequent implementation adds the feature and follows migration; no dependency or seeded relationship was added.
