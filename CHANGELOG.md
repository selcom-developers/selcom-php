# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

First version, 0.1.0, not yet published.

### Changed

- Webhook verification follows selcom-spec v0.2.1: `order_id` and `transid` must be signed, signed names and values may not contain `&`, number handling is bounded, and replay entries are kept until max(now, signed time) + window.
- `Psr18Transport` requires a per-profile client factory, or `clientManagesTimeouts: true` for a ready client; payment requests use a fresh connection; the failed-send cause is a short summary.
- Request classes accept an int, a digit string or an integral float for amounts and refuse anything else.
- Secret parameters are marked `#[\SensitiveParameter]`; secrets are held outside the objects that use them; the API key shows its last 4 characters.
- Packaging: no `version` field, `export-ignore` rules, `suggest` and `authors`, CI actions pinned to commit SHAs, `composer audit` and a vectors-match-spec step.

### Added

- Configuration with an explicit environment (no default), credentials with the secret and PIN kept out of dumps, per-profile timeouts, and an optional second credential set for checkout.
- A pure request signer (flattening, value text, `Signed-Fields`, query string or JSON body) with a pluggable digest signer and HS256.
- A replaceable `Transport` interface and a PSR-18 default using `php-http/discovery`, with `NOT_SENT` / `UNKNOWN_OUTCOME` failure classification. Payments and writes are never retried.
- A client with typed request and result objects for the 16 v1 operations: checkout, utility payments, wallet cash-in and vendor balance.
- The five typed statuses (`SUCCESS`, `PENDING`, `AMBIGUOUS`, `FAILED`, `NOT_FOUND`) with the raw HTTP status and body.
- A webhook verifier with a configurable window and replay store, and payload parsing with strict amount parsing.
- A redaction helper for logs.
- Tests that run every case, reject, response scenario and webhook sample of selcom-spec v0.1.0 (copy in `tests/vectors`, recorded in `SPEC_VERSION`, refreshed by `scripts/refresh-spec.sh`), plus an opt-in sandbox test.
- GitHub Actions CI on PHP 8.2, 8.3, 8.4 and 8.5: PHPStan (max level, strict rules), tests and a package build.
