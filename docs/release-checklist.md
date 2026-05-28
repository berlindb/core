# Release Checklist

Use this checklist before tagging a BerlinDB release.

## Before Tagging

- Confirm the target milestone has no required open issues.
- Confirm pull requests intended for the release are merged or intentionally punted.
- Run the manual `Pre-Release` GitHub Actions workflow for the target version.
- Run Composer validation:

```bash
composer validate --strict --no-check-publish
```

- Run static analysis:

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

- Run coding standards:

```bash
vendor/bin/phpcs
```

- Run PHPUnit on the supported PHP versions:

```bash
bin/run-tests.sh -p 8.1 -w 6.7 -- --group default
bin/run-tests.sh -p 8.2 -w 6.7 -- --group default
```

- Inspect the Composer archive:

```bash
composer archive --dir=/private/tmp --file=berlindb-core-package-check --format=zip
zipinfo -1 /private/tmp/berlindb-core-package-check.zip
```

- Update `CHANGELOG.md`.
- Draft GitHub release notes.
- Tag the release.
- Merge the release branch into the default branch.
- Confirm Packagist sees the new tag.
