# Contributing to the BugBoard PHP SDK

Thanks for helping improve the SDK! This guide covers everything you need to get a change from
idea to merged pull request.

## Before you start

- **Bugs & features** — open an [issue](https://github.com/bug-board/bugboard-php/issues) first
  so we can discuss the approach before you invest time in code.
- **Wire-contract changes** — the request format, auth schemes, retry policy, and the 16-method
  reporting surface are defined by the [API reference](https://bugboard.dev/docs/api-reference).
  Changes to that contract must be discussed in an issue first — an SDK doesn't diverge from the
  API on its own.
- **Security issues** — never open a public issue; see [SECURITY.md](SECURITY.md).

## Development setup

You need PHP 8.2+ and Composer.

```bash
git clone https://github.com/bug-board/bugboard-php.git
cd bugboard-php
composer install
```

Day-to-day commands:

```bash
composer test     # PHPUnit test suite
composer analyse  # PHPStan static analysis (level 8)
composer lint     # Laravel Pint style check (no changes)
composer format   # Laravel Pint, fix style
composer check    # lint + analyse + test — exactly what CI runs
```

Please make sure `composer check` passes before opening a pull request.

## Project principles

Keep these invariants in mind; they are what make the SDK safe to embed in other people's apps:

1. **Never throw into the host app.** Reporting methods and `flush()` catch everything; failures
   go to the debug logger. (`BadMethodCallException` for a typo'd method name is the one
   deliberate exception — that's a programming error, not a runtime failure.)
2. **Framework-agnostic core.** `src/Laravel` and `src/Symfony` are thin adapters; everything
   else must work with nothing but PSR interfaces.
3. **Never log key material.** The logger redacts secrets; keep it that way.
4. **The 16 reporting methods are generated** from the severity/priority tables in `Client` —
   update the `@method` docblocks (Client **and** the Laravel facade) if the surface changes.
5. **Clamp before sending.** Title ≤ 255 chars, tags ≤ 50 chars, description well under the
   64 KB server cap.

## Making changes

1. Fork the repo and create a branch from `main`:
   `git checkout -b fix/retry-after-parsing`.
2. Make your change, **with tests**. Every behavior fix or feature needs coverage in `tests/`.
3. Update `README.md` if you changed anything user-facing, and add an entry under
   `## [Unreleased]` in `CHANGELOG.md`.
4. Run `composer check`.
5. Open a pull request against `main` and fill in the template.

## Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/) **without scopes**:

```text
feat: honor Retry-After on 429 responses
fix: treat blank env values as absent credentials
docs: add Symfony bundle setup guide
test: cover buffer overflow accounting
chore: bump dev dependencies
ci: run tests on PHP 8.5
```

- Subject in the imperative mood, lower-case, no trailing period.
- No scopes (`feat:` — never `feat(laravel):`), no emoji.
- Keep each commit to one logical change; we prefer a clean history over squash-everything.

## Pull request expectations

- CI must be green (Pint, PHPStan, PHPUnit on PHP 8.2/8.3/8.4).
- New behavior is documented and tested.
- Breaking changes are called out explicitly in the PR description.
- One approving review is required before merge.

## Releases

Maintainers cut releases. Versioning follows [SemVer](https://semver.org/); the changelog
follows [Keep a Changelog](https://keepachangelog.com/).

## Code of conduct

Participation in this project is governed by our
[Code of Conduct](CODE_OF_CONDUCT.md). Be kind.
