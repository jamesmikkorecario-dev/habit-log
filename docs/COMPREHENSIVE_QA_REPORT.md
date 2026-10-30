# Comprehensive QA and deployment readiness audit

## Deployment remediation 1 follow-up — 2026-10-30

QA-04/05 now have reviewed application fixes; [the remediation report](PASSWORD_RESET_SECURITY_REPORT.md) records exact behavior, tests and residual risks. Reset and signed email-change links use validated `APP_URL`, production has exact-host rejection and explicit proxy trust, and production mail rejects unsafe transports while removing token-bearing failure diagnostics. Local/test workflows remain supported. Target ingress/TLS, real mail delivery and external logs are still unverified, so the deployment disposition remains **do not deploy yet**. Backup, deletion reconciliation and other audit findings are unchanged. The following audit observations are the pre-remediation baseline, not a claim those two original behaviors persist in the fixed code.

Post-remediation evidence: **522 PHP tests / 7,435 assertions**, **88 browser scenarios** (no skips/flakes), Pint, production build, Composer/npm audits and the production-image build/smoke all passed sequentially. The image rejects unknown hosts with 400 after cache warming and verifies canonical reset origins and unsafe-mail rejection without database access. Synthetic failure tests demonstrate that message/token/credential diagnostics are not logged. No external provider/ingress acceptance or production restore-safety claim follows from these local checks.

## Original audit baseline

Audit window: 2026-10-29–30, Asia/Manila. Revision: `030f24fb0a66bafaf2ebe25005ba779bcf2a0687`. `dev`, `origin/dev` and `origin/main` matched at the start; the worktree was clean. This is an audit, not a release authorization.

**Disposition: do not deploy yet.** The application regression suites pass, but backup/deletion recovery safeguards and target-environment security/operations gates remain unresolved. Two unsafe configuration behaviors were reproduced with synthetic, database-free probes: request-host-dependent reset links and password-reset tokens reaching a production log mailer. No confirmed Critical finding was identified within this audit's scope; that is not a claim that every vulnerability has been excluded.

The prioritized [remediation plan](DEPLOYMENT_REMEDIATION_PLAN.md) separates deployment gates from optional work. No application fixes, dependency updates, migrations, commits, pushes, merges, deployment or backup cleanup were performed. Only isolated synthetic test data was mutated. Existing backup contents were neither opened nor printed, decrypted, imported, uploaded, moved, chmodded or deleted. During the audit, the user's renewed account-deletion policy approval was separately rechecked and documented in `ACCOUNT_DELETION_REPORT.md`; that documentation change is preserved.

## Evidence definitions and scope

- **Verified:** inspected source/configuration or a recorded local/hosted check establishes the specific claim. A passing local check does not establish target-host behavior.
- **Unverified:** no sufficient evidence for the actual production environment, external service or manual/device journey. This is not automatically a confirmed software defect.
- **Not applicable:** intentionally excluded product features or infrastructure without a current requirement; not a missing implementation to invent for launch.

Severity: **Critical** means demonstrated immediate severe compromise/data loss; **High** means a deployment blocker with significant confidentiality, authentication or recovery risk; **Medium** means an operational/accessibility/capacity gap requiring evidence or explicit release acceptance; **Low** means documentation or optional improvement. Findings below distinguish observed defects from unverified release gates.

Reviewed the AGENTS/source-of-truth documents, product and scoring contracts, architecture/schema, UI specification, roadmap, deployment/security runbooks and account ADRs. Static review included routes and middleware, Fortify/provider configuration, Policies/Gates, account actions and session handler, profile allowlists, tracking/journal transactions, analytics projections, client draft guards, Docker/Apache/PHP configuration, migrations, seeders and test harnesses. This is not a formal penetration test, exhaustive source proof or compliance certification.

### Environments

| Environment | Evidence and limit |
| --- | --- |
| Local Linux/WSL PHP | PHP 8.3.6; Node 22.23.2; npm 10.9.8. MySQL test identity is enforced as `habit_log_test` before destructive fixtures. |
| Browser | Installed Playwright Chromium via `/tmp/habit-dashboard-browsers`; one worker, no retries. Test server uses isolated storage/cache, synthetic accounts and captured test mail. Normal CSRF/session middleware remains enabled. |
| Production image | Locally built `habit-log:comprehensive-qa`, PHP 8.3 Apache, non-root runtime. Disposable smoke uses file sessions/cache and array mail, with no database access; it does **not** verify production account mutations, database-session revocation, SMTP or HTTPS. |
| Hosted CI | Current merge revision push workflow and Phase 5 PR workflow both completed successfully. Links below. |
| Target production | Hosting/domain, operator configuration, TLS/proxy, SMTP, data volumes, backup store, external deletion records and monitoring were not supplied or inspected. **Unverified.** |

The current local `.env` reports local/debug mode, database sessions, file cache, synchronous queue and log mail. These are development settings, not evidence of a deployed misconfiguration. Do not copy this environment into production.

## Verification commands and results

Heavy PHP/browser/container/measurement runs were performed separately using the existing guarded harness; no concurrent destructive database suites were started. Audit evidence is local and ignored under `storage/app/comprehensive-qa/`, with browser screenshots/observations also in the existing feature folders under `storage/app/`. The build changes only ignored assets.

| Command/check | Observed result |
| --- | --- |
| `git ls-remote origin refs/heads/dev refs/heads/main` | Both matched the audited revision. |
| `php artisan test --compact` | **509 passed / 7,294 assertions**, 156.71 seconds. Includes migration/rollback tests on the isolated database. |
| `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e` | **88 passed**, 438.66 seconds; JSON: 0 unexpected, 0 skipped, 0 flaky. Cache-isolation runner test passed. |
| `vendor/bin/pint --test` | **Passed**, no formatting changes. |
| `npm run build` | **Passed**, Vite 7.3.7; final JS `app-CCp2_e7D.js`. |
| `composer audit --locked` | **No security vulnerability advisories found.** |
| `npm audit` | **0 vulnerabilities.** |
| `docker compose config --quiet` | **Passed.** |
| `docker build --target production -t habit-log:comprehensive-qa .` | **Passed**; local image manifest `sha256:4362b28772f286f866927212c95c4ea148ce41433c28a7bb46bf1a2264256ed4`. |
| `sh tests/Containers/smoke.sh habit-log:comprehensive-qa` | **Passed**: non-root, runtime-only files, health/login/assets, public document-root isolation, CSRF rejection and sensitive-URL log privacy. Disposable container removed. |
| `php /tmp/habit-log-readiness-probe.php` | **Confirmed findings QA-04/05**; only synthetic values, in-memory logger, no real mail or database writes. |
| `php artisan test tests/Performance/PerformanceTest.php --compact` | **1 passed / 311 assertions**, 307.34 seconds. Measurements below; passing HTTP assertions do not enforce a latency budget. |
| `sh /tmp/habit-log-readiness-container.sh` | Config/route/view cache warmup passed; `/login` accepted an unexpected Host with **200**. `no-store, private`, `no-referrer`, `SAMEORIGIN`, `nosniff` headers present. No DB connection; disposable container cleanup verified. |
| `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e -- tests/Browser/__readiness-a11y.spec.js` | **3 passed**, 3.2 seconds; login/register/forgot-password axe and overflow checks at 390px and 1440px. Temporary audit spec removed afterward; reproduction source retained in ignored evidence. |
| `git diff --check` | **Passed** at closeout. |

Hosted evidence: [merge revision workflow](https://github.com/jamesmikkorecario-dev/habit-log/actions/runs/40892304401), [Phase 5 PR workflow](https://github.com/jamesmikkorecario-dev/habit-log/actions/runs/40889592398). These supersede older closeouts that correctly described hosted CI as unobserved at their original dates. Neither workflow is a production deployment or external restore drill.

The subsequent approved-policy recheck, with unchanged application code, also passed the full PHP suite (509 tests / 7,294 assertions, 151.06 seconds), both account-deletion browser scenarios, Pint and build. Those results supplement rather than replace the full 88-scenario audit run.

### Larger-history measurements

PHP 8.3.6 / MySQL 8.4.11; one initial sample followed by ten warm samples per operation, with query logging enabled. The fixture's reported p95 is the maximum of those ten warm samples, so it is a small-sample indicator, not a population percentile guarantee. Timings use Laravel HTTP/Livewire test calls without a real network/browser. Bytes are uncompressed response content; process peak memory includes the test harness and query log.

| Operation | 10 habits / one year median / p95 | 100 habits / five years median / p95 | Queries, small → large |
| --- | --- | --- | --- |
| Dashboard | 178 / 184 ms | 4,446 / 4,648 ms | 30 → 398 |
| Shared profile | 120 / 131 ms | 3,005 / 3,105 ms | 47 → 293 |
| Date-details update | 171 / 179 ms | 4,486 / 4,569 ms | 22 → 390 |
| Year change | 184 / 195 ms | 4,539 / 4,685 ms | 23 → 391 |
| Individual habit | 47 / 51 ms | 107 / 111 ms | 26 → 32 |
| Entry history | 14 / 21 ms | 15 / 16 ms | 9 → 9 |

The large fixture contains 94,350 entries, 37,740 checkpoint completions, 200 tracking versions and 1,001 relationships; half its habits use sparse monthly records. The small fixture contains 3,650 entries and 1,460 completions. Dashboard warm responses were approximately 562 KB in both cases; peak process allocation reached 75 MiB in the large scenario. Raw samples and query plans are in ignored `storage/app/comprehensive-qa/performance.json`. These measurements establish a concrete scaling concern for aggregate views and interactive calendar requests (QA-07); no production concurrency, compression or user-perceived latency claim follows from them.

## Functional QA coverage

| Feature | Verified behavior and primary evidence | Remaining limit |
| --- | --- | --- |
| Authentication and recovery | Fortify routes, login throttles, session rotation/logout, uniform known/unknown reset feedback, single-use/expired reset tokens, stale credential rejection. `tests/Feature/Auth/`, `auth*.spec.js`. | Real delivery, target proxy/IP attribution, production password-policy external lookup and abuse/load behavior require target checks. QA-04/05 apply. |
| Password/email changes | Current-password checks, account-bound throttles, mailbox confirmation, unique-address race handling, rotating/cancelled/expired pending requests, old credentials/remembered sessions revoked, initiator retained. Concurrency PHP and password/email browser suites. | Real SMTP failure/latency and old/new mailbox receipt unverified; registration-wide email verification remains intentionally disabled. |
| Sessions and account deletion | Owner-scoped listing and individual/all-other revocation; fresh admission checks and late session-writer protection. Immediate transactional deletion of active/archived/trashed owner graph; rollback and inconsistent cross-owner records fail closed; other users/system categories survive. Account deletion concurrency, large-history and multi-browser tests pass. | Database sessions on the same supported connection are required. Global remember-token invalidation is accepted behavior. Requests already authorized cannot be recalled. Backup recovery is not covered by live deletion. |
| Settings | Six-field allowlist and normalized saved feedback; clean/reverted/saved values do not warn; failed saves retain drafts; editing pauses during save; Stay/Escape/focus return, native/Livewire links, Back/Forward, refresh/external unload, multi-form handling, logout/revocation/deletion precedence. Eight Settings browser scenarios plus profile PHP tests. | Native tab close/crash suppression is browser-controlled. Separate-tab profile updates retain existing last-writer semantics. |
| Dashboard, analytics and heatmap | Approved design unchanged. Exact success comparisons, proportional consistency, avoidance maximum/zero, goal-free assessment, N/A neutrality, past unlogged zeros, pending today, historical versions, mean-by-date aggregation and strict streaks. Precision/snapshot/empty/pagination/calendar tests; keyboard/touch/date-browser scenarios. | Real-user scale and target latency budgets remain to be accepted; no persisted analytics cache is introduced. |
| Habits and categories | Private defaults; all five types; optional goals; checkpoint identity/order/validation; deterministic category matching and user-scoped choices; archive/trash/restore preserves history. Creation/edit/lifecycle/category tests and browser flows. | Habit permanent deletion/automatic expiry remains deferred by accepted scope. Account deletion is a separate irreversible operation. |
| Daily tracking and entry history | Whole-number bounds, zero versus blank, duration minutes, checkpoint-derived status, private notes/reasons, stale-write conflict detection, immutable historical configuration, chronological pagination and owner-only editing. Tracking/history/concurrency suites and all five editor browser flows. | Long-running open clients and independent tabs do not provide offline persistence. |
| Journal | Required text, optional 1–5 mood, one owner/date record, conflicts, future read-only dates, private fields, confirmed deletion and navigation/focus behavior. Journal schema/concurrency/analytics regression/browser suites. | No sharing or crash recovery; manual assistive-device testing remains open. |
| Profiles/shared habits/privacy | Signed-in viewers only; private accounts require accepted following; public/followers/private filtering precedes aggregates; hidden contributions and private notes/mood/journal excluded. Pending follows stay pending after privacy changes. Revocation removes access on subsequent requests and refreshes already-open content. Social policy/query/snapshot/concurrency and browser suites. | Already delivered content cannot be recalled. No push revocation/polling or anonymous public viewing is promised. |
| Dates/timezones | User-selectable timezone, UTC default; subject timezone for shared analytics; local midnight/DST/travel/leap-year tests; stored calendar dates never move; future/active-range/suspension boundaries and stale timezone checks. `AnalyticsTest`, tracking history/configuration and journal tests. | Actual browser locale/date-picker and target clock synchronization remain operational/manual checks. |

No new functional regression was observed in the fresh full suites. This claim covers the tested scenarios, not every possible input or concurrency interleaving.

## UI/UX and accessibility

Verified Terminal Green presentation, ordinary labelled controls, responsive layouts, keyboard operation, focus return and status/validation messages in the browser suite. Axe scans use WCAG 2 A/AA and 2.1 AA tags; captured scanned states report no violations. Automated checks cover many widths from 320–1440px, including the requested 390px/1440px for authenticated surfaces. Exact public-authentication coverage is recorded in the supplementary result below.

The supplementary three-scenario public-page check passed for login, registration and password recovery at both **390px and 1440px**, with no page overflow or violations in the scanned axe rules. Screenshots/JSON/log are under `storage/app/comprehensive-qa/`; mobile login and desktop registration were visually inspected. To reproduce, copy the retained `public-a11y-probe.mjs` into `tests/Browser/__readiness-a11y.spec.js`, run the command above, then remove that temporary spec. No persistent test or application code was added. Synthetic mail/host and container probe sources are also retained there as `readiness-probe.php` and `readiness-container.sh`.

Visually inspected current-run screenshots include:

| Local artifact under `storage/app/` | Review |
| --- | --- |
| `dashboard-checks/populated-1440.png`, `dashboard-cleanup/status-390.png` | Summary/calendar layout, status key, focus outline and wrapping fit; the approved heatmap is preserved. Intentional horizontal calendar scrolling is not page overflow. |
| `tracking-design-checks/populated-390.png` | All five entry-type cards stack with readable status/value labels. |
| `journal-design-checks/empty-390.png` | Date navigation, privacy guidance, text/mood labels and save actions fit. |
| `habits-design-checks/populated-1440.png` | Lifecycle actions and long names wrap within cards. |
| `categories-design-checks/review-390.png` | Review-before-create flow and category catalog fit. |
| `entry-history-refinements/duration-1440.png` | Recorded assessment and historical performance remain explicitly distinct. |
| `profiles-shared-refinements/private-denied-1440.png` | Private gate shows permitted identity/request UI without activity. |
| `settings-design-checks/profile-390.png`, `email-change/confirmation-1440.png` | Form groups, visible field focus, privacy details and confirmation labels remain legible. |
| `comprehensive-qa/login-390.png`, `comprehensive-qa/register-1440.png` | Labels, focus ring, authentication actions and desktop/mobile layout fit. |
| `account-deletion/confirmation-390.png` | Irreversible warning, password/acknowledgment controls and Cancel focus fit at mobile width. |

No new visual blocker or automated contrast/focus violation was confirmed in these inspected states. Automated contrast checks do not certify every dynamic state. **Unverified:** NVDA/VoiceOver journeys, physical iOS/Android touch/virtual keyboard, native 400% browser zoom, Firefox/WebKit, forced colors and target language/locale combinations. These are QA-08 release acceptance gates, not claims of observed failures.

## Security/privacy findings and deployment blockers

### QA-01 — High — Existing SQL backup exceeds retention and lacks restrictive file mode (verified local finding)

Metadata-only inspection found `storage/app/phase3-checks/pre-phase3.sql`: 13,832 bytes, UID/GID 1000, mode **0644**, modified **2026-09-28 05:54:09 UTC**; modification age was **31.36 days** at initial inspection. The previous account-deletion inventory identifies it as a plaintext sensitive database export; this audit did not read or reconfirm its contents. It is older than the approved maximum 30-day policy. Directory modes through the repository are 0755, while `/home/james` is 0750: the file mode allows group/other reads, but the ancestor restricts traversal for unrelated users. Do not describe this as demonstrated public Internet access. ACLs, other copies/snapshots and past access are unverified.

Reproduce without opening the backup: use `stat` on that path and its ancestors and compare the modification timestamp with the approved retention policy. `git check-ignore`/tracking checks establish repository exclusion, not encryption. Operator-controlled containment and an explicitly authorized disposition of expired copies are needed. This audit did not change permissions or delete anything. The local `.env` also has mode 0644 behind the same ancestor restriction; secret-file access needs operator review before copying any environment material.

### QA-02 — High — Production backup lifecycle is not implemented here or externally evidenced (verified repository gap; external state unverified)

Compose declares application/Vite/MySQL only; `routes/console.php` has no backup/purge schedule. Host metadata confirms no current-user crontab; listed system timers contain only an unrelated OS package-database backup. No Habit-Log encryption, off-host backup destination, key custody or automatic ≤30-day expiry job was identified in those inspected surfaces. Other users, remote platforms and production hosts were not inspected: **do not infer that no external backups exist**.

The additional `storage/app/phase7-checks/restore-backup.gpg` artifact is 7,816 bytes, mode 0600, modified 2026-10-05 14:00:34 UTC. Prior closeout records it as synthetic and its temporary key as destroyed; only its metadata was rechecked. It is not proof of a usable production backup. Reproduction: inspect service/schedule definitions and metadata; obtain external policy/job/run/restore evidence from the operator. Define retention, encryption, permissions, key recovery, expiry monitoring, backup frequency and approved RPO/RTO before launch.

### QA-03 — High — Independent deletion capture and restore reconciliation remain absent/unverified (deployment blocker)

`DeleteAccount` correctly removes the live owner graph in one transaction, but it does not write a durable deletion record outside restorable database backups. `session_revocations` is inside the application database and is removed with the account; it is not an external deletion-suppression ledger. No recovery process in this repository reapplies deletions and verifies absence before opening traffic/mail.

Reproduce by tracing `app/Actions/Accounts/DeleteAccount.php`, ADR-0011 and the deployment runbook: the external capture/failure contract is a declared prerequisite, not implemented code. A recovery drill must include accounts deleted after the backup and prove that neither those accounts nor their applicable personal/shared data become accessible. Without reliable reconciliation, restoration of a backup containing deleted accounts must remain blocked. Do not run the old pre-account-deletion restore fixture as proof of this requirement. Owner-approved identifiers, durable capture semantics and reconciliation verification are required before production backup/restore is enabled.

### QA-04 — High — Reset URL origin follows request Host; unknown-host protection is not configured (confirmed configuration exposure)

**Follow-up:** application remediation uses canonical credential URLs and exact trusted hosts; explicit proxy handling excludes forwarded Host/prefix. See the current [implementation/verification report](PASSWORD_RESET_SECURITY_REPORT.md). Actual target ingress, static-file host rejection and TLS remain acceptance gates. The reproduction below describes the original defect.

A synthetic production-mode notification probe set `APP_URL` to an intended `.test` origin but bound a request with another `.test` host. The generated reset URL used the **request host**, not the configured origin. `bootstrap/app.php` configures no trusted-host/proxy allowlist, and the production Apache virtual host has no host rejection rule. The supplementary container HTTP probe confirmed `/login` returns **200** with `Host: untrusted.example.test`, including after config/route/view caching. This is a default-image/target-configuration finding, not evidence that an attacker has accessed a deployed system.

Reproduction: bind a synthetic request from `https://untrusted.example.test/forgot-password`, render `Illuminate\Auth\Notifications\ResetPassword` for an unsaved synthetic user, and compare only the action URL's hostname with `config('app.url')`. No real reset token or mailbox is needed. If an exposed deployment accepts arbitrary Host values, recovery mail can carry an unintended origin. Configure/verify canonical host enforcement at ingress/application, an explicit proxy trust chain, HTTPS links and rejected spoofed forwarding headers before release; `APP_URL` alone is insufficient.

### QA-05 — High — Production password resets can write tokens through the log mailer (confirmed unsafe configuration behavior)

**Follow-up:** the production mail boundary now rejects log/array and unsafe resolved transport graphs, strips private transport/notification failure diagnostics and returns generic reset failure without token creation for unsafe configuration. Safe failover and local/test capture remain supported. See [current evidence and configuration requirements](PASSWORD_RESET_SECURITY_REPORT.md). Real provider delivery and upstream/provider logging remain unverified. The reproduction below describes the original defect.

The same database-free probe used `app.env=production`, an in-memory Monolog test handler and `mail.default=log`. Calling the inherited `User::sendPasswordResetNotification` with a synthetic unusable token produced a log record containing that token. No external mail, real reset or persisted token log was created. The email-change request controller explicitly rejects non-delivering mailers in production; password recovery lacks the equivalent guard. `config/mail.php` defaults to `log`, and its failover example includes `log`.

Reproduction: configure the log transport to an in-memory test channel, invoke a reset notification on an unsaved synthetic user, and assert token presence without printing the record. Require a real approved transactional transport and test all security mail paths; reject log/array/fallback-to-log configurations for production authentication mail. Verify controlled password-reset, new-address verification and old-address alert delivery/failure behavior, SMTP credentials/TLS, sender authentication and proxy log redaction. Existing browser mail capture cannot prove delivery.

### QA-06 — High — Target production configuration, migrations and recovery acceptance are unverified (release gate)

A buildable image and successful smoke check do not establish the real domain, HTTPS termination, supported database sessions, a stable secret key, private database networking, persistent storage, correct cache driver, mail or rollback. Database cache/queue fallback configuration names tables not created by this app's migrations; current supported deployment uses file cache and synchronous work. Explicitly set and verify intended drivers rather than relying on framework defaults. Wrong session storage makes individual revocation/account deletion unavailable by design.

Production preflight must prove: `APP_ENV=production`, debug false; stable recoverable `APP_KEY`; actual HTTPS `APP_URL`; dedicated MySQL configuration with no conflicting `DB_URL`; database sessions on the supported same connection/table; Secure/HttpOnly/SameSite cookies and intended domain; file cache with restricted persistent storage (or an explicitly implemented/tested alternative); sync queue; real mail; restricted rotating logs; no Vite, debug tooling or seeded test login. Test cached configuration/routes/views, read/write permissions and private HTML/Livewire no-store through the actual proxy/CDN.

Migration tests passed locally, including additive account changes, FK/index constraints and rollback/reapply in `habit_log_test`. Production requires schema/status inspection, writer suspension, an approved backup/recovery boundary, a timed upgrade rehearsal and compatible-code rollback. Do not use `composer setup`, `migrate:fresh` or generic development seeding. Rollback of tracking/journal/follow schemas destroys data; prefer retaining additive schemas with forward repair/compatible code. No production migration or restore was attempted.

### QA-07 — Medium — Aggregate views slow with larger histories; capacity/recovery budgets remain unverified

Reproduce with `php artisan test tests/Performance/PerformanceTest.php --compact` using the isolated test database. The synthetic 100-habit/five-year dashboard takes 4.45 seconds median with 398 queries, and date/year interactions take about 4.5 seconds. Shared profiles take about 3 seconds. The smaller fixture and paginated history are much faster, as shown above. This is an observed scaling concern, not a declared production-SLO violation: no target latency budget has been approved, and the local query-logging test harness affects timings.

Before release acceptance, agree supported account/history sizes and latency/payload/concurrency budgets, profile the aggregate paths, and measure on the intended host with realistic concurrency. Also measure backup/restore time against approved recovery objectives. Preserve raw scoring and privacy semantics and the approved heatmap design. Optimize demonstrated bottlenecks before deciding whether a cache is justified; do not add one speculatively. A passing performance fixture currently asserts successful responses, not acceptable speed.

### QA-08 — Medium — Manual assistive/device/browser acceptance remains unverified

Complete critical journeys with NVDA or VoiceOver, physical mobile keyboard/touch, native 400% zoom and supported non-Chromium engines. Include heatmap alternatives, menus, stacked dialogs, date inputs, failed saves, focus return and unload prompts. Record actual outcomes and fix any blockers before release acceptance. Automated passes are useful evidence, not screen-reader or device certification.

### QA-09 — Medium — Operational monitoring, log retention and artifact lifecycle are unverified

Application QueryExceptions are sanitized; secrets are excluded from flashed input, and Apache omits reset-token paths/query strings. Existing tests verify those boundaries. The image emits logs to stderr; a rotation/retention/access policy and alerting depend on the platform. Review upstream access/error logs as well as mail transport exceptions, failed backup/purge/deletion capture, storage exhaustion, database errors and health monitoring. `/up` is liveness, not database/mail/recovery readiness. Assign an operator and demonstrate an alert. No runtime OS/base-image vulnerability scan or SBOM verification was available; package audits do not cover those layers.

### QA-10 — Low — Historical documentation can be mistaken for current scope (confirmed documentation drift)

Earlier status/closeout documents describe features or hosted CI as absent/unverified at their original dates. The product document's original approximate-30-day habit-trash wording is superseded by the user's accepted recoverable-trash-only scope; current account deletion and database-backup retention are different contracts. Reproduce by comparing `PRODUCT_REQUIREMENTS.md`, `DATABASE_DESIGN.md`, `ROADMAP.md`, historical status reports and accepted decisions. Label historical snapshots and consolidate current release gates in a later documentation change; do not implement deferred habit expiry/deletion to reconcile old prose.

## Other security review results

- **Verified in inspected paths/tests:** authenticated web routes, server Policies/Gates, locked Livewire identities and same-user hydration checks; owner-derived writes; bound queries/static raw fragments; explicit profile allowlist; private data excluded from shared projections; no newly identified unsafe raw HTML sink in application views/JS; CSRF enforced through real browser requests; validation and scoped rate limits; transactional account and domain writes; hidden password/remember/note/journal fields. These findings are supported by source review and named regression suites, not a blanket absence-of-XSS/injection claim.
- **Verified locally:** tracked environment files are example/testing only; no SQL dump, backup or log is tracked; no private-key marker found in scanned application/config/view/database/route/workflow sources. This narrow scan is not a secret-manager/history audit. No secret values or backup content were printed.
- **Optional defense in depth:** a tested CSP and platform HSTS policy, broader automated secret scanning and dependency/base-image provenance scanning. Do not claim CSP compatibility or impose an untested policy on Livewire/Alpine. HSTS/TLS belong in the target security acceptance.
- **Not applicable to current product:** public API, administrators/RBAC, Redis, WebSockets, background business queues, uploads/avatar processing, comments, reminders, two-factor setup and anonymous shared viewing. No new infrastructure is justified solely because configuration stubs exist.
- **Scheduler:** no application business jobs currently require a scheduler. Future backup expiry or approved housekeeping must have an explicit mechanism/owner. Do not arbitrarily prune session revocation records: their retention protects against late writes until account removal.

## Final review and remaining evidence

Audit-only scope preserves all design guardrails, including the approved heatmap and private defaults. No schema/data lifecycle or application boundary changed. Existing production backup and deletion-recovery requirements remain mandatory. Completion of this report means the audit is delivered; it does not mean deployment is approved. See the remediation plan for owners, ordering, acceptance evidence and optional polish.

| Requested audit item | Completion evidence |
| --- | --- |
| Major features, validation/privacy/errors/edge cases/timezones | Functional coverage matrix, inspected source boundaries and full 509-test PHP / 88-scenario browser results. |
| 1440px/390px UI, keyboard/dialogs/labels/status/contrast | Full browser accessibility checks, three supplementary public-page scenarios and visually inspected captures above; manual assistive/device gaps explicitly retained as QA-08. |
| Authentication, CSRF/XSS/injection/assignment/rate limits/secrets/deletion isolation | Security findings and bounded source/test evidence; QA-04/05 reproduced safely, with no real mail or account mutation. |
| Backup permissions/lifecycle, independent deletion records and restore verification | Metadata inventory, repository/schedule review and QA-01–03; external mechanisms are not claimed verified. Backup size/mode/owner/modification metadata was unchanged at closeout. |
| Migration/rollback and environment/services | QA-06/09, local migration regressions, production-image build/smoke/cache/headers and explicit target checklist in the plan. |
| Sequential checks and isolated data | Command table and ignored terminal logs; guarded `habit_log_test`, no overlapping destructive suites. Temporary browser spec removed and disposable audit container absence verified. |
| Findings with severity, reproductions and recommended order | QA-01–10, plus the prioritized remediation plan with owners and closure evidence. |
| Requested deliverables and publication limits | This report and `DEPLOYMENT_REMEDIATION_PLAN.md`; no application changes, migrations or publication. The separate account-policy documentation recheck is preserved. |

Suggested later documentation commit, only if authorized: `docs: record comprehensive QA and deployment gates`.
