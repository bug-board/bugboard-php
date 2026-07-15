# 1.0.0 (2026-07-15)


### Bug Fixes

* remove unnecessary whitespace in log message for local report logging ([1e22a80](https://github.com/bug-board/bugboard-php/commit/1e22a807269ae8e6d163ea123f9ea926e6fbac40))
* update CI workflow to trigger on dev branch instead of main ([066f2e1](https://github.com/bug-board/bugboard-php/commit/066f2e1fbebc643f35d41399e86302a2dbf6afbc))


### Features

* add buffered client with shutdown flush and the 16 reporting methods ([79e6bc8](https://github.com/bug-board/bugboard-php/commit/79e6bc898537190bc0dbb62b9680dba58c2f97a9))
* add call site capture for reporting, including file name and line number ([30d0a1a](https://github.com/bug-board/bugboard-php/commit/30d0a1a971fcff5e6922313a8897bd271d972f4a))
* add config object, exceptions, payload normalization, and debug logger ([2e688a6](https://github.com/bug-board/bugboard-php/commit/2e688a6bf33b1f8f3650a673e895f1955123848f))
* add hideApiResponse and captureLocation options to configuration and update related logic ([57a0bba](https://github.com/bug-board/bugboard-php/commit/57a0bba58c9db12b986eadbacf47ee05bec724fa))
* add HMAC request signer and sealed-box encrypter ([07071ac](https://github.com/bug-board/bugboard-php/commit/07071ac28f1779d615cd204d021e619ba97df737))
* add Laravel service provider, facade, and publishable config ([05cea10](https://github.com/bug-board/bugboard-php/commit/05cea10071cdfcaa5f8afb9ab8c34d92d86f71d8))
* add logLocally option for local report logging in dry-run mode ([96b9fcb](https://github.com/bug-board/bugboard-php/commit/96b9fcb6b85e41d68ef2132a3c713298fb1b4b3b))
* add PSR-18 transport with retries, backoff, and Retry-After support ([a209f41](https://github.com/bug-board/bugboard-php/commit/a209f41a33351bbb6ad515a46c32749ad2f4a3e0))
* add Symfony bundle with autowired client service ([2308591](https://github.com/bug-board/bugboard-php/commit/2308591ff48b54badea997157742fd30e89d74c8))
* refactor endpoint configuration to use baseUrl and update related logic ([dfdc4a1](https://github.com/bug-board/bugboard-php/commit/dfdc4a17973848bfb10fb662da2119b5a1a5ed9e))

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
