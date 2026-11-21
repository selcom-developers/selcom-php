# Contributing

Thanks for helping. This is a community client for the Selcom API gateway, and it is **not affiliated with Selcom Paytech Ltd.**

## Ground rules

- **The spec is the source of truth.** Signing, result codes, retries and webhooks are defined in [selcom-spec](https://github.com/selcom-developers/selcom-spec). If this client and the spec disagree, the client is wrong, or the spec needs a fix that every client then picks up: open the spec change first.
- **Clean room.** Write your own code. Do not copy from Selcom's official clients or from other Selcom libraries. Reading them to understand behaviour is fine.
- **No secrets and no real data.** Tests use made-up values only: phones `2557000000xx`, IDs like `ORD-1001` and `TXN-...`, keys like `test-key-0001`, or the sample values printed in Selcom's public documentation. Never commit credentials, customer data or details of any merchant's integration.
- **No automatic retry of payments or writes.** This is a hard rule.

## Development

Requires PHP 8.2 or later and Composer.

```
composer install
composer test        # PHPUnit
composer analyse     # PHPStan, max level
```

Both must pass on PHP 8.2 to 8.5 (CI runs the matrix). Keep the public API small and stable: the classes listed in the README's "Public API" section follow semantic versioning.

## Spec vectors

The tests run the shared vectors from a copy in `tests/vectors`. To move to another spec tag:

```
scripts/refresh-spec.sh v0.2.0
```

This rewrites `tests/vectors` and `SPEC_VERSION`. Never edit the vectors by hand.

## Pull requests

- One concern per pull request. Add or update tests, and add a line under "Unreleased" in `CHANGELOG.md`.
- Describe the behaviour change and, where the language makes a spec rule awkward, how you handled it.
- Releases, tags and publishing are done by the maintainers.

By contributing you agree that your work is licensed under the Apache License 2.0.
