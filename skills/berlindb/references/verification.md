# Verification Reference

## BerlinDB Core

For changes inside `berlindb/core`, run:

```bash
composer validate --strict --no-check-publish
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
bin/run-tests.sh -p 8.1 -w 6.7 -- --group default
bin/run-tests.sh -p 8.2 -w 6.7 -- --group default
```

If the local sandbox blocks PHPStan's parallel worker socket, use:

```bash
vendor/bin/phpstan analyse --debug --memory-limit=1G
```

## Focused Tests

Prefer focused tests while iterating:

```bash
bin/run-tests.sh -p 8.2 -w 6.7 -- --filter QueryCrudTest
bin/run-tests.sh -p 8.2 -w 6.7 -- --filter TableTest
bin/run-tests.sh -p 8.2 -w 6.7 -- --filter ParserTest
```

Then run the full default suite before declaring release readiness.

## Package Archive

Composer archives should include runtime package files and public package docs,
but not tests, CI, dev tooling, or vendored dependencies.

```bash
composer archive --dir=/tmp --file=berlindb-core --format=zip
unzip -Z1 /tmp/berlindb-core.zip
```

Expected inclusions:

- `CHANGELOG.md`
- `LICENSE`
- `README.md`
- `autoloader.php`
- `composer.json`
- `src/**`

Expected exclusions:

- `.github/`
- `bin/`
- `docs/`
- `tests/`
- `vendor/`
- `composer.lock`
- tool configs such as PHPCS, PHPStan, PHPUnit, and markdownlint
- `skill/`

## Pre-Release Workflow

If the GitHub workflow is present, run the manual `Pre-Release` workflow with
the target version before tagging. It checks static analysis, tests, changelog
presence, and archive contents.
