# Changelog

Notable changes to BerlinDB are documented here.

## 3.0.0 - 2026-06-01

- Modernizes the project structure around adapters, interfaces, kern objects,
  operators, parsers, and traits.
- Adds a dedicated database connection interface with `wpdb` and null connection adapters.
- Adds parser and operator classes for reusable SQL clause generation.
- Adds schema object injection and MySQL introspection factories for columns,
  indexes, schemas, and tables.
- Adds automated data typing via column casts, including JSON column support.
- Adds lifecycle state management, structured logging, magic property helpers,
  and environment/database connection helpers.
- Adds `cache_results` query support and improves query cache behavior.
- Adds parser-driven `ORDER BY` support for date, meta, and `__in` query vars.
- Improves table copy, duplicate, rename, repair, and upgrade behavior.
- Expands schema, table, query, parser, operator, lifecycle, environment,
  logging, and error handling coverage.
- Adds PHPStan, PHPCS, and PHPUnit GitHub Actions.
- Declares PHP 8.1 as the minimum supported PHP version.
- Improves Composer package metadata and distribution contents.

### Upgrade Notes

- PHP 8.1 or newer is required.
- The object model has been reorganized around `Adapters`, `Interfaces`, `Kern`,
  `Operators`, `Parsers`, and `Traits` namespaces.
- Database access now flows through the `Connection` interface and adapter classes.
- Query parsing is more schema-aware, especially for column casts,
  parser-specific clauses, and supported `__in`/`__not_in` query vars.
- Projects extending internals should review renamed traits, parser/operator
  extraction, and table/query lifecycle behavior before upgrading.

## 2.0.2 - 2025-10-23

- Fixes the Composer autoloader for the current repository structure.
- Fixes `Query::add_item()` return typing.
- Fixes date query SQL generation to use the table alias/name correctly.
- Fixes query item-shape state so one query cannot affect another query.
- Fixes `parse_groupby()` argument handling.
- Updates Composer dependencies.
- Adds temporary dynamic-property compatibility attributes.
- Changes `Table::exists()` to search only the current database via information
  schema, then reverts the broader table-exists change from PR #166.

## 2.0.1 - 2022-03-10

- Changes the project license from GPL to MIT.
- Adds a `_clone()` shim for compatibility with downstream Sugar Calendar usage.
- Fixes the `$order` argument typo in query handling.
- Audits `shape_item_id()` usage.
- Refreshes inline documentation.

## 2.0.0 - 2021-07-12

- Improves update behavior so `Query::update_item()` only updates relevant changed columns.
- Removes unnecessary `stripslashes()` handling from item validation.
- Replaces hardcoded `id` references with the configured primary column name.
- Fixes `get_meta_table_name()` return behavior.
- Improves meta handling during item updates.
- Improves `parse_groupby()` alias handling.
- Adds `Table::columns()`.
- Updates table uninstall behavior to clean version information when the table no longer exists.
- Tightens query and table property typing and array handling.

## 1.1.0 - 2021-02-22

- Adds custom meta-query support by abstracting the `WP_Meta_Query` dependency
  into BerlinDB's own meta parser.
- Adds `query_var_default_value` and `is_query_var_default()` support.
- Refactors date query handling to better align with meta and compare queries.
- Adds table `clone()` and `copy()` helpers.
- Adds `Table::index_exists()`.
- Improves `get_item()` and related query helpers.
- Adds `Column::sanitize_default()`.
- Improves null-value validation for nullable columns.
- Uses GMT-safe date and time helpers.
- Adds `start_of_week` support for date clauses.
- Adds `Query::get_meta_type()` and improves meta table name handling.
- Improves upgrade filters, metadata deletion, cache cleaning, and inline documentation.

## 1.0.0 - Initial Development

- Introduces the original Base, Schema, Row, Query, Table, Column, Date, and Compare classes.
- Adds the first README and project naming.
- Adds early decimal and datetime validation helpers.
