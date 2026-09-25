# Changelog

## 1.1.2 - 2026-09-18

- Route lifecycle and bootstrap requests through the resilient public API path.
- Publish the public GitHub README and use the package name `Anti Spam - Forum
  Fortress` for the SMF Customization catalog.

## 1.1.1 - 2026-09-16

- Support SMF 2.1's PHP 7.1+ runtime range while preserving the shared API
  routing behavior.
- Fix SMF staff-bypass checks and load native moderation APIs in scheduled
  actions.
- Keep API keys on the original HTTPS host and use SMF's canonical forum URL
  instead of the request Host header for bootstrap identity.

## 1.1.0 - 2026-09-11

- Replace health and endpoint-catalogue routing with deterministic GeoDNS
  fallback and keep regional routing locked unless global fallback is enabled.
- Limit standard-plan heartbeat attempts to hourly while retaining ten-minute
  Pro/MultiMod check-ins.

## 1.0.8 - 2026-09-07

- First release licensed as free and open-source software under
  `GPL-2.0-or-later`.
- Add the complete GPLv2 text, project notice and same-licence contribution
  terms while keeping the hosted Forum Fortress service separate.
