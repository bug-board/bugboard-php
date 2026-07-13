# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-13

### Added

- `Client` with the 16 severity×priority reporting methods
  (`critical`, `criticalLow`, … `minorHigh`), generated from the severity/priority tables with
  full `@method` docblocks for IDE autocompletion.
- HMAC-SHA256 request signing for secret keys (the secret never travels on the wire) and bearer
  auth for publishable keys.
- Buffered fire-and-forget delivery: bounded buffer (drop-newest overflow policy), graceful
  flush via `register_shutdown_function`, and an explicit `flush()`.
- Resilience on any PSR-18 client: per-request timeout (Guzzle auto-configured), retries with
  exponential backoff + jitter for 429/5xx/network failures, `Retry-After` support, and
  quota-drop awareness.
- Safety: `beforeSend` scrubbing hook, sampling (`sampleRate`), client-side clamping to API
  limits, and a secret-redacting debug logger.
- Optional payload encryption via libsodium sealed boxes (`sodium_crypto_box_seal`).
- Laravel integration: auto-discovered service provider, `BugBoard` facade, publishable config,
  and delivery on app terminate (after the response is sent).
- Symfony integration: `BugBoardBundle` with a typed config tree and an autowirable `Client`
  service.
- `baseUrl` option (config key `base_url`) for pointing the SDK at a different BugBoard origin
  (`http://localhost:8000`, trailing slash optional) — the SDK appends `/api/v1/tasks` itself.
  A base URL that isn't absolute warns and falls back to `https://bugboard.dev`. Read the
  resolved URL via `Config::endpoint()`.

[unreleased]: https://github.com/bug-board/bugboard-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/bug-board/bugboard-php/releases/tag/v1.0.0
