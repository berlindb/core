# Contributing

Thanks for helping improve BerlinDB.

BerlinDB is a WordPress-focused database library, so the best contributions tend
to be small, well-tested, and careful about backwards compatibility.

## Local Setup

Install PHP dependencies:

```bash
composer install
```

The integration tests run inside Docker against WordPress and MariaDB. The
default helper chooses sensible versions:

```bash
bin/run-tests.sh -- --group default
```

To test a specific PHP or WordPress version:

```bash
bin/run-tests.sh -p 8.1 -w 6.7 -- --group default
```

## Quality Checks

Run these before opening a pull request:

```bash
composer validate --strict --no-check-publish
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
bin/run-tests.sh -- --group default
```

## Pull Requests

- Keep changes focused.
- Include tests for bug fixes and new behavior.
- Update docs when public APIs, requirements, or workflows change.
- Avoid unrelated formatting or refactors in the same PR.
- Explain any backwards compatibility tradeoffs.

## Coding Standards

BerlinDB follows WordPress-oriented PHP conventions and is checked with PHPCS. If
PHPCS reports a problem, prefer adjusting the code over suppressing the rule
unless the suppression is clearly justified.

## Compatibility

BerlinDB 3.0.0 targets PHP 8.1 or newer and current supported WordPress
versions. Compatibility changes should be explicit in the pull request
description.
