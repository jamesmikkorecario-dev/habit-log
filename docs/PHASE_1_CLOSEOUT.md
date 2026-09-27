# Phase 0/1 gap closeout — 2026-09-27

The recommended closeout work is complete. Phase 0 and Phase 1 meet their current scoped acceptance checks, and Phase 2 can begin. No Phase 2 features were added. The earlier status/completion reports are historical assessments, not the current result.

**Changes and findings.**

| Area | Change | Files |
| --- | --- | --- |
| Profile data loss | Replaced truthiness-based normalization with an explicit empty-string check. Both `"0"` and padded `" 0 "` now persist as `"0"`; blank text still becomes null. | `resources/views/pages/settings/⚡profile.blade.php` |
| Permanent regression coverage | Added four zero-text cases, another-user isolation, rejection of a real Livewire update after logout, rejected-update atomicity, and escaped display-name output. | `tests/Feature/Settings/ProfileTest.php` |
| CI database setup | Start the existing MySQL Compose service with a health wait before `composer setup`. Its initialization creates development and isolated test databases. Push checks cover both `main` and `master`, retaining pull-request checks. | `.github/workflows/tests.yml` |
| Privacy text | Explain that follower approval is future behavior and that all new habits will default private regardless of account preference. | Profile component |
| Theme regression discovered in browser | Flux's automatic appearance initialization removed the dark class on a light system, leaving dark text on the fixed dark shell. Removed that automatic override and set the dark color scheme explicitly. Terminal Green remains the sole supported theme. | `resources/views/partials/head.blade.php`, `resources/css/app.css` |
| Keyboard menu discovered in browser | Replaced the custom ARIA menu with the installed Flux dropdown/menu. Arrow keys move focus; Escape closes the menu and returns focus to the labelled trigger. Logout still uses a CSRF-protected POST form. | `resources/views/layouts/app.blade.php` |
| Motion and feedback | Mobile navigation and saved feedback suppress transitions under reduced motion; saved feedback is a status region and stays cloaked until initialized. | App layout and profile component |
| Documentation | Recorded actual profile columns, constraints, defaults, validation, theme behavior, test boundaries, CI setup, and phase status. Marked earlier reports as historical. | `README.md`, `docs/DATABASE_DESIGN.md`, `docs/UI_UX_SPEC.md`, `docs/TESTING_STRATEGY.md`, `docs/PROJECT_COMMANDS.md`, `docs/PROJECT_STRUCTURE.md`, `docs/ROADMAP.md`, earlier reports, and this report |

**Verification results.**

| Check | Result |
| --- | --- |
| `php artisan test tests/Feature/Settings/ProfileTest.php --filter=zero_text` before the fix | Four failures reproduced the bug: null was stored instead of `"0"`. |
| `php artisan test tests/Feature/Settings/ProfileTest.php --compact` after the fix | 43 passed, 142 assertions. |
| `composer ci:check` after all application changes | Passed: Pint plus the full suite, **66 tests / 197 assertions**. |
| `npm run build` | Passed. The empty application JavaScript chunk is expected because Livewire/Flux supply their scripts separately. |
| `docker compose config --quiet` and `docker compose up -d --wait` | Passed; existing MySQL service healthy. |
| `php artisan migrate:status` | Both existing migrations applied. No new migration was introduced. |
| Workflow YAML and step order | Parsed successfully; database startup precedes application setup. |
| Installed Chrome smoke test | **27 checks passed**, no JavaScript errors. Details below. |
| Git / whitespace review | No staging, commits, or pushes. Changed-file whitespace scan passed. Git has no historical commits and all project files remain untracked, so a normal empty diff is not evidence that no work exists. |

Tests used `habit_log_test`; no development database migration/reset was performed. The temporary browser server also used `habit_log_test`, with normal session and CSRF middleware enabled. The temporary server and browser were stopped after verification. No packages or system browser libraries were installed.

**Browser acceptance evidence.** Used the existing Windows Chrome **154.0.8037.57**, automated through an already available Playwright runtime with a temporary browser profile. This did not add a project dependency. Tested desktop **1280 × 900**, mobile **375 × 812**, and narrow mobile **320 × 812**. Desktop/mobile screenshots were visually inspected after the theme fix.

The 27 passing checks covered:

- Guest denial, registration into the protected dashboard, login after logout, and desktop/mobile logout.
- Livewire/Alpine initialization; dark appearance despite a light OS preference and a stored light preference; dark native controls.
- Visible save feedback; zero-text persistence; trimming; stored privacy/theme preference; visible invalid-username feedback; rejected-update persistence.
- Visible keyboard focus, Enter opening the account menu, ArrowDown selecting an item, Escape closing and restoring focus.
- Mobile navigation, reduced-motion behavior, preferred-name greeting, and no horizontal overflow at the checked widths.
- Missing-CSRF rejection (419), logged-out Livewire mutation rejection (401), unchanged stored bio afterward, and no JavaScript errors.

The local harness and screenshots were temporary artifacts under `/tmp/habit-log-closeout`; this report is the durable record. The permanent PHP regression tests remain in the repository. Browser tests wait for the menu's actual focus/visibility state; the first immediate reads raced Flux's asynchronous focus handling. Reduced-motion verification checks disabled transitions, rather than treating a retained inline duration alone as active animation.

**Design verification and scope.**

- Profile writes remain constrained to the authenticated user; validation, authentication, CSRF protection, and escaped output are preserved. No public profile or habit visibility surface was added.
- No schema changes or migrations were needed. A profile save remains a single-row update, so no multi-record transaction is necessary. Existing schema constraints are unchanged.
- The fixes use Laravel/Livewire conventions and existing Flux controls. No new service layer, framework, or dependency was introduced; no new architectural decision requires an ADR.
- Documentation now separates implemented profile fields and the current shell from future habits, journals, analytics, and followers. Avatar/additional themes remain optional; Phase 2 remains unstarted.
- The scoped suite, formatter, build, and browser smoke checks pass. These support Phase 1 closure, not a claim of exhaustive production hardening.

**Remaining verification limits.** The actual GitHub Actions workflow has not run remotely because no commit/push/PR was requested. Local Compose startup, workflow parsing/order, and the CI check command were verified; a fresh hosted runner remains to be observed. Comprehensive cross-browser, screen-reader, accessibility, performance, and deployment reviews remain Phase 7 work. Migration rollback and real email delivery were not tested in this closeout. Existing password-reset tests use notification fakes.

Suggested commit message, if later requested: `fix: close profile and foundation verification gaps`.
