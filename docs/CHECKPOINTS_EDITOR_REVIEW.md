# CI navigation stabilization and Checkpoints editor review

> The initial inspection below is retained as the baseline. The subsequently authorized implementation is documented under [Implemented refinements](#implemented-refinements).

Reviewed on 2026-10-23 on `dev`. Checkpoints was inspected only; no application UI, business logic, schema, dependency, or authorization changes were made. The implementation change is limited to `tests/Browser/app-container.spec.js`. All browser fixtures and database-resetting PHP tests used guarded `habit_log_test`, serially under the repository's shared test lock. No personal database was reset. Nothing was committed, pushed, merged, or deployed.

## Mobile alignment failure: confirmed cause and fix

The mobile menu in `resources/views/layouts/app.blade.php` uses Alpine `x-transition`. `toBeVisible()` succeeds while the menu is still at its initial `scale(0.95)`. At a 768px viewport, the expected link position is 24px and the scale origin is 384px: `384 + (24 - 384) × 0.95 = 42`. Browser instrumentation measured `42.000011px` with `matrix(0.95, 0, 0, 0.95, 0, 0)`, followed by 24px after cleanup. This occurred with both reduced and normal motion; reduced motion does not eliminate Alpine's initial transform frame.

The test now uses Playwright's retrying `toHaveCSS('transform', 'none')` on the menu panel before measuring the link. Its exact `expect(navBounds.x).toBe(24)` assertion and all other page/container assertions remain unchanged. There are no sleeps, loosened tolerances, or retry-on-failure settings. A persistent wrong transform fails the wait; incorrect final padding still fails the original alignment assertion. No lasting application layout defect was observed, so application markup and CSS were left alone.

The affected test passed **10 consecutive runs**, one worker, **zero retries**, in **1.9 minutes**. Each run checks nine authenticated pages at 375, 390, 768, 1024 and 1440px, bounded inner forms, and mobile navigation. A separate diagnostic scenario passed with both motion preferences and recorded the actual transition frames.

## Checkpoints findings, in priority order

No critical/high-severity functional, scoring, history, or data-integrity defect was found within the reviewed scope. The items below are recommendations for a later UI change, not implemented work.

| Priority | Finding and evidence | Recommended refinement |
| --- | --- | --- |
| Medium usability | A 20-item list has no selected-count, percentage, or derived outcome summary. New, partial, completed and missed states all present the same “Track checkpoints” mode label; saved state copy only says “Editing saved activity.” The all/some/none explanation appears after the entire list. In the long-label fixture, the mobile page is 2,857px tall and Save begins around y=2,651px. | Put a compact “2 of 20 selected · 10%” draft summary and scoring explanation above the checklist. Clearly distinguish unsaved preview from saved activity. Preserve automatic all/some/none derivation, N/A neutrality and explicit Save. Do not introduce a separate manual assessment. |
| Low usability | After saving partial or completed activity, the applicability selector contains two identically named “Track checkpoints” options (values `missed` and `partial`/`completed`). Choosing either derives the same result from the checkboxes when saved, so this is confusing presentation rather than incorrect scoring. | Present one tracking-mode option plus Not applicable, while preserving the existing saved outcome and backend behavior. Use a plain-language mode label such as “Tracking for this date.” |
| Low, error-path usability | A deliberately forged checkpoint ID is rejected without advancing the saved revision, but “The selected form.completed.0 is invalid.” appears 22 times for 20 rows: once per Flux checkbox and twice after the list. An overlong note also exposes `form.note` in its message. These paths were exercised by bypassing native controls in synthetic data; ordinary users cannot select a foreign ID, and the note normally has native maxlength protection. | Show one readable group error, for example “One or more checkpoints are no longer valid. Reload this entry,” plus the existing focused summary. Give private note validation a readable attribute name. Keep strict ID/version validation. |
| Low usability | At the maximum list size, Save and Unsaved changes are far below the first rows, especially after the stacked date card on mobile. The long list remains usable and wraps correctly, but there is substantial travel between editing and saving. | First add the compact progress summary. If further usability testing warrants it, consider a second Save action near the checklist header with the same loading/disabled and explicit-save behavior. Keep the current row target sizes; do not compress them to solve scrolling. |

These refinements should preserve the approved Terminal Green header, existing date/check-in card structure, bounded desktop editor, neutral borders, emerald primary action, owner-only notes, dated setup, and draft protections. The current visual treatment is consistent with the approved editor design. A new layout system, scoring change, schema change, select-all feature, or bulk operation is not needed for this polish.

## Verified behavior

| Area | Evidence |
| --- | --- |
| 1–20 checkpoints | A one-item habit and a 20-item habit were created through the actual UI. Add checkpoint is disabled at 20. PHP habit tests cover zero/21 items, blank/duplicate/overlong labels, reordering and atomic validation. |
| New and unsaved | New dates show no saved activity, all boxes unchecked, and no persisted revision. Keyboard selection shows Unsaved changes without claiming a completed save. Cancelled Reload and date navigation retain the draft. |
| Automatic scoring | One checked out of one saves completed/100%. Two checked out of 20 save partial/10%; all 20 save completed/100%; none save missed/0%. The real habit heatmap was checked after each save, and reloaded selections matched. |
| N/A | The checklist is hidden, neutral guidance and private reason appear, and the heatmap is neutral. Saving/reloading N/A retains the prior 20 checked snapshots. Switching back restores them. |
| Validation | Native note maxlength is 2,000. Server-side overlong-note and invalid checkpoint-ID requests are rejected, show errors, and retain the existing revision. The PHP suite also checks duplicate IDs, private text boundaries and rollback on child-write failure. |
| Stale saves | A second tab's outdated save is rejected with reload guidance; its private draft survives. The newer saved selection remains intact. |
| Historical IDs/labels | A saved past entry retains 20 snapshot rows, the original labels, selected snapshot ID, version and revision after the source setup renames the first item and removes the second. The selected removed source item's historical label/completion remains. PHP history tests additionally advance the date to verify the new configuration becomes effective tomorrow. |
| Keyboard and touch | Space toggles the focused checkbox; Tab moves to the next checkbox; Space toggles that item. A visible native focus outline was measured. A touch-emulated 390px browser taps the label, saves and reloads successfully. |
| Responsive and long labels | All 20 captured state/width combinations at 1440px and 390px fit without horizontal page overflow. Both spaced and unbroken 80-character labels wrap. Label click targets measure at least 44px tall; checkbox glyphs remain 18px. Screenshots confirm desktop columns and mobile stacking. |
| Accessibility and client errors | The populated 20-item mobile editor had zero axe violations for WCAG 2 A/AA and 2.1 AA tags. No page JavaScript errors occurred. Error summary, private text labels and N/A guidance remain present. |

## Commands and results

- `php artisan test tests/Feature/Tracking tests/Feature/Habits tests/Feature/Analytics tests/Unit/Analytics --compact`: **215 passed / 2,255 assertions**, 38.13 seconds. Includes tracking history, stale writes, authorization, migration/concurrency tests, habit limits and analytics formulas.
- `vendor/bin/pint --test`: passed.
- `npm run build`: passed with Vite 7.3.7, production assets generated. Browser review used those assets (`public/hot` absent).
- `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- app-container.spec.js --repeat-each=10`: **10 passed**, 1.9 minutes.
- `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- --config storage/app/checkpoints-review/playwright.config.js navigation.spec.js`: **1 passed**, 3.3 seconds; reduced and normal motion instrumented.
- `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- --config storage/app/checkpoints-review/playwright.config.js checkpoints.spec.js`: **1 passed**, 18.8 seconds. The first attempt stopped because the temporary inspection harness's alert locator matched both summary and field alerts. Scoping that locator to the summary fixed the harness; no application change was needed.
- `node --check tests/Browser/app-container.spec.js` and `git diff --check`: passed.

No new browser libraries were installed. Checks used installed Windows Chrome through the existing WSL runner. This is local evidence, not a hosted GitHub Actions result. Physical touch devices, manual screen readers, Firefox and Safari were not tested. The axe scan covers the populated mobile editor, not every application state. The focused PHP suite is not the complete application suite.

## Local evidence and screenshots

Artifacts are ignored under `storage/app/checkpoints-review/`; they are local evidence and are not included in Git. The inspection harness and its configuration also live there. Logs: `php-tests.log`, `navigation-repeat.log`, `navigation-test.log`, `checkpoints-browser.log`. Structured observations: `navigation-geometry.json` and `observations.json`.

Each filename below exists with both `-1440.png` and `-390.png` suffixes:

- `one-new` — one-checkpoint empty editor.
- `twenty-new` — full 20-checkpoint setup, including 80-character labels.
- `twenty-partial-draft` — two selected, not yet saved.
- `twenty-partial-saved` — two selected, saved partial activity.
- `twenty-completed` — all 20 checked and saved.
- `twenty-missed` — none checked and saved.
- `twenty-na` — neutral state and private reason.
- `note-validation` — server-rejected overlong note.
- `checkpoint-id-validation` — rejected checkpoint ID and repeated error rendering.
- `historical` — original labels/selection after future setup changes.

`stale.png` captures the stale-save error at 390px. There are **21 screenshots** in total. Representative captures, including populated desktop/mobile, N/A, validation and historical states, were visually reviewed.

Suggested commit message for the test correction and this report: `test: wait for mobile navigation transition before measuring alignment`.

## Implemented refinements

The follow-up authorized Checkpoints changes on `dev`. The baseline review and the uncommitted navigation-test correction above are preserved. The four findings are now addressed:

1. **Reactive historical progress:** a summary above the list shows completed/total, percentage and Completed/Partial/Missed. For example, selecting two of the effective 20 checkpoints immediately shows `2 of 20 completed · 10% · Partial`. It distinguishes Not saved, Unsaved preview and Saved activity. N/A shows `Not applicable · Neutral`, without a percentage. Alpine reads the existing deferred Livewire selection; toggling does not auto-save. The server remains authoritative for scoring and persistence. The summary is keyed by version ID/revision and uses that version's snapshot IDs, so future setup changes cannot change the historical denominator.
2. **One tracking option:** the selector renders one Track checkpoints option with the existing loaded status value, plus Not applicable. It retains the same Livewire binding and derived-save behavior; no separate assessment or mode field was added.
3. **One readable group error:** the Flux checkbox and label use an explicit field wrapper so each row no longer creates its own copy of the group error. A single error beside the group covers malformed, duplicate or foreign IDs. Controls reference it and expose invalid state. Checkpoint-only messages also name private note and N/A reason limits clearly. Validation rules and all other habit types' messages are unchanged.
4. **Save near the summary:** an ordinary top Save button and the existing bottom button submit the same form. Both remain in normal document flow, fill the mobile width and fit compactly on desktop. A held browser request verified disabled actions, disabled text/checkbox input, ignored checkpoint clicks and exactly one POST even when the second submit button was clicked programmatically. No sticky overlay or separate save handler was introduced. In the same 390px, 20-item fixture, the first Save moved from approximately y=2,651px to y=1,113px.

The loading check exposed that Flux's custom checkbox does not inherit native fieldset disabling. Checkpoints now explicitly receive disabled state for save/reload and read-only dates, with an accessible group state while loading and per-control state for read-only dates. Read-only behavior was verified again after Reload. During implementation, browser assertions also caught a disabled-state announcement gap and a native fieldset attribute mistakenly treated as an Alpine expression; both were corrected before final verification.

Changed implementation files: `resources/views/pages/tracking/⚡edit.blade.php`, `resources/js/checkpoint-progress.js`, its import in `resources/js/app.js`, and checkpoint-only messages in `app/Livewire/Forms/HabitEntryForm.php`. New regressions are `tests/Feature/Tracking/CheckpointEditorTest.php` and `tests/Browser/checkpoint-editor.spec.js`. `docs/UI_UX_SPEC.md` describes the finished behavior. The existing `tests/Browser/app-container.spec.js` fix remains unchanged.

No migrations, dependencies, Actions, Policies, analytics formulas, historical IDs/labels, N/A retention rules or authorization rules changed. Count, Duration, Avoidance and Yes/No controls and behavior remain unchanged.

### Refinement verification

- `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- app-container.spec.js --repeat-each=10`: **10 passed**, 1.9 minutes, one worker and zero retries, after the final Checkpoints implementation. The preserved exact 24px assertion remains unchanged.

- `php artisan test tests/Feature/Tracking/CheckpointEditorTest.php --compact`: **8 passed / 70 assertions**. The new tests verify one mode option across saved outcomes, N/A snapshot preservation, one readable error for invalid/duplicate/non-list/oversized selections, private text limits, historical setup and read-only dates.
- `php artisan test tests/Feature/Tracking tests/Feature/Habits tests/Feature/Analytics tests/Unit/Analytics --compact`: **223 passed / 2,325 assertions**, 40.23 seconds.
- `HABIT_LOG_E2E=1 E2E_NODE='/mnt/c/Program Files/nodejs/node.exe' npm run test:e2e -- checkpoint-editor.spec.js count-editor.spec.js duration-editor.spec.js avoidance-editor.spec.js entry-editor-design.spec.js core.spec.js analytics.spec.js history.spec.js`: **19 passed**, 2.3 minutes. This includes all five types, N/A, history, analytics, stale saves, validation, responsive layouts, loading locks, private data and browser Back draft guards. The Checkpoints scenario passes empty/partial/complete/saved/N/A/historical/invalid/20-item states, keyboard and touch behavior, and read-only reloads. Both the populated and invalid-checkpoint mobile states have zero axe violations under the tested WCAG tags, with no page JavaScript errors.
- `vendor/bin/pint --test`, `npm run build`, JavaScript syntax checks and `git diff --check`: passed. The final build used Vite 7.3.7; no Vite hot file was present during browser verification.

### Updated screenshots and remaining limits

The updated evidence is isolated from the baseline under **`storage/app/checkpoint-editor-checks/`**. It contains the same ten desktop/mobile state pairs listed above, plus `stale.png` and two `twenty-partial-saved-bottom-{1440,390}.png` viewport captures verifying the original bottom Save action: **23 screenshots** total. Representative new, populated, saved, N/A, invalid, and historical captures were visually reviewed. The test also measures all 20 state/width combinations with no horizontal overflow and label targets of at least 44px.

Key files:

- `storage/app/checkpoint-editor-checks/twenty-partial-saved-1440.png`
- `storage/app/checkpoint-editor-checks/twenty-partial-draft-390.png`
- `storage/app/checkpoint-editor-checks/twenty-completed-390.png`
- `storage/app/checkpoint-editor-checks/checkpoint-id-validation-390.png`
- `storage/app/checkpoint-editor-checks/historical-1440.png`

Raw logs and `observations.json` stay ignored. Synthetic fixtures use `habit_log_test`; no personal database was reset. No known issue remains in the requested refinement scope. Physical devices, manual screen readers, other browser engines and a hosted CI run remain unverified; local Chrome/axe and touch emulation do not establish those results. Nothing was committed, pushed, merged or deployed.

Suggested commit message for the refinement: `feat: clarify checkpoint progress and entry controls` (the preserved navigation fix can remain a separate test commit).
