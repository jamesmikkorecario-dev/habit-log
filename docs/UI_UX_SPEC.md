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
