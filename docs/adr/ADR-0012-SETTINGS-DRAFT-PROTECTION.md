# ADR-0012: Settings draft protection

Status: Accepted by the account-management Phase 5 implementation request, 2026-10-29. Verification is recorded in [the report](../SETTINGS_UNSAVED_CHANGES_REPORT.md).

Compare current Settings values with loaded/successfully saved values. Do not use touched flags as proof of unsaved work. Profile success returns only its normalized six allowlisted settings values; validation/network failures retain the previous baseline. Disable/inert profile editing during a save so an acknowledgment cannot silently clear later user edits. Existing separate-tab last-writer semantics are unchanged; no optimistic-locking schema is introduced.

Extend the existing numeric history-position guard. Native internal links and Livewire navigation use an accessible native dialog with safe Stay focus, Escape cancellation and focus return. Restore cancelled Back/Forward before Livewire consumes it. Native unload prompts cover browser-owned exits where supported. Fetch Settings history entries afresh to reauthorize and avoid resurrecting cached discarded drafts.

Security forms remain native CSRF-protected POST/DELETE submissions. Compare their editable inputs against empty defaults without storing credential baselines. Submitting one form warns before discarding another dirty form; no additional draft warning blocks explicit logout or confirmed account deletion. Server authentication errors/redirects take precedence over all draft state. Cancelled credential dialogs and validation responses clear secrets according to existing behavior. No password or token is added to Livewire state, history, URL, logs, local/session storage or confirmation text.

No autosave, persistent draft, router, dependency or migration is added. The guard does not detect revocation until the next server request and does not promise crash/OS-termination recovery. Production backup/restore prerequisites from ADR-0011 remain outstanding.
