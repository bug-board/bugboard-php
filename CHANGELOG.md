# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-07-20

### Added

- Reports are now discarded **before** they reach the network while the server is dropping them.
  Previously every report still cost a round trip: the server accepts a report it has decided to
  discard and answers `200`, which the SDK must not retry, so an app over its allowance kept
  sending full volume for nothing. The client now closes a gate on the first drop and reopens it
  when the drop is expected to have cleared, letting a single probe request through to find out.
- The drop response's `reason` is read and sets how long suppression lasts — `quota` until the
  next midnight UTC (when the daily allowance refills), `paused` and `archived` for 30 minutes,
  since those clear whenever someone changes them in the dashboard. A server that sends only the
  legacy `quota_exceeded` flag is treated as a quota drop, and an unfamiliar `reason` from a newer
  server takes the short window rather than assuming a day.
- `QuotaStore`, an optional backing store for that suppression. PHP-FPM builds a fresh process per
  request, so without one the gate re-opens on every request and a busy site keeps sending — the
  store is what makes suppression real there. **The Laravel and Symfony integrations wire it to the
  application cache automatically**; standalone users can pass `Psr16QuotaStore` (or their own
  implementation) to `ClientBuilder::create()`. Without a store the gate still works for the life of
  the process, which is all a CLI command, a queue worker or an Octane process needs.
- `psr/simple-cache` is suggested (not required) for `Psr16QuotaStore`; the core SDK gains no new
  dependency.

### Changed

- `Transport::__construct()` and `Client::__construct()` take an optional trailing `QuotaGate`.
  Both default to null and existing call sites keep working — the transport builds its own gate when
  none is passed.
- The warning logged when the server drops a report now names the cause (allowance exhausted,
  project paused, project archived) and says when reporting resumes. It previously said "monthly
  quota" unconditionally, which no longer matches the daily allowance window.

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

[unreleased]: https://github.com/bug-board/bugboard-php/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/bug-board/bugboard-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/bug-board/bugboard-php/releases/tag/v1.0.0
