# Settings unsaved-change protection — account management Phase 5

Status: implemented and locally verified on 2026-10-29; ready for review. Starting from clean synchronized `dev` / `origin/dev` / `origin/main` at `d375d28124b4b02f452eda597ee895f29675f6a6`. The implementation pass did not authorize publication. The subsequent Phase 5 closeout request authorizes one reviewed commit, a push to `origin/dev`, and a `dev` → `main` PR; merge and deployment remain unauthorized.

## Form audit and intended behavior

| Surface | Draft fields | Confirmation behavior |
| --- | --- | --- |
| Profile | Username, display name, bio, privacy, timezone, theme | Compare against loaded or successfully saved values; validation/network failures retain the previous baseline. Disable editing during save. |
| Change password | Current password, new password, confirmation | Empty credentials are clean. Submit intentionally leaves this form; warn if it would discard another unfinished form. |
| Email request/replacement | Proposed email and current password | Failed proposed email remains unsaved; passwords clear on server validation as before. |
| Email resend/verification | Current password | Guard typed input; successful submission proceeds to the existing security redirect. |
| Cancel pending email | No editable fields | Does not itself become dirty; warn before losing another form's draft. |
| Session revocation | Current password | Closing its dialog resets only this form; session selectors are not draft content. |
| Account deletion | Current password and irreversible acknowledgment | Cancelling clears the dialog. Confirmed deletion takes precedence over draft warnings. |
| Explicit logout | No draft fields | Always proceeds without a second leave warning. |

The unused two-factor recovery component is not an enabled Settings route. Hidden CSRF tokens, account IDs, pending-email generations and session selectors are excluded from dirty comparisons.

## Implementation boundaries

`resources/js/settings-guard.js` extends the existing navigation guard via `resources/js/app.js`. It compares values, intercepts native in-app links and Livewire navigation, uses the existing numeric history-position mechanism to restore cancelled Back/Forward, and uses native `beforeunload` for browser-owned exits. A native accessible dialog provides Stay and keep editing / Discard changes and leave, safe initial focus, Escape and focus return. Document listeners are registered once; state is scoped to current forms.

Profile success emits only the six normalized saved profile values after the existing transactional save. Validation responses do not advance the baseline. Profile controls are disabled/inert while saving. This does not introduce optimistic locking or change the existing last-writer behavior between separate authenticated browser tabs; each tab tracks its own loaded/acknowledged values, and another tab's save does not silently clear a local draft.

Security inputs remain in the native form DOM. No new URL, log, browser-storage, history-state or Livewire payload contains passwords. No autosave, persistent draft, dependency or migration is added. Native security submissions warn about other dirty forms before proceeding; logout and confirmed deletion bypass draft warnings. Livewire authentication failures and redirects clear sensitive inputs and leave stale state without a draft veto. Revocation is detected on the next server request, not by adding polling. Back/forward cache restoration reloads Settings to recheck authentication.

## Verification

- `php artisan test tests/Feature/Settings/ProfileTest.php --compact`: **45 passed / 165 assertions**, including normalized saved-value feedback and existing authorization/allowlist tests.
- `php artisan test --compact`: **509 passed / 7,294 assertions**, 199.34 seconds.
- `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e -- tests/Browser/settings-guard.spec.js`: **7 passed** after the network-save recovery fix; the final full run additionally covers external navigation and failed destination loading.
- `PLAYWRIGHT_BROWSERS_PATH=/tmp/habit-dashboard-browsers HABIT_LOG_E2E=1 npm run test:e2e`: **88 passed**, 7.6 minutes, with one worker and no retries; the runner's cache-isolation test also passed.
- `vendor/bin/pint --test`: **passed** after formatting the new PHP assertion. This changed whitespace only.
- `npm run build`: **passed**, Vite 7.3.7; final application asset `app-CCp2_e7D.js`.
- `composer audit --locked`: **no security vulnerability advisories**.
- `npm audit`: **0 vulnerabilities**.
- `docker compose config --quiet`: **passed**.
- `docker build --target production -t habit-log:settings-guard-check .`: **passed**; the image contains the same final JS asset as the host build.
- `sh tests/Containers/smoke.sh habit-log:settings-guard-check`: **passed**: non-root execution, runtime-only files, health/login/assets, document-root boundaries, CSRF rejection and log privacy. The disposable container was removed by the script; no database writes or deployment occurred.
- `git diff --check`: **passed**. At the implementation verification checkpoint, HEAD remained the starting commit on `dev`; that pass performed no commit, push, merge or deployment.

Verification ran sequentially against the guarded test database. Logs are ignored local artifacts under `storage/app/settings-guard-*.log`; no generated fixtures, screenshots or logs are included as source changes.

The first expanded browser run passed 18 of 19 scenarios and exposed competing disabled-state handlers on the profile Save button after a network failure. Removing the redundant `wire:loading.attr="disabled"` leaves Livewire's native submit disabling responsible for that button; the regression now verifies both editable fields and an enabled retry button. No vendor code changed. The final review also keeps the guard active until a Livewire page actually swaps, so a failed destination fetch cannot leave the current page unprotected.

Screenshots are local ignored artifacts: `storage/app/settings-guard/confirmation-1440.png` and `confirmation-390.png`. Both were visually inspected; the dialog fits, labels/actions remain legible, and no horizontal overflow appears. Axe checks report no WCAG A/AA violations on these captured states. Keyboard checks cover safe initial focus, Tab, Escape and focus return.

## Requirement coverage

| Requirement | Implementation and evidence |
| --- | --- |
| 1. Audit every Settings draft | Form audit above; markers cover profile, password, shared email forms, session and deletion dialogs. |
| 2, 5. Compare actual values; clean after save/revert | WeakMap baselines, normalized profile success event; browser value/revert and successful/failed-save scenarios; PHP normalized-value event assertion. |
| 3. Navigation paths | Native and Livewire links, restored Back/Forward, native reload/external prompts; browser navigation/history/reload scenarios. Tab-close uses the same native unload hook with the limitations below. |
| 4, 10. Accessible responsive confirmation | Named/described native dialog, safe focus, Escape and focus return; keyboard/Axe checks and inspected 1440px/390px screenshots. |
| 6. Failed saves, multiple forms, in-flight edits | Validation/network failures retain baseline; fieldset disabled/inert during save; browser failed-save, request-delay and multiple-form scenarios. Separate-tab save semantics remain unchanged as documented above. |
| 7. Secret handling | Native credentials never enter baseline values, persistent storage or confirmation copy; browser storage/history assertion; existing password/email validation and container log-privacy regressions. |
| 8. Security behavior | Existing server-side checks unchanged; dirty revoked-session/account-switch/logout scenarios plus complete password/email/session/deletion and PHP authorization suites. |
| 9. Stale state and duplicate warnings | Document listeners installed once, dialog bindings tracked by node, fresh Settings history loads; repeated traversal and failed destination-fetch regressions. |

## Limits and production prerequisites

Browsers control refresh/close/external-navigation prompt wording and may suppress prompts without user activation, on mobile process termination, or during a crash. No recovery after process termination is promised. Passwords cleared by a validation redirect are intentionally not reconstructed as drafts; a retained invalid proposed email remains dirty. Cancelling a leave prompt preserves the current draft; cancelling a credential dialog clears its own secret fields.

The Phase 4 backup deployment blockers remain unchanged: encrypted restricted backups with automatic maximum-30-day expiry, durable deletion-suppression records outside restored backups, and verified reconciliation before restored access. This phase does not implement those controls or touch the local backup.

## Files and design review

- Navigation: `resources/js/settings-guard.js`, `resources/js/app.js`, and the new `resources/views/components/settings-unsaved-dialog.blade.php`.
- Integration: app layout, profile/security/session/deletion views and shared email-change forms. Profile emits normalized saved feedback and suspends editing while a save is pending.
- Tests: `tests/Browser/settings-guard.spec.js`, existing `settings-design.spec.js`, and `tests/Feature/Settings/ProfileTest.php`.
- Documentation: account-management plan, product requirements, architecture, UI specification, roadmap, account review, this report and ADR-0012. Database design needs no change: no schema, foreign key, migration or data lifecycle changed.

Review against `DESIGN_VERIFICATION.md`: server-side ownership, CSRF and visibility remain authoritative; profile allowlisting and transaction handling remain intact. No raw activity, analytics, private notes/journals/mood, category or follow behavior changes. Existing security confirmations remain required. New controls use ordinary labelled buttons, visible keyboard focus, 44px targets and Terminal Green styling. Verification results above provide the behavioral and security evidence; production restore safety is outside this phase and remains unverified.

Authorized closeout commit message: `feat: protect unsaved Settings changes`.

## Closeout review

Reviewed the complete implementation diff, all new source/test files, native form markers, history restoration, dialog listener lifecycle, normalized success baselines, security-transition precedence, and current desktop/mobile captures. No blocking navigation, focus, secret-disclosure or authorization finding remained. The recorded 509 PHP tests / 7,294 assertions and 88 browser scenarios were verified against local logs and the browser JSON result (zero failures, skipped or flaky scenarios). Formatting, build, audit and container evidence was also checked. Application/test code has not changed since that verification, so the passing evidence is reused; this closeout only updates publication documentation.

Only the reviewed application code, test source and documentation are included. Screenshots, logs, generated fixtures, backups and secrets remain excluded. Screenshot paths above are local review references, not committed repository images. PR metadata and GitHub Actions provide the publication and hosted-CI status; local verification is not a claim that hosted CI has completed. Existing backup data and production prerequisites remain untouched.
