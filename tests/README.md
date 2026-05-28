# BerlinDB Core — PHPUnit Tests

Integration tests for the BerlinDB Core library. Most test groups require a real
WordPress installation and a MySQL database; a subset are pure unit tests.

## Running Tests

The easiest way to run the suite is via the Docker runner at the repository root:

```bash
bin/run-tests.sh
```

See `bin/run-tests.sh --help` for available options (PHP version, WP version,
MariaDB version, PHPUnit filter passthrough).

For manual local runs (requires PHP 8.1+, MySQL, SVN, and Composer):

```bash
composer install
bin/install-wp-tests.sh berlindb_tests root '' localhost latest
vendor/bin/phpunit
```

## Test Classes

Tests are organized under `tests/Database/` by layer.

| Group | Files | DB? | What it covers |
| --- | --- | :-: | --- |
| `Adapters/` | `NullConnectionTest`, `WpdbTest` | No | NullConnection inert return values; Wpdb adapter delegation to `$wpdb` |
| `Column/` | `ColumnTest`, `ColumnFromMysqlTest`, `JsonColumnTest` | No | Column defaults, type detection, `special_args()`, `get_create_string()`, JSON support |
| `Index/` | `IndexTest` | No | Index defaults and `get_create_string()` |
| `Operators/` | `OperatorsTest` | No | All SQL operator classes (`In`, `NotIn`, `Between`, `Like`, `NotLike`, etc.) |
| `Parsers/` | `ByParserTest`, `CompareParserTest`, `DateParserTest`, `InParserTest`, `MetaParserTest`, `NotInParserTest`, `SearchParserTest` | No | SQL clause generation for each query var parser |
| `Query/` | `QueryCacheTest`, `QueryCrudTest`, `QueryFilterTest`, `QueryGetResultsTest`, `QueryGettersTest`, `QueryHooksTest`, `QueryParserTest`, `QuerySchemaLogTest`, `QueryTransitionTest`, `ReduceItemTest` | Mostly | CRUD, filtering, caching, hooks, `get_results()`, schema log, status transitions |
| `Row/` | `RowTest` | No | Row object construction and property access |
| `Schema/` | `SchemaTest`, `SchemaFromTableTest` | `SchemaFromTableTest` only | Column/index management, `get_create_table_string()`, MySQL introspection |
| `Table/` | `TableTest`, `TableSchemaLogTest` | Yes | Table lifecycle, upgrades, `column_exists()`, schema log integration |
| `Traits/` | `BaseSanitizationTest`, `BootTest`, `EnvironmentTest`, `ErrorTest`, `LifecycleTest`, `LogTest`, `MagicTest`, `ParserTest` | `EnvironmentTest` only | Per-trait unit coverage; `EnvironmentTest` also validates the adapter cache and switch-blog safety |

## Fixture Classes

The test fixtures live in `tests/Fixtures/` and provide minimal, concrete
implementations of the abstract BerlinDB classes:

| Class | Extends | Purpose |
| --- | --- | --- |
| `TestSchema` | `Schema` | 7-column schema covering all common column flags |
| `TestTable` | `Table` | `berlindb_test_widgets` table with an upgrade callback for testing the upgrade flow |
| `TestRow` | `Row` | Typed row wrapper matching the test schema |
| `TestQuery` | `Query` | Wires `TestSchema` and `TestRow` together |

## Notes

- The test table is named `berlindb_test_widgets` and is isolated from any real
  WordPress tables.
- `wp_set_current_user(1)` is called in database test classes because
  `Query::reduce_item()` checks `current_user_can()` before saving column data.
  Without a logged-in user, `add_item()` silently drops all columns.
- `wp_cache_flush()` is called between tests to prevent stale object cache from
  masking CRUD changes.
