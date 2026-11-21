# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-21

First release.

### Added

- Configuration with an explicit environment (no default), credentials held outside the objects that use them (the API key prints its last 4 characters), per-profile timeouts, and an optional second credential set for checkout.
- A pure request signer (flattening, value text, `Signed-Fields`, query string or JSON body) with a pluggable digest signer and HS256.
- A replaceable `Transport` interface and a PSR-18 default using `php-http/discovery` for the factories and a Guzzle client per timeout profile, or your own client (a per-profile closure, or a ready client with `clientManagesTimeouts: true`). Payments and writes use a fresh connection and are never retried. `NOT_SENT` / `UNKNOWN_OUTCOME` classification from cURL request-byte counters, error numbers and connect-phase messages.
- A client with typed request and result objects for the v1 operations: checkout, utility payments, wallet cash-in and vendor balance. Amounts accept an int, a digit string or an integral float.
- The typed statuses (`SUCCESS`, `PENDING`, `AMBIGUOUS`, `FAILED`, `NOT_FOUND`) with the raw HTTP status and body.
- A webhook verifier following selcom-spec v0.2.1 (`order_id` and `transid` signed, no `&` in signed names or values, bounded number handling, replay entries kept until max(now, signed time) + window), a replay store interface, `ReplayStoreException` for store failures (answer 5xx), and payload parsing with strict amount parsing.
- A redaction helper for logs.
- Secret parameters marked `#[\SensitiveParameter]`; masked output for `var_dump`, `print_r`, `var_export` and array casts.
- Tests that run every case, reject, response scenario and webhook sample of selcom-spec v0.2.1 (copy in `tests/vectors`, recorded in `SPEC_VERSION`, refreshed by `scripts/refresh-spec.sh`, checked by `scripts/check-spec.sh`), plus an opt-in sandbox test.
- GitHub Actions CI on PHP 8.2, 8.3, 8.4 and 8.5: `composer validate --strict`, `composer audit`, vectors match the spec, PHPStan (max level, strict rules), tests and a package build. Actions are pinned to commit SHAs.

[Unreleased]: https://github.com/selcom-developers/selcom-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/selcom-developers/selcom-php/releases/tag/v0.1.0
