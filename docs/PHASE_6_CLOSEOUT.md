# Phase 6 closeout — Followers and permitted profiles

Date: 2026-10-04. Phase 6 implementation and scoped verification are complete. Scope follows the [plan](PHASE_6_PLAN.md), the three confirmed sign-in/privacy/pending-request decisions, and [ADR-0006](adr/ADR-0006-SHARED-PROFILE-ACCESS.md). The user subsequently requested a Phase 6 commit and findings report. No push, deployment, or new dependency was requested or performed. The preexisting planning changes, including retirement of the Phase 5 plan, remain intact.

## Delivered behavior

- `/people` provides exact username lookup and private, paginated Following, Followers, Incoming requests, and Sent requests management. Both users need usernames before starting a relationship. Renaming preserves relationship identity; old profile URLs do not become aliases.
- New public-account follows accept immediately; private-account follows remain pending until approved. Approval/rejection, cancellation, confirmed unfollow, and confirmed follower removal are implemented. Relationships are directional. Privacy switches preserve accepted followers and pending requests. A repeated Follow never silently promotes an existing pending request.
- `/people/{username}` shows a minimal private header before approval, or permitted profile fields, accepted relationship counts, visible habits, and filtered analytics. `/people/{username}/habits/{habit}` provides read-only permitted metadata and activity. All browsing requires sign-in; private-account approval gates even public-marked habits.
- Shared reports exclude private habits before any score, count, streak, or denominator calculation. They use the subject's timezone, existing historical scoring, and current visibility. Trashed habits are excluded; archived/ended permitted history remains available. Shared current setup excludes tomorrow's pending changes, and shared results contain no private editor URLs.
- Journals, mood, habit notes, N/A reasons, email, registration-name fallback, private category metadata, and checkpoint labels remain absent from shared surfaces. Existing owner management permissions remain owner-only. New habits remain private by default.
- Livewire pages and nested heatmaps pin session identity and reauthorize requests. Expected follow ID/state prevents stale approval/removal of a replacement relationship. Accessible confirmation dialogs restore focus; successful removal focuses the page heading. The navigation switch moved from 768px to 1024px to fit People without tablet overflow.

## Files and database

Core additions: `Follow`, `FollowStatus`, `FollowPolicy`, `UserPolicy`, `SocialAccess`, `FollowUser`, `TransitionFollow`, `SaveProfile`, `InteractsWithFollows`, the follows factory/migration, three People pages, and the follow-confirmation component. User relationships, habit policy, analytics/heatmap, profile settings, navigation, visibility copy, routes, tests, and durable documentation were updated. Scoring formulas and raw activity were preserved.

Migration `2026_10_04_000002_create_follows_table.php` is applied locally as batch 6. It adds restricted user FKs, unique directed pair, self-follow/status/timestamp checks, and incoming/outgoing state-list indexes. No development reset, social seeding, or data backfill occurred. Tests verified populated upgrade and targeted rollback/reapply preserve prior users, habits, configuration/checkpoints, entries, suspensions, categories, and journals. Rollback drops approvals; prefer reverting shared routes while retaining the additive table.

Follow mutations lock both users in ascending ID order, then the relationship. Profile saves lock their own user row and do not rewrite follows. Shared analytics reuse the existing engine with explicit viewer/subject identities and a consistent nonlocking read transaction. An in-flight authorized snapshot may complete; the next request observes revocation. No cross-request authorization cache, report cache, queue, role system, or duplicated scoring engine was added.

## Verification evidence

Commands executed from the repository root include:

```bash
php artisan test tests/Feature/Social --compact
php artisan test tests/Feature/Social tests/Feature/Analytics tests/Feature/Settings --compact
./vendor/bin/pint --dirty
composer ci:check
php artisan test tests/Feature/Social/QueryTest.php --compact
./vendor/bin/pint --test --dirty
npm run build
php artisan route:list --path=people
php artisan migrate --path=database/migrations/2026_10_04_000002_create_follows_table.php
php artisan migrate:status
git diff --check
```

The focused social run passed **32 tests / 1,455 assertions** before the final query, pending-setup, and list/rename coverage. Full verification passed **329 tests / 3,610 assertions** in 86.78 seconds, including Pint. An intermediate focused query check passed **1 test / 14 assertions** in 4.08 seconds. The final full run includes the expanded list measurements and explicit profile-policy checks; no application code changed afterward. Final dirty-file Pint and the production Vite build passed. The route list contains three authenticated People routes, and migration status confirms all prior batches plus follows batch 6 are applied. All 73 local Markdown links/anchors across README and 34 docs files resolve; `git diff --check` passes. The test preview was stopped after verification.

Independent MySQL workers exercise duplicate/opposite-direction follows, privacy versus Follow, approval versus cancellation/recreation, rejection versus recreation, and unfollow versus removal. A separate lock-timeout check proves absent-pair creation waits on the user lock. Snapshot tests change follow, visibility, and privacy state from another connection during report reads, proving consistency and next-request revocation. Destructive migration/concurrency/snapshot tests check configured and connected database names and use only `habit_log_test`.

Chrome **154.0.8037.93** passed **45 browser checks**, with no JavaScript errors. Three isolated accounts exercised private requests, public immediate following, both privacy transitions, approval/rejection/cancellation, unfollow/removal dialogs and focus, stale replacement-request rejection, filtered profile/habit reads, denied owner editing, shared heatmap keyboard/details focus, mobile navigation, CSRF rejection, and journal draft protection. The People index, shared profile, and shared habit fit 320/375/768/1024px; screenshots were inspected at desktop and mobile sizes. Reduced-motion preference was enabled.

The preview used port 8017, `habit_log_test`, normal session/CSRF middleware, and debug disabled. Browser fixtures ran only after database-resetting tests finished. The standard category catalog was seeded into the test database for browser habit creation. Existing Windows Chrome/Node and the neighboring project's Playwright runtime were reused; nothing was installed. Local evidence in ignored `storage/app/phase6-checks` includes `smoke.cjs`, `results.json`, `full-suite.log`, `focused.log`, `query-check.log`, `queries.json`, and desktop/mobile screenshots. `capture.cjs` captures the shared profile at the document top without the sticky-header screenshot artifact.

## Query measurements

The representative fixture has **84 mixed-visibility habits, 252 entries, and 51 relationships**. The accepted viewer sees 56 habits across two history batches. The empty report used 6 queries; the populated report used **17 queries**, including **2 follow checks**, and took **45.12 ms** in the final measurement. Incoming and outgoing 20-row relationship pages each used **3 queries**, taking **1.85 ms** and **1.97 ms**, respectively.

EXPLAIN selected the incoming state index, the directed-pair index plus a small filesort for the outgoing fixture, and a reverse primary-key scan for the habit list. These are observed plans on a small local fixture, not production guarantees. No evidence justified another habit index, analytics cache, or locking optimization. Existing bounded history processing remains in use.

## Findings and design verification

1. Existing habit write policies delegate to owner-only `view`; a separate `viewShared` ability preserves that invariant. Tests compare policy, query, and route results over the account/relationship/visibility/lifecycle matrix and reject followers on owner management routes.
2. Existing analytics authorizes its argument as owner. The new explicit viewer/subject entry point filters first, preventing private habits from leaking through totals or normalized scores. Private projection canaries and unchanged owner-report regressions pass.
3. Current habit configuration can contain a pending next-day goal. Shared metadata now reads the effective version; a regression proves the pending unit stays absent until its effective day.
4. People made the desktop shell overflow at 768px. Moving the switch to 1024px fixed the measured regression; all four viewport checks passed afterward.
5. Shared heatmaps and aggregate reports invoke the profile policy explicitly, keeping authorization in the same policy boundary as shared profile pages. The final full suite includes this review change.
6. Stale request identities and account switching are checked server-side. Actual Livewire request tests reject logout and reused snapshots under another account. Browser stale approval leaves the new request pending.

Product and privacy scope match the confirmed decisions and guardrails. Foreign-key/unique/check constraints, ordered transactional writes, and guarded upgrade/rollback protect data. No social write changes habit/journal records. Focused/full regression, browser, and query evidence cover the applicable [design verification](DESIGN_VERIFICATION.md) items. README, roadmap, product, schema, architecture, UI, security, testing, commands, structure, plan, and ADR document the delivered behavior.

## Limits and rollout

Existing explicit public/followers preferences now grant permitted signed-in read access to stored history; owners should review account privacy and habit visibility. Private defaults are unchanged. Follower removal is not blocking, and public activity on a public account remains readable. Rejected/removed relationships have no retained audit history or notification. Already delivered information cannot be recalled; no live revocation push is promised.

Browser verification is limited to the installed Chrome. Firefox, Safari, real touch devices, a full screen-reader audit, and production-scale load testing were not performed. No anonymous browsing, social feed, comments, messages, notifications, journal sharing, permanent habit deletion, or automatic expiry is included. Broader hardening remains Phase 7.

Suggested commit: `feat: implement Phase 6 followers and shared profiles`.
