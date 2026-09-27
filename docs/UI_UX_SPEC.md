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
