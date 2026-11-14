# ADR-0016: Relative registration verification signatures

Status: accepted for the user-authorized application-level verification fix, 2026-11-14. Supersedes only ADR-0015's absolute-signature and package-owned route-registration choices.

## Evidence and decision

The public HTTPS verification-notice request redirected to an HTTP login URL. Local reproduction proves an unexpired absolute HTTPS signature fails when the backend request uses HTTP and forwarded metadata is untrusted. The same path/query signed with `absolute: false` validates with Laravel's corresponding relative mode. See [the investigation](../EMAIL_VERIFICATION_INVESTIGATION.md).

Generate registration verification with the existing canonical credential URL generator using `absolute: false`, then prefix the signed relative path/query with validated `CredentialUrls::origin()`. Production delivery still requires HTTPS `APP_URL`; no inbound Host, forwarded host, prefix, scheme or port contributes to the mail link. Native `signed:relative` protects the complete path/query (including expiry) using the unchanged application key. No query parameters are ignored and no absolute/relative fallback is added.

Relative signatures intentionally do not authenticate scheme/hostname/port. The existing exact-host middleware continues to admit only the canonical `APP_URL` hostname outside local/testing environments; subdomains and forwarded-host overrides remain rejected. External TLS enforcement remains an ingress responsibility. This change handles HTTP on the internal hop without treating an untrusted forwarded header as proof of HTTPS. Existing local/testing hostname behavior is unchanged.

## Route registration

Installed Fortify hardcodes `signed` and has no per-verification-route signature-mode setting. Use its supported `Fortify::ignoreRoutes()` in the application's provider registration phase, before providers boot. Declare the application's currently enabled routes in `routes/fortify.php`, loaded through the provider's cache-aware `loadRoutesFrom` and Fortify's existing domain/prefix/middleware configuration.

Retain native Fortify controllers, form requests, responses, actions, names, methods, paths, auth guards, view/feature conditions and limiters. The sole route behavior change is `signed:relative` on `verification.verify`. Do not duplicate/replace a previously registered route, mutate middleware after registration, replace the global `signed` alias, or edit vendor code. A parity test compares the application declarations with the installed package routes, permitting only this one middleware difference, and compiles all routes to reject duplicate names. Fortify upgrades or enabling additional features require maintaining this explicit route file; the parity test fails when it diverges.

## Safeguards and deployment

Expiry remains 60 minutes by default. Fortify still checks the authenticated account ID/current email hash; the model retains its locked identity/credential/deletion checks, idempotent timestamp and event behavior. Verification/resend share their existing account limiter, resend requires CSRF, and verified middleware still protects the application and Livewire updates. Mail safety, sessions, password confirmation, account deletion and managed recovery are unchanged.

Password-reset generation and signed email-change generation/validation remain unchanged. There is no migration, bulk verification, key rotation, new controller, signature bypass or trusted-proxy relaxation. Registration verification no longer requires provider support to discover ingress addresses; other proxy concerns retain their documented requirements.

Refresh route/config/view caches and serving processes during release. Existing absolute registration links cannot pass the new validator; owners request a fresh message from the existing retry page. Rollback likewise requires fresh links. Do not add a compatibility bypass. Confirm one synthetic account through the real HTTPS ingress after deployment and retain invalid/expired-link rejection. Local regression evidence does not claim that deployment has occurred.
