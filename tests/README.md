# BerlinDB Core — PHPUnit Tests

Integration tests for the BerlinDB Core library. These tests require a real
WordPress installation and a MySQL database; they are not pure unit tests.

## Running Tests

The easiest way to run the suite is via the Docker runner at the repository root:

```bash
bin/run-tests.sh
```

See `bin/run-tests.sh --help` for available options (PHP version, WP version,
MariaDB version, PHPUnit filter passthrough).

For manual local runs (requires PHP 7.4+, MySQL, SVN, and Composer):

```bash
composer install
bin/install-wp-tests.sh berlindb_tests root '' localhost latest
vendor/bin/phpunit
```

## Test Classes

| File | Requires DB | What it covers |
|------|:-----------:|----------------|
| `ColumnTest.php` | No | Column defaults, type detection, `special_args()`, `get_create_string()`, validation callbacks |
| `SchemaTest.php` | No | Column object conversion, `get_create_table_string()`, `clear()`, `add_item()` |
| `TableTest.php` | Yes | Table lifecycle (`create`, `exists`, `drop`), `count()`, upgrade flow, `column_exists()`, versioning |
| `QueryCrudTest.php` | Yes | `add_item()`, `get_item()`, `get_item_by()`, `update_item()`, `delete_item()`, `copy_item()` |
| `QueryFilterTest.php` | Yes | `query()` filtering by status/priority/id, `__in`/`__not_in`, search, orderby, pagination, count mode |

## Fixture Classes

The test fixtures live in `tests/Fixtures/` and provide minimal, concrete
implementations of the abstract BerlinDB classes:

| Class | Extends | Purpose |
|-------|---------|---------|
| `TestSchema` | `Schema` | 7-column schema covering all common column flags |
| `TestTable` | `Table` | `berlindb_test_widgets` table with an upgrade callback for testing the upgrade flow |
| `TestRow` | `Row` | Typed row wrapper matching the test schema |
| `TestQuery` | `Query` | Wires `TestSchema` and `TestRow` together |

## Notes

- The test table is named `berlindb_test_widgets` and is isolated from any real WordPress tables.
- `wp_set_current_user(1)` is called in the database test classes because `Query::reduce_item()` checks `current_user_can()` before saving column data. Without a logged-in user, `add_item()` silently drops all columns.
- `wp_cache_flush()` is called between tests to prevent stale object cache from masking CRUD changes.
