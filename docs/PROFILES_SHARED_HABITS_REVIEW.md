# Profiles and shared habit details review

## Refinements implemented — 2026-10-25

The confirmed findings below have been addressed on `dev`. The original inspection is retained as historical evidence; its findings describe the pre-change revision.

- **Fresh analytics:** Follow/unfollow and Refresh profile now replace the nested heatmap using a locked presentation revision. Counts, visible habits, summary percentages, and daily cells agree immediately. New reports clear previously selected day details. Shared habit details also have Refresh activity. Successful heatmap interactions refresh surrounding profile/detail metadata without remounting the selected day or creating an event loop.
- **Revocation:** Shared components authorize during hydration and before report reads. Revoked Livewire requests navigate to the freshly authorized profile or People and omit old report HTML. Direct unauthorized shared-habit URLs still return 404; session identity failures remain rejected. Approval/removal, account privacy, habit visibility, and trash use the existing policies and relationship rules. Another session's changes are observed on the next refresh/interaction, not through background polling or instantaneous remote recall.
- **Responsive content:** Long measurement units wrap in current setup and historical day details. Confirmation headings wrap the full display name. Profile names/bios and habit names/descriptions remain complete. Navigation, refresh, pagination, year/day-list controls, and follow-dialog actions have 44px targets.
- **Pagination:** Invalid pages show No habits on this page, the current filtered count, and Back to first page. A truly empty first page retains its privacy-safe empty state. Valid pages remain newest-first, 20 per page.
- **Presentation:** The pages use `~/people/profile` and `~/people/habits/details`, accessible back links, and a View habits jump link. Shared analytics use subject-neutral copy; setup distinguishes goals, avoidance maximums, checkpoint performance, and Boolean outcomes.

No scoring formula, historical snapshot, raw activity write path, Policy, migration, dependency, or relationship transition rule changed. `RedirectsRevokedSharedAccess` is a small shared Livewire response concern, not an authorization substitute. Shared reads retain field allowlists and owner-only notes/reasons/journals/mood/checkpoint-label exclusions.

### Verification and artifacts

Verification uses guarded `habit_log_test` under the shared lock. The maintained browser fixture generator creates only synthetic accounts and uses application Actions. Generated identifiers, logs, observations, and screenshots stay ignored under `storage/app/profiles-shared-refinements/`.

Verification results:

- `php artisan test tests/Feature/Social tests/Feature/Analytics tests/Unit/Analytics tests/Feature/Auth/SessionBoundaryTest.php tests/Feature/Tracking tests/Feature/Habits --compact`: **290 tests / 4,246 assertions passed** (94.39s).
- After adding hydration checks for generic revoked refreshes, the affected suites were rerun with `php artisan test tests/Feature/Social tests/Feature/Analytics tests/Unit/Analytics tests/Feature/Auth/SessionBoundaryTest.php --compact`: **98 tests / 2,461 assertions passed** (43.15s). This includes nine new cases; the existing revocation test now verifies a navigation effect with no report HTML instead of the old 404-overlay response. Direct URL tests still require 404.
- `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- tests/Browser/shared-profiles.spec.js tests/Browser/social.spec.js tests/Browser/people-design.spec.js tests/Browser/analytics.spec.js tests/Browser/dashboard-design.spec.js tests/Browser/habit-details-design.spec.js`: **16 scenarios passed**, no retries/skips, in 2.1 minutes. All five new shared-profile scenarios passed on the final application code.
- `vendor/bin/pint --test`, `npm run build`, `node --check tests/Browser/shared-profiles.spec.js`, and `git diff --check`: passed.
- `composer audit --locked` and `npm audit`: **zero advisories/vulnerabilities**. No lockfile changes.
- **62 distinct desktop/mobile captures**, zero page overflow; all measured noncalendar main controls at least 44px high. Long confirmation title content fits its client width. **18 axe scans**, zero WCAG 2 A/AA and 2.1 AA violations; zero JavaScript errors. Delayed Escape, Cancel, and Close all restore focus. Representative populated desktop, long mobile detail/day detail, long-name dialog, invalid-page recovery, and removed-access screenshots were visually inspected.

The initial verification attempts encountered local MySQL shutdown/restart and lost execution handles; no passing results were inferred from those attempts. Once the service was healthy, focused tests exposed and helped correct Livewire lifecycle timing (event/redirect effects must be prepared before dehydration) and a guarded Follow fixture assignment. Pint required a second formatting pass for the fixture. The final passing runs above supersede those intermediate failures.

Logs: `php-focused.log`, `php-regressions.log`, `php-final.log`, `browser-focused.log`, `browser-regressions.log`, `composer-audit.log`, and `npm-audit.log` in the refinement artifact directory. `observations.json` records measurements, accessibility, focus, and refresh evidence. There are no remaining reproduced failures among the five requested refinements; the verification limits below still apply.

The new PHP cases cover follow/unfollow report replacement, approval/removal/privacy refresh, denied direct URLs versus revoked Livewire navigation, absent report HTML, generic refresh requests, switched sessions, selected-day metadata refresh, and populated/empty/out-of-range pagination. The maintained browser suite asserts immediate score/list/summary agreement; changes made by an owner in another session; removed access, private-account gates, and habit visibility revocation; and unchanged `performance.timeOrigin` across Livewire refresh/navigation. It checks historical 5-of-10 scoring against a current goal of 20, N/A, private-field canaries, lifecycle states, guests, delayed dialog cancellation focus, 44px controls, keyboard back/date navigation, and long content at both widths.

Updated screenshots use the original 27 prefixes plus `approved-with-refresh`, `removed-access`, `privacy-revoked-refresh`, and `habit-visibility-revoked`, each with `-1440.png` and `-390.png`: **62 captures** under `storage/app/profiles-shared-refinements/`. The original 54 review screenshots remain in their separate review directory. Examples:

- `storage/app/profiles-shared-refinements/public-followed-1440.png`
- `storage/app/profiles-shared-refinements/long-shared-habit-390.png`
- `storage/app/profiles-shared-refinements/long-day-detail-390.png`
- `storage/app/profiles-shared-refinements/long-unfollow-dialog-390.png`
- `storage/app/profiles-shared-refinements/profile-page-99-390.png`
- `storage/app/profiles-shared-refinements/removed-access-390.png`

Limits: installed Chrome through WSL, synthetic 1440px/390px layouts, and existing touch-emulation coverage. No physical-device/manual-screen-reader certification, other browser engines, hosted CI, deployment, or real-time remote revocation was added. No commits, pushes, merges, or deployment were performed. Suggested commit message: `fix(social): refresh shared activity and refine profile presentation`.

## Original inspection — 2026-10-25

Inspection on **2026-10-25**, on synchronized `dev` at `a90edb7` (the merged Entry History milestone). `HEAD`, `origin/dev`, and `origin/main` matched at the start. The working tree was clean. Application code, maintained tests, dependencies, and database schema were not changed. This report is the only new nonignored file. No commit, push, merge, or deployment was performed.

Reviewed `/people/{username}` and `/people/{username}/habits/{habit}` against the guardrails, product requirements, architecture, database design, UI specification, roadmap, design verification checklist, and [ADR-0006](adr/ADR-0006-SHARED-PROFILE-ACCESS.md). “Blocked access” means privacy/visibility denial here: account blocking is explicitly outside the implemented contract.

## Current behavior

| State | Observed behavior |
| --- | --- |
| Own profile | Includes the owner's private habits and explains that others see a permitted subset. No self-follow action. Trash is excluded. Own private habits remain accessible on the read-only shared-detail route. |
| Public profile, nonfollower | Shows shared identity, bio, accepted relationship counts, permitted habit count/list, and analytics based on public habits only. Follow accepts immediately. |
| Private profile, nonfollower | Shows shared display name/username, privacy, Request to follow, Refresh profile, and an approval explanation. Bio, counts, and all activity are absent. |
| Pending request | Shows pending feedback and Cancel request; grants no private-account or followers-only access. Cancel and request-again worked. Existing pending requests survive a switch to public. |
| Accepted follower | Can view public and followers-only habits after passing the account gate, including on private accounts. Private habits remain excluded. Unfollow requires named confirmation. |
| Viewer without a username | Can browse permitted profiles; receives a Profile Settings link instead of follow controls. |
| Denied habit / guest | Private, followers-only, trashed, private-account-gated, and mismatched-owner detail URLs return 404 when unauthorized. Guests are redirected to sign-in on both routes. |

Server-side `SocialAccess`, Policies, scoped queries, and pinned Livewire viewer IDs enforce access. The nested heatmap independently rechecks access. Tests cover the full account/relationship/visibility/lifecycle matrix, owner management-route rejection, switched/expired sessions, locked subject IDs, filtered analytics, stale replacement requests, and revocation after a consistent read. No new unauthorized read or write was reproduced.

Shared detail shows name, description, category label, visibility, archive/end context, current effective setup, and read-only analytics. Pending future configuration is excluded. Shared projections omit notes, N/A reasons, journals/mood, checkpoint labels, and private editor links. Synthetic private-field canaries remained absent from inspected shared HTML. Registration names and emails are not shared identity fallbacks.

Historical calculations remain correct: the synthetic Count habit currently has a goal of 20, while its older 5-of-10 entry displays **50%**, the historical goal **10**, and its separately recorded **Completed** assessment. N/A is neutral; archived/ended history remains available. Profile summaries filter habit membership before computing totals and scores. The subject's UTC timezone was displayed in the browser fixture; PHP coverage also checks differing viewer/subject timezones.

The visual shell uses Terminal Green, neutral bordered cards, monospaced results, and ordinary controls. Profiles have a compact identity/follow card; shared details use a simpler heading and metadata block. Neither secondary page yet uses the terminal-path identifier proposed in the UI specification. Empty profiles show zero visible habits, neutral consistency, and a no-eligible-activity explanation without implying hidden totals. Populated lists paginate 20 rows; the 25-habit fixture's second page shows the remaining five.

## Findings, ranked by severity

No critical or high-severity authorization/data-integrity defect was found in this inspection. The following issues are reproducible on the reviewed revision.

### 1. Medium — profile analytics remain stale after Follow, Unfollow, and Refresh profile

On the public fixture, following changes the visible list from four habits to five, including the follower-only habit. Today's heatmap remains **50.0%**; clicking **Refresh profile** still leaves **50.0%**. A full reload correctly changes it to **33.3%**. After confirmed unfollow, the list returns to four, but the heatmap retains **33.3%**, including the previously permitted follower contribution, until a full reload restores **50.0%**. Summary percentages also remain stale.

The profile heatmap has a stable subject-only key at `resources/views/pages/people/⚡show.blade.php:89`. `InteractsWithFollows::refreshRelationships()` invalidates the parent projections but does not refresh the nested component. This is stale previously authorized presentation, not evidence that a fresh unauthorized analytics request succeeds: those requests are separately rejected or filtered by the passing authorization tests.

**Recommendation:** refresh or remount the nested report after relationship changes and explicit profile refresh, preserving server authorization and resetting invalid selected-day state. Add a browser regression asserting list membership, summary, and heatmap agree immediately after Follow/Unfollow and Refresh profile. Evidence: `observations.json` → `refresh`; `public-followed-*` and `public-unfollowed-*` screenshots.

### 2. Medium — allowed long text overflows shared details and clips confirmation identity

Maximum-length synthetic inputs were used: 30-character username, 50-character display name, 160-character bio, 100-character habit name, 2,000-character description, and 30-character goal unit. Profile identity/bio and habit name/description wrap. The following do not:

- A 30-character unbroken goal unit makes the shared-detail document **408px wide at a 390px viewport**. The metadata paragraph has a 358px client width and 392px content width (`people/⚡habit.blade.php:52–54`).
- Opening that habit's day details increases document width to **442px**; the measurement/unit explanation also lacks wrapping (`resources/views/livewire/activity-heatmap.blade.php`, selected-day measurement paragraph).
- The unfollow dialog clips a 50-character unbroken display name. Its title has a **295px client width / 877px content width**, inside a **343px-wide dialog with 901px content width** (`resources/views/components/follow-confirmation.blade.php:5`). The page itself does not overflow in this case, so document-width checks alone miss the problem.

**Recommendation:** wrap arbitrary user text in metadata, measurement details, and confirmation headings; preserve complete names/units. Test both page width and internal dialog content at mobile widths. Evidence: `long-shared-habit-390.png`, `long-day-detail-390.png`, `long-unfollow-dialog-390.png`.

### 3. Medium — out-of-range profile pages claim there are no habits

At `/people/reviewpaged?page=99`, the header correctly reports **25 visible habits**, but the list says **No habits to display.** Mobile pagination only offers Previous, which leads toward another empty page rather than directly recovering to a valid page. The list's `@forelse` branch does not distinguish an empty collection on an invalid page from a genuinely empty filtered result (`people/⚡show.blade.php:93–100`).

**Recommendation:** retain the filtered total, identify the invalid page, and provide Back to first page or clamp to a valid page. Keep the true zero-visible-habits message privacy-safe. Evidence: `profile-page-99-1440.png` and `profile-page-99-390.png`; compare `empty-profile-*` and `profile-page-2-*`.

### 4. Low — several navigation targets remain smaller than the 44px targets used elsewhere

At 390px, People/back-to-profile and ordinary habit links measure **21px high**, Refresh profile is **40px**, and profile pagination is **38px**. Follow/Request/Cancel-request/Unfollow controls are **44px**, as are primary year navigation buttons. The small heatmap cells have a separate accessible day-list alternative and are not treated as ordinary navigation targets here.

**Recommendation:** give navigation links and list rows adequate clickable padding, and align refresh/pagination/secondary controls with the existing 44px convention. These measurements are usability findings, not a claim of a WCAG 2.1 AA failure; spacing and alternative controls matter. Evidence: `observations.json` → `layouts[].controls` and the mobile profile/detail captures.

### 5. Low — shared-page wording and hierarchy need a focused presentation pass

The shared summary says **Your current run** and **Your longest run**, and date details say **your measurement**, even when viewing another person. Detail setup uses a dense **Goal / limit** line; Boolean and Checkpoint habits show **No numeric goal**, which adds little explanation. The profile's habit links sit below the full analytics section, requiring substantial mobile scrolling before navigation. Refresh profile is visually detached from the identity card and offers disabled-state feedback without a distinct progress message.

**Recommendation:** use subject-neutral analytics wording for shared views; label Goal versus Maximum by type and use suitable nonnumeric setup copy. Add the already-proposed `~/people/profile` and `~/people/habits/details` identifiers with clear back navigation, group identity/bio/counts coherently, and consider a jump link to the permitted habit list. Keep the existing visibility rules, scoring, and editor exclusions. These are presentation recommendations, not new social features or missing current access requirements.

## Interaction and accessibility verification

Keyboard Enter opened historical day details; ArrowRight moved between dates; Escape/Close restored the originating date's focus. The shared-habit backlink returned to the correct profile with keyboard activation. Unfollow dialogs expose an accessible name/description and Cancel, Confirm, and Close controls. Escape, Cancel, and Close restored focus to Unfollow with the cancellation response deliberately held. The existing social browser scenario verifies successful-removal heading focus, approval/rejection, owner removal, stale-request feedback, CSRF rejection, and journal draft navigation protection.

The long-dialog Tab probe reached Confirm and Close, then a browser-focus transition before returning into the native dialog; it did not focus a background application control. This is not a manual assistive-technology certification. Year 999 triggered native minimum validation; an empty historical year rendered neutral context. Follow visibly disabled while its request was held. Error and loading checks did not exercise arbitrary offline/server-failure recovery.

**16 distinct mobile states received axe scans** for WCAG 2 A/AA and 2.1 AA, with **zero violations** (17 scans including one repeated dialog scan). This does not invalidate the overflow/clipping and stale-state findings. No browser JavaScript errors were recorded. Visual inspection included desktop public/own profiles and historical details; mobile long profile, normal/long detail, private denied/approved, out-of-range page, and long confirmation dialog.

## Commands and results

All database checks used guarded **`habit_log_test`** and the repository's shared test lock. The review runner copies the existing isolation/server lifecycle into ignored storage and uses installed Windows Chrome through WSL. Fixtures create synthetic accounts and use application Actions for habit setup/versioning, entries, lifecycle, and follows. No development/personal database was reset.

```sh
php artisan test tests/Feature/Social tests/Feature/Analytics tests/Unit/Analytics tests/Feature/Auth/SessionBoundaryTest.php --compact
vendor/bin/pint --test
npm run build
git diff --check
HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- tests/Browser/social.spec.js tests/Browser/people-design.spec.js tests/Browser/analytics.spec.js
HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' flock --nonblock storage/framework/testing.lock node storage/app/profiles-shared-review/run.mjs
HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' flock --nonblock storage/framework/testing.lock node storage/app/profiles-shared-review/run.mjs --grep 'follow refresh'
HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' flock --nonblock storage/framework/testing.lock node storage/app/profiles-shared-review/run.mjs --grep 'long content measurements'
```

- **89 PHP tests / 2,341 assertions passed** in 46.57s. The initial sandboxed attempt could not connect to loopback MySQL; the rerun with local network access passed. This was an environment failure before database-backed behavior checks.
- **Five maintained browser scenarios passed**, no retries/skips, in 42.7s.
- **Four review scenarios completed across focused runs:** state inspection and historical/keyboard inspection passed in the initial run; follow/loading/focus inspection and supplemental long-text inspection passed in subsequent runs. The initial follow probe incorrectly expected Flux to disable Unfollow during a different, cancellation-specific request. It was changed to record the actual state; no application assertion or code was altered. Focus restoration then passed for all three dismissal methods. Review findings are recorded observations, so passing the harness does not mean the UI has no defects.
- **Pint, frontend build, harness syntax checks, and whitespace checks passed.** Build outputs remain ignored.
- Existing query regression: 84 mixed-visibility habits, 252 entries, and 51 relationships produced a 56-habit permitted report in **17 queries**, including two follow queries (27.56ms locally). This is focused regression evidence, not production load certification.

## Artifacts and screenshots

All artifacts remain ignored under **`storage/app/profiles-shared-review/`**. `setup.php`, `run.mjs`, `playwright.config.js`, and `review.spec.js` are inspection-only harness sources. `fixtures.json` contains synthetic identifiers. `observations.json` records layout, accessibility, refresh, focus, and keyboard evidence; the initial observations are preserved separately. Logs: `php.log`, `browser-initial.log`, `browser-follow.log`, `browser-long.log`, and `browser-regressions.log`. The initial harness-failure screenshot is in `results/` and is not counted below.

There are **54 final screenshots**, with `-1440.png` and `-390.png` for each of these **27 prefixes**:

| Area | Screenshot prefixes |
| --- | --- |
| Profile states | `own-profile`, `public-profile`, `private-denied`, `private-pending`, `private-approved`, `empty-profile`, `viewer-needs-username` |
| Long text and pagination | `long-profile`, `paged-profile`, `profile-page-2`, `profile-page-99` |
| Shared habits | `shared-count`, `shared-checkpoint`, `shared-archived`, `shared-ended`, `followers-shared-habit`, `private-habit-denied`, `long-shared-habit` |
| Historical activity | `historical-day-detail`, `na-day-detail`, `empty-historical-year`, `long-day-detail` |
| Follow interactions | `follow-loading`, `public-followed`, `public-unfollowed`, `unfollow-dialog`, `long-unfollow-dialog` |

Example full path: `storage/app/profiles-shared-review/long-shared-habit-390.png`. All measured desktop states fit their viewport. At mobile width, the long shared-habit page and its open day detail overflow as reported above; the long dialog instead clips internally.

Limits: installed desktop Chrome, 1440px/390px review viewports, and the maintained touch-emulation checks. No physical devices, other browser engines, manual screen reader, live multiuser load, or hosted CI run for this inspection. No fixes were implemented; the findings above remain open.
