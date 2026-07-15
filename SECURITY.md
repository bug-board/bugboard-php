# Security Policy

## Supported versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Report them privately via
[GitHub Security Advisories](https://github.com/bug-board/bugboard-php/security/advisories/new)
for this repository. Include as much detail as you can: affected version, a proof of concept
or reproduction steps, and the impact you foresee.

You should receive an acknowledgement within 72 hours. We'll keep you informed as we triage,
fix, and disclose — and we'll credit you in the advisory unless you prefer otherwise.

## Key-handling guidance for SDK users

- A **signing secret** (`bb_sec_…`) belongs on servers only — read it from the environment
  (`BUGBOARD_SIGNING_SECRET`), never hardcode it, never commit it. The SDK only ever uses it to
  compute request signatures; it is never transmitted or logged.
- Revoke and rotate keys instantly from **Settings → API Keys** if one leaks — keys are named
  and individually revocable, so rotating one never breaks the others.
- For sensitive payloads, enable **payload encryption** (`encryptionPublicKey`) so request
  bodies are opaque in transit — at proxies, CDNs, and in access logs.
