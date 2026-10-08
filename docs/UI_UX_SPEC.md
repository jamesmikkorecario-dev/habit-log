# UI/UX specification

Design mobile-first for comfortable daily use, then enhance wider layouts. Use near-black surfaces, phosphor-green accents, monospaced identity, subtle borders, restrained shadows, and contribution-grid cues. Phase 0 provides only one default theme foundation.

Terminal styling is decorative: use standard links, buttons, forms, checkboxes, menus, dialogs, and touch controls—never typed commands. Maintain semantic markup, keyboard navigation, visible focus, sufficient contrast, reasonable touch targets, reduced-motion support, and responsive layouts. Heatmaps require accessible labels and a non-color status cue.

Phase 0 proves the stack with guest authentication screens and a protected shell containing branding, a user greeting, “Foundation ready,” and logout. It must not contain habit, analytics, journal, profile, theme-selection, or social features.

## Current Phase 1 interface

Phase 1 replaces the foundation-only shell with Dashboard and Settings navigation, a user menu, mobile navigation, and a preferred-display-name greeting. The dashboard remains a placeholder for future habit features; the Phase 0 “Foundation ready” message is no longer required in this phase.

Profile settings contain username, optional display name and bio, private-account preference, and the Terminal Green theme selector. Username is required on save, uses 3–30 lowercase letters/digits/underscores starting with a letter, and must be unique. Display name is limited to 50 characters; bio to 160. Blank values clear optional text; `"0"` remains valid text. Validation is server-side and errors appear beside the fields. Saving provides visible feedback.

Account privacy is a stored preference until followers are implemented. Help text must not imply that habit defaults depend on account privacy: all new habits will default private. Only Terminal Green is available; avatar and additional themes are not required to close Phase 1.

Terminal Green stays dark regardless of system light/dark preference. The desktop account menu uses Flux keyboard navigation and returns focus to its trigger on Escape. Mobile navigation and save feedback respect reduced-motion preferences; save feedback is a status announcement.

Before closing Phase 1, exercise authentication, profile saving/reloading, validation feedback, mobile navigation, logout, and keyboard focus in a browser. Broader accessibility and performance reviews remain in Phase 7.

## Phase 2 interface

Desktop/mobile navigation now includes Habits and Categories; the dashboard links to the habit list and creation form. Categories show system and personal options, with a two-step custom creation form: first review deterministic recommendations, then select an existing category or explicitly create your own. Personal category names, descriptions, and suggestions are owner-only. Category creation is a separate save; the habit form explains that leaving it discards unsaved form input.

The habit list uses Active, Archived, and Trash controls, a category filter, stable newest-first ordering, and 20-row pagination. List rows show name, category, type, visibility preference, dates, and lifecycle state. Detail/edit pages are owner-only. Trashed habits have only a summary and Restore control in Trash; normal detail/edit routes exclude them.

Create/edit forms configure one category, a name (100 characters), optional description (2,000), one of five types, optional goals/units where appropriate, required start date, optional end date on/after start, and a private-default visibility preference. Numeric goals are whole numbers up to 999,999,999; avoidance allows zero, count/duration require positive values when provided. Duration means minutes. Checkpoint habits require 1–20 distinct labels up to 80 characters, with ordinary Add/Remove/Up/Down buttons. Switching types clears incompatible settings on save and explains that effect in the form.

Visibility choices are stored preferences: sharing is not available in Phase 2, and all management access is owner-only. Account privacy does not change the new-habit private default. Daily tracking and heatmaps remain future work.

Archive/unarchive preserve configured dates; restore preserves the prior active/archived state. Moving to trash requires a named dialog with Cancel and Confirm controls. Escape cancels and restores focus; status messages announce successful changes. Trash has no automatic expiry or permanent-delete control. Dialogs respect reduced motion. Server-side validation provides field errors; output is escaped and literal `"0"` text is preserved.

## Phase 3 daily tracking interface

Profile settings add an IANA timezone selector, default UTC. The stored preference defines today and the default new-habit start date. Saved entry dates never move when timezone changes.

Desktop/mobile navigation includes Tracking and the dashboard links to Track today. `/tracking?date=YYYY-MM-DD` displays an owner-scoped, paginated daily overview with Previous day, Today, Next day, and a native date input. It distinguishes Not logged, explicit outcomes, N/A, and unavailable dates. There is no automatic missed-entry creation or scoring. Notes and N/A reasons never appear in summaries.

`/habits/{habit}/entries/{date}` records one day. Boolean uses completed/missed; numeric types accept whole counts/minutes/occurrences and an explicit personal assessment; checkpoints derive completed/partial/missed from checked items. All types support N/A and an optional private note. N/A has an optional private reason and retains previously saved measurements/checkpoints. Numeric zero is meaningful; missing values are not coerced to zero. Saving is explicit, with field errors, announced feedback, and reload guidance for stale edits.

The editor protects unsaved activity when navigating away, supports historical dates, and shows the effective setup for that date. Existing dates that become future-local dates after travel remain readable but cannot be edited until eligible again. `/habits/{habit}/entries` is a paginated private history list with links to the editor. Archived/ended habits retain eligible historical access; trashed habits have no tracking access.

Configuration detail/edit pages distinguish current setup from pending next-day changes after the first entry. Historical labels, type, goals, units, and dates remain intact. Pending changes can be cancelled explicitly. Archive/trash starts a neutral gap tomorrow; unarchive/restore resumes on the current local date, preserving prior archive state. Permanent deletion and expiry remain absent.

Use keyboard-accessible standard controls, visible focus, text outcome cues, reduced motion, and announced status/error feedback. Navigation switches to its mobile form below 768px to accommodate the additional link. Browser verification and its limitations are recorded in the Phase 3 closeout after acceptance.

## Phase 4 heatmaps and analytics

The owner dashboard displays overall profile activity; habit details display individual activity. Both include a calendar-year heatmap, year controls, lifetime current/longest strict streaks, trailing 30-day consistency, and selected-year consistency through local today. Show the fresh owner timezone/as-of date, eligible denominator, N/A counts, and pending/unavailable counts. Archived/ended habits keep eligible history and their last run across inactive intervals; trash removes the habit from overall totals until restored. No public profile route is introduced.

Performance uses historical goals and raw values; goal-free numeric habits use the recorded assessment. Details distinguish that assessment from goal-based performance. Avoidance at/below its maximum succeeds, including zero maximum/zero occurrences. Partial progress contributes proportionally to consistency. Past unlogged dates score zero and break strict streaks, while today is pending until recorded. N/A and inactive dates preserve but do not add to streaks. Overall today remains provisional while habits are pending; pending habits prevent an overall success from extending its run.

The Monday-first grid uses five increasing green intensity bands (0, above 0–25%, above 25–50%, above 50–75%, above 75–100%). Neutral cells are dashed. Text/symbol cues distinguish full success, zero, unlogged, N/A, pending, unavailable, and inactive/future dates. Date buttons announce date, outcome, percentage, and pending/unavailable counts. Calendar arrow keys move one day vertically or one week horizontally; Home/End reach year boundaries. Enter/Space or touch opens details. Escape or Close returns focus to the originating date/list control, resolved after Livewire updates. A paginated textual day list provides larger ordinary controls. Only the calendar region scrolls horizontally on narrow screens.

Year input has an explicit Go action and field validation; query/state years are validated server-side. Year changes clear date selection and reset the list. Date details link to the existing daily editor/overview when eligible; private notes and N/A reasons are never included. Refresh/navigation recomputes local today; no live midnight timer is promised.

## Phase 5 private journal

Journal appears in desktop/mobile navigation and the dashboard links to Write in journal without a content preview. `/journal` opens owner-local today; the `date` query selects an earlier date. Previous day, Today, Next day, and a native date input with Go provide navigation. Saved dates never move when timezone changes; retained future-local journals are readable but cannot be edited or deleted until their date is current/past again.

Journaling works without habits and permits historical backfill before account creation. Exact real calendar dates from `1000-01-01` through `9999-12-31` are supported; navigation cannot cross those boundaries. Invalid initial dates or absent future dates are not found; invalid date-form input preserves the current editor and draft. A timezone change after loading the editor rejects a write and asks for a reload.

The owner writes required plain text (up to 10,000 characters) and optionally selects mood 1–5 (Very low, Low, Okay, Good, Very good) or Not set. Only explicit Save persists changes; whitespace-only text is rejected and literal zero/newlines survive. Missing dates stay empty until saved. Field errors and save/delete feedback are announced. Reload requires confirmation before discarding a draft; stale writes preserve drafts and request a reload. Dirty-form guards cover date/app navigation and browser unload where supported.

Delete journal opens a dated permanent-deletion confirmation; unsaved changes receive a separate discard warning first. Cancel/Escape preserves data and returns focus. Confirmed deletion removes both text and mood, leaves the same date blank, and moves focus to the heading when the delete trigger disappears. There is no journal trash or undo. Journals and mood never appear in profile activity or shared surfaces.

## Phase 6 people and shared activity

Phase 6 moves the navigation switch to 1024px so the additional People link fits; narrower screens use the mobile menu. People navigation opens `/people`, with exact username lookup and Following, Followers, Incoming requests, and Sent requests tabs. Lists use stable 20-row pagination. A username is required for both people before starting a follow relationship; no email search, numeric profile fallback, directory, or recommendations are provided. My profile links to `/people/{username}`. Renaming preserves relationships through user IDs; old username links stop resolving.

Signed-in visitors see a minimal private profile header and Request to follow/Pending controls. Private bio, counts, and activity stay hidden until approval. Public accounts allow public activity immediately to signed-in viewers; new follows accept immediately. Existing pending requests remain pending when privacy changes. Accepted followers retain access when an account becomes private. Shared labels use explicit display names or usernames, while the owner's existing greeting may still use registration name.

Permitted profiles show bio, Terminal Green, accepted follower/following counts, visible habit counts, a paginated permitted habit list, and filtered profile analytics. Private habits never contribute to another person's counts or calculations. Own profile views include private habits and explain that others see a subset. `/people/{username}/habits/{habit}` is read-only, shows permitted metadata/current effective setup and yearly activity, and omits pending setup, notes/reasons, checkpoint labels, journal/mood, and editor links. Archived/ended history remains available; trash is excluded.

Following is directional. Approve/Reject are available to the recipient; Cancel request to the requester. Unfollow and Remove follower require named confirmation dialogs with Cancel/Escape and focus restoration; successful removal moves focus to the page heading. Stale operations give feedback and reload current relationship state. Removal is not a block and does not hide public activity on a public account. Settings explain the effect of turning off privacy and that historical activity follows current visibility. All new habits still default private.

## Phase 7 usability corrections

Desktop navigation can wrap and the header can grow when text is enlarged; links and account controls must remain reachable. Narrow screens keep the existing mobile menu. Heatmap cell borders use a stronger neutral outline while preserving the same performance bands, labels and scoring. Both journal and activity editors restore their original history position when browser Back/Forward navigation is cancelled with a dirty draft.
