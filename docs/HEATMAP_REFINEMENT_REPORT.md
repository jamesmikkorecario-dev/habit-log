# Dashboard Activity History refinement

## Scope and behavior

Started from synchronized `dev` at `0d439e8d9bc7ef92245a58c8884e454a069e6877`, matching `origin/dev` and `origin/main`. The refinement is confined to the owner Dashboard's Activity History presentation. Habit and shared-profile calendars retain their symbol presentation. Dashboard welcome, daily habit cards, Progress Overview, navigation, and other components are unchanged.

Day buttons on the Dashboard contain no visible outcome characters. The existing five green performance bands remain unchanged. The initial refinement used a fixed 1rem cell and 0.25rem gap. The responsive-width follow-up below replaces the fixed cell size while retaining the gap and a 1rem minimum; the labelled calendar still scrolls internally at 390px. Month/weekday alignment still derives from the same report offset and date indices, including 366-day and 54-week years. January remains the initial scroll position.

| State | Visual treatment | Meaning |
| --- | --- | --- |
| Recorded performance | Existing green bands, solid border | Includes a filled level-zero square for recorded 0%. |
| Not logged | Empty dashed outline | Missing past entry; the existing derived score remains 0%. |
| Pending | Heavier dotted outline | No recorded score yet. |
| N/A | Single diagonal stroke | Neutral, excluded from scoring. |
| Unavailable | Diagonal hatching | Missing/invalid data, excluded from scoring. |
| Inactive / future | Empty muted solid outline | Neutral dates, no performance score. |

The default legend is a compact Less–More scale and a keyboard-operable Calendar key disclosure with thresholds, labelled state samples, and explanations. Hover/focus previews provide the date, status, score or no-score explanation, contributing counts, and pending/N/A/unlogged/unavailable counts. A recorded score with pending habits is explicitly provisional. Previews are viewport-bounded, hoverable, and dismissible with Escape; mouse movement or a new focused date can reopen them. They use only already-authorized rendered report fields and make no network request.

Click/tap or Enter/Space retains the existing date-detail request and panel, focuses the rendered heading, and supports Escape/Close focus restoration. Arrow/Home/End movement and the paginated accessible day list remain. The list provides larger controls for users who find compact cells difficult to target. Future-year selection remains rejected by the existing server validation; future dates within the current year remain visible and neutral. No animation or dependency is added.

## Data, authorization, and changed files

- `resources/views/components/activity-heatmap.blade.php`: Dashboard-only symbol-free cells and authorized preview metadata; existing labels, geometry, and selection links retained.
- `resources/views/livewire/activity-heatmap.blade.php`: Dashboard-only legend and rendered detail-heading focus.
- `resources/js/activity-calendar.js` and `resources/js/app.js`: extracted existing calendar navigation plus local preview positioning/dismissal.
- `resources/css/app.css`: scoped cell sizes, special-state treatments, and forced-colors patterns.
- `tests/Feature/Analytics/CalendarPresentationTest.php`: unchanged reports/entries, explicit zero/missing/neutral labels, private-field exclusion, and Dashboard-only scope.
- `tests/Browser/heatmap-refinement.spec.js`: before/after capture, all bands/states, keyboard/pointer/touch, year boundaries, privacy, viewport checks, and axe scans.
- `tests/Fixtures/heatmap-review.php` and `tests/Fixtures/browser-setup.php`: guarded synthetic review accounts/history, used only by the opted-in browser harness against configured and connected `habit_log_test`. Fixture actions create history normally; deliberate missing synthetic measurements exercise unavailable-data presentation.
- `docs/UI_UX_SPEC.md` and this report: updated UI contract and evidence.

No application service, policy, scoring formula, timezone logic, query, database schema, migration, or raw production record changes. Analytics continues to supply the same authorized report. Private notes, reasons, journals, and mood never enter preview metadata. No cache is added. This preserves DG-001, DG-003/004, DG-010/011/012, DG-021/022 and the verification checklist's privacy/data boundaries.

## Verification and screenshots

All database-mutating checks run serially against guarded `habit_log_test`. Browser commands use `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e`, installed Chrome, the repository's isolated temporary storage/cache runner, and production-built assets. No browser packages were installed.

The baseline capture passed before UI changes. Before/after screenshots are retained locally in ignored `storage/app/heatmap-refinement/`: `before-populated-1440.png`, `after-populated-1440.png`, the corresponding `390` pair, and matching `before-empty-*` / `after-empty-*` pairs. Captures use a tall viewport to keep the fixed application header from obscuring the calendar; interaction tests separately use 390×844. The initial capture test was run with `before-` filenames and then changed to `after-` so future runs preserve the baseline images.

| Verification | Command | Result |
| --- | --- | --- |
| Focused analytics | `php artisan test tests/Feature/Analytics tests/Unit/Analytics` | 50 passed / 1,882 assertions. |
| Dedicated browser coverage | `npm run test:e2e -- heatmap-refinement.spec.js` with the environment above | 5 passed (30.7s), including axe scans, all visual states, provisional totals, forced-colors patterns, hover/focus dismissal, mobile bounds, touch, and year validation. |
| Full PHP and Pint | `composer ci:check` | Pint passed; 473 tests passed / 6,540 assertions (153.91s). |
| Production assets | `npm run build` | Passed; browser checks use built assets (`public/hot` absent). |
| First complete browser suite | `npm run test:e2e` with the environment above | 67 passed, 1 failed (8.8m). All heatmap scenarios passed; the unchanged password-settings mobile scenario timed out on its pending-state assertion. |
| Password failure recheck | `npm run test:e2e -- password-change.spec.js` with the environment above | All 3 passed unchanged (16.9s). |
| Complete browser confirmation | `npm run test:e2e` with the environment above | All 68 passed (7.4m), including all five new scenarios and the unchanged password-settings mobile scenario. No automatic retries or skips. |
| Final diff review | `git diff --check` | Passed; 11 scoped, unstaged source/test/documentation files; generated artifacts excluded. |

The existing dashboard geometry scenarios verify every date's weekday and month position, January initialization, December reachability, 2024 leap day, the 2012 54-week calendar, all year controls, and resizing at 375/390/768/1024/1440px. New scenarios verify symbol-free 366-cell populated/empty calendars and no additional Livewire requests during previews. PHP coverage compares complete analytics reports and stored entries before/after presentation interactions, checks private-field exclusion, and confirms individual/shared views retain their prior presentation.

Focused testing caught and resolved scroll-driven preview dismissal, missing touch-detail heading focus, Escape reopening beneath a stationary pointer, and first-open positioning of a hidden mobile preview. Previews remain measurable while hidden, and their hidden coordinates reset to avoid stale positions after resizing. No timeout increase, arbitrary sleep, skipped test, or scoring change was used.

The first full run's password-settings timeout occurred at `password-change.spec.js:161`, while waiting for the submitting/disabled UI state. Its final snapshot showed the successful password-change status after the test's request gate was released. The scenario passed unchanged both in isolation and in the complete confirmation run; no password code, security assertion, timeout, or test was modified. The original evidence is preserved under ignored `storage/app/heatmap-refinement/full-suite-password-failure/`. The exact cause of this intermittent observation remains unproven.

Screenshots, fixture output, logs, and generated build files remain ignored. Manual screen-reader operation, physical touch devices, and other browser engines remain unverified; automated accessibility and emulated touch checks do not substitute for those checks.

The initial implementation stopped without committing, pushing, merging or deploying. Publication was subsequently authorized for the milestone closeout below.


## Responsive width follow-up (2026-10-26)

### Root cause and implementation

The first refinement set every Dashboard date to 1rem inside a content-sized wrapper. At 1440px, 53 columns occupied 1056px even though the scroll region had 1128px of usable width, leaving 72px unused. The wrapper was not missing dates; fixed track sizing prevented it from expanding.

The Dashboard calendar now establishes an inline-size CSS container. Its shared cell dimension is the greater of 1rem or `(container width − weekday rail − rail gap − scroll padding/borders − column gaps) / week columns`. Grid rows, week columns, month labels and weekday labels use that same value. The existing wrapper naturally expands with its tracks. All gaps remain 0.25rem, cells remain square with 3px corners, and legend samples retain their fixed 1rem size. No viewport breakpoint, JavaScript measurement, dependency or animation was added.

A full January–December Monday-first display requires 53 columns in ordinary years, including partial boundary weeks, and 54 in leap years starting Sunday. “52 weeks” does not cover every calendar date; the existing date-derived column count is preserved. Coverage includes 2023 (ordinary year starting Sunday), 2024 (leap year starting Monday), and 2012 (54 columns).

Only four files were changed for this follow-up, preserving the existing uncommitted refinement:

- `resources/css/app.css`: Dashboard-only responsive geometry and shared inset variables.
- `tests/Browser/heatmap-refinement.spec.js`: checks every cell/weekday/month position at five widths, grid fill, square shape, minimum size, December scrolling, container-only resizing, axe checks and screenshots.
- `docs/UI_UX_SPEC.md`: responsive calendar sizing contract.
- `docs/HEATMAP_REFINEMENT_REPORT.md`: root cause and current verification evidence.

No Blade, JavaScript, application/service/policy, scoring, privacy, stored-data, dependency or migration changes were needed for this follow-up. Individual-habit and shared-profile calendars retain their existing geometry. The symbol-free appearance, five bands, special-state treatments, legend, previews, year controls, keyboard movement, details and accessible day list remain covered by the existing browser scenarios.

### Measurements and artifacts

| Viewport | Usable calendar width | 53-column cell | 53-column grid | Internal horizontal scrolling |
| --- | --- | --- | --- | --- |
| 375px | 247px | 16px | 1056px | Yes |
| 390px | 262px | 16px | 1056px | Yes |
| 768px | 616px | 16px | 1056px | Yes |
| 1024px | 872px | 16px | 1056px | Yes |
| 1440px | 1128px | 17.344px | 1127.219px | No |

At 1440px the rare 54-column year uses 16.953px cells across 1127.469px, also with no scrolling. The remaining fraction of a pixel is browser track rounding. At widths up to 1024px the complete year cannot fit at the retained 16px minimum, so internal scrolling remains intentional. There is no page-level horizontal overflow. Every measured date is square, and month starts/weekday rows align. Changing only the card width at a fixed 1440px viewport correctly switches to the minimum and back to fluid sizing.

New before/after captures are separate from the first refinement's baseline:

- Desktop: `storage/app/heatmap-width/before-populated-1440.png` and `storage/app/heatmap-width/after-populated-1440.png`.
- Mobile: `storage/app/heatmap-width/before-populated-390.png` and `storage/app/heatmap-width/after-populated-390.png`.
- Full measurements: `storage/app/heatmap-width/geometry.json`.

These artifacts and command logs remain local and ignored. Desktop/mobile captures were visually inspected; the expanded desktop graph and unchanged mobile minimum preserve the approved design.

### Verification

| Check | Exact command | Result |
| --- | --- | --- |
| Baseline capture before CSS changes | `npm run test:e2e -- heatmap-refinement.spec.js --grep 'captures populated'` | 1 passed (10.8s); baseline saved separately before changes. |
| Focused browser suite, local Vite assets | `npm run test:e2e -- heatmap-refinement.spec.js dashboard-design.spec.js` | 9 passed (1.1m), including all five viewport sizes and axe scans with zero violations. |
| Focused browser suite, production assets | Same command, with `public/hot` temporarily set aside | 9 passed (1.1m); original Vite marker restored afterward. |
| Full relevant analytics PHP suite | `php artisan test tests/Feature/Analytics tests/Unit/Analytics` | 50 passed / 1,882 assertions (7.55s), including unchanged reports/raw entries, privacy and scope. |
| Formatting | `vendor/bin/pint --test` | Passed. |
| Production build | `npm run build` | Passed (475ms). |
| Diff check | `git diff --check` | Passed. |

Logs: `storage/app/heatmap-width-{baseline,browser,built-browser,php,pint,build}.log`. The full application suites were not rerun for this CSS-only follow-up; the earlier full-suite results above belong to the initial refinement.

The browser command uses the existing `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe'` environment and guarded `habit_log_test` harness. The initial sandboxed launch could not bind the local server; the approved run with local networking succeeded. No browser libraries were installed.

The design-verification review confirms Dashboard-only presentation scope, unchanged server authorization/privacy and data paths, no schema or architecture impact, and preserved keyboard/touch alternatives. Other browser engines, manual screen-reader use and physical touch devices remain unverified. The CSS uses modern container units. No commit, push, merge or deployment was performed.

The responsive-width follow-up also stopped without publishing; it is included in the combined milestone closeout below.


## Milestone closeout (2026-10-26)

The combined symbol-free and responsive-width refinements were reviewed against `AGENTS.md`, the design guardrails and `DESIGN_VERIFICATION.md`. No confirmed regression or unrelated change was found. Complete reports and raw entries remain unchanged in the PHP regression; the full browser suite covers shared visibility and revocation as well as owner Dashboard behavior.

Verification ran sequentially to avoid overlapping database resets and WSL load:

| Command | Closeout result |
| --- | --- |
| `composer ci:check` | Pint passed; all 473 PHP tests passed / 6,540 assertions (126.81s). |
| `composer audit --locked` | No security vulnerability advisories found. |
| `npm audit` | 0 vulnerabilities. |
| `npm run build` | Passed (346ms). |
| `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e` | All 69 browser scenarios passed (7.1m), including all six heatmap scenarios, axe checks, the five viewport widths, square/full-width geometry, all special states, keyboard/touch details and shared privacy. No retries or skips. |
| `git diff --check` | Passed. |

The full browser run used production assets while `public/hot` was temporarily set aside, then restored the original development marker. Logs remain ignored under `storage/app/heatmap-closeout-*.log`. The historical password pending-state failure documented above did not recur. Screenshots and geometry files were refreshed and reviewed; desktop cells fill the usable width within subpixel rounding, while narrower containers retain internal scrolling and the 16px minimum.

The publication contains only the 11 source/test/documentation files listed above, including the guarded fixture generator source. Generated test data, screenshots, logs, build output and secrets are excluded. No migrations, dependency updates, backend scoring changes or privacy changes are included. Other browser engines, physical touch devices and manual screen-reader operation remain unverified.

The user authorized the combined commit `refactor(ui): simplify and optimize dashboard activity heatmap`, push to `origin/dev`, and a `dev` → `main` PR titled `refactor: improve dashboard activity heatmap`. Hosted CI results are available on that PR; this document records the local pre-publication verification. Merge and deployment remain outside the authorized scope.
