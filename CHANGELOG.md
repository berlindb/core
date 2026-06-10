# Changelog

Notable changes to BerlinDB are documented here.

## 3.1.0 - Unreleased

- Adds first-class `Relationship` objects (`belongs_to`/`has_many`), wired through
  `Column` and `Schema`, with a Query-level relationship API (#193).
- Adds relationship-filtered queries — `'in'` (subquery) and `'join'`
  (EXISTS / NOT EXISTS) strategies in both directions, recursive nested AND/OR
  subgroups, and an opt-in LEFT OUTER JOIN for `belongs_to`.
- Adds relationship cache priming via `with` for `belongs_to` and `has_many`, and
  opt-in enforced FOREIGN KEY DDL; fails closed on malformed or unresolvable specs.
- Adds opt-in typed `CAST` in comparisons (e.g. `AS SIGNED` / `DATETIME`),
  sanitized at the boundary and fail-closed on invalid casts.
- Adds relationship-declaration validation (#206): `Schema::get_validation_errors()`
  checks each declaration's local side (own shape, local columns, accessor
  uniqueness, unsupported composite, a named-but-missing remote query class);
  `Query::get_relationship_errors()` checks the remote side on demand (the class is a
  sibling `Query`, the referenced remote columns exist); and relationship
  declarations dropped by `Column::sanitize_relationships()` are now logged with
  stable reason codes (`relationship_invalid_query_class`, `relationship_invalid_type`, …).
- Adds a per-column save-time `intercept()` hook and a `Generator` trait; UUID
  generation moves into `intercept()` (#194).
- Improves cache-key coherence: per-group `last_changed` salting, id-pointer
  lookups routed through the salt, and stale `cache_key` slots invalidated on
  update (#203).
- Adds `$engine`, `$row_format`, and `$auto_increment` table properties with
  `engine()`, `auto_increment()`, and `get_create_sql()`; prevents
  auto-reinstallation after `uninstall()` via a tombstone and an `$auto_install`
  flag.
- Makes every Kern class config-constructable from an array — no subclass required
  (`new Query( $definition )`) — through a normalized `Boot` lifecycle
  (`sunrise → configure → init → consume_args → sunset`) split across the
  `Boot`, `Lifecycle`, and `Configuration` traits.
- `Query` accepts a definition or query vars via one constructor argument
  (discriminated by a schema signature); structural query vars are canonicalized
  before the cache key.
- Adds opt-out strict configuration: construction keys outside the declared config
  surface (`get_config_callbacks()`) are dropped and logged.
- Reserves the construction-machinery properties (`get_reserved_vars()`, each trait
  declaring its own) and excludes them from the config merge, so configuration can
  no longer clobber internal state — most visibly, a diagnostic logged by a config
  sanitizer during `validate_args()` now survives construction.
- Isolates query-var parsers from each other's clauses and fails closed on
  unresolvable or misdeclared columns; consolidates shared parser helpers onto the
  base.
- Reduces WordPress coupling: reimplements `wp_validate_boolean()`, `absint()`, and
  (filter-free) `sanitize_key()` in the `Sanitizer` trait and (filter-free)
  `wp_parse_args()` as `Base::parse_args()`, and uses native PHP CSPRNG for UUIDs
  and random integers instead of `wp_rand()`.
- Removes the internal `Parser::caller()` indirection in favor of direct,
  type-checked calls.

### 3.1.0 Upgrade Notes

- The `Query::sunrise()` construction hook (3.0.0) is renamed to `Query::init()`,
  which now runs after `configure()` and before `consume_args()`; `sunrise()` still
  exists but now runs *before* configuration. Rename any override that derived state
  from the query's configuration.
- The `parse_args()` construction hook (`Boot`/`Query`, 3.0.0) is renamed to
  `consume_args()`; `parse_args()` is now a `wp_parse_args()`-style array helper.
  Rename any override of the leftover-args hook to `consume_args()`.
- Configuration is strict by default — keys outside the declared config surface
  (`get_config_callbacks()`) are dropped and logged. Override `is_strict_config()`
  to opt out (as `Row` does for its
  dynamic columns).
- Several `Parser`/`Column`/`Query` methods introduced in 3.0.0 are now `protected`
  rather than public.
- Parsers now fail closed (return no rows) on unresolvable or misdeclared columns,
  and no longer bleed clauses across parser types.
- Malformed relationship declarations are still dropped (fail-closed), but now log a
  non-fatal warning identifying the column and the reason. Call
  `Query::get_relationship_errors()` to validate the remote side of the surviving
  relationships on demand.

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
- Ships as the 300th commit from the 3.0.0 release branch.

### 3.0.0. Upgrade Notes

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
