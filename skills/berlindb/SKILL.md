---
name: berlindb
description: >-
  Use when implementing, reviewing, debugging, or migrating WordPress custom
  database tables that use berlindb/core 3.x. Covers Schema, Table, Row, Query,
  Connection adapters, parser/operator query vars, table upgrades, common save
  failures, and release-safe verification commands.
---

# BerlinDB

Use this skill for BerlinDB 3.x work in WordPress plugins and libraries.

> **Contributing to BerlinDB core itself** (editing `src/`, `tests/`, configs)?
> This skill is consumer-facing — it covers *using* the library. The
> contributor workflow, coding-style rules, and verification gate live in the
> repository's `CLAUDE.md`. Read that first when working on the core.

## First Moves

1. Check the installed BerlinDB version and current branch before editing.
2. Prefer local source, tests, README, and CHANGELOG over memory.
3. Use current namespaces:
   - `BerlinDB\Database\Kern\Schema`
   - `BerlinDB\Database\Kern\Table`
   - `BerlinDB\Database\Kern\Query`
   - `BerlinDB\Database\Kern\Row`
   - `BerlinDB\Database\Interfaces\Connection`
   - `BerlinDB\Database\Adapters\Wpdb`
   - `BerlinDB\Database\Adapters\NullConnection`
4. Do not use old 2.x paths such as `BerlinDB\Database\Schema`,
   `BerlinDB\Database\Table`, `BerlinDB\Database\Query`, or
   `BerlinDB\Database\Row` unless the project explicitly aliases them.
5. Do not invent APIs. If unsure, search `src/` and `tests/`.

## Reference Map

Read only the reference needed for the task:

- `references/schema-table.md`: schemas, columns, indexes, tables, installs,
  upgrades, table versions, and nullability.
- `references/query-row.md`: query classes, row shapes, CRUD return values,
  filters, `__in`/`__not_in`, JSON, casts, and the three-cache model
  (query/by-id/secondary) with `last_changed` salt invalidation.
- `references/debugging.md`: silent save failures, table upgrade issues,
  wrong primary key usage, malformed query vars, and logging.
- `references/migration-2-to-3.md`: updating older BerlinDB 2.x patterns to
  BerlinDB 3.x.
- `references/verification.md`: local checks, CI expectations, package/archive
  checks, and pre-release workflow.

## Canonical Object Model

A typical integration defines:

- a `Schema` subclass with `$columns` and `$indexes`
- a `Table` subclass with `$name`, `$version`, and `$schema`
- a `Row` subclass with public properties for returned data
- a `Query` subclass with `$table_schema`, `$item_shape`, `$table_name`, names,
  cache group, and optional prefix/alias values

In BerlinDB 3.x, `Table` and `Query` instantiate Schema objects from class names
or accept Schema instances. Prefer `::class` constants for schema and row shape.

## Relationships (3.1.0, #193)

Declare a relationship on the column that holds the key, via a `relationships`
array in the Schema. Each entry needs `query` (FQCN of the remote `Query`
class), `column` (the key on the remote side), and `type`
(`belongs_to` | `has_many`); `name` (the accessor) is optional and derived from
the local column otherwise.

```php
// On the column holding the foreign key (e.g. 'order_id'):
'relationships' => array(
    array(
        'query'  => \EDD\Database\Queries\Order::class,
        'column' => 'id',          // remote key referenced
        'type'   => 'belongs_to',
        'name'   => 'order',       // optional accessor
    ),
),
```

- **Prime caches** with `with` (quiet by default — pass accessor names to warm):
  `$q->query( array( 'status' => 'active', 'with' => array( 'order' ) ) );`
- **Resolve related rows** with `get_related()` (on the `Query`, not the `Row`):
  `$order = $q->get_related( $item, 'order' );` — `belongs_to` returns a `Row`
  or `null`; `has_many` returns an array of `Row`s (the FULL child set —
  pagination is a direct `query()`, not a relationship accessor).
- **Filter rows by a relationship** with the `relation` query var; two strategies:
  - `'in'` (default) — resolves a subquery into a `{fk}__in` filter:
    `'relation' => array( 'name' => 'order', 'where' => array( 'status' => 'complete' ), 'strategy' => 'in' )`
  - `'join'` — a real JOIN/EXISTS, supporting INNER (default) or
    `'join' => 'left'`, `'exists' => false` (anti-join), operator conditions
    (`array( 'compare' => '>', 'value' => 100 )`), and nested AND/OR `where`
    groups:
    `'relation' => array( 'name' => 'order', 'where' => array( 'status' => 'complete' ), 'strategy' => 'join' )`
- **Type a comparison** with an opt-in `cast` on a `where` condition — never
  applied by default (a SQL `CAST` defeats index use):
  - `'cast' => 'SIGNED'` — explicit target (`SIGNED`/`UNSIGNED`/`DECIMAL`/
    `DATE`/`DATETIME`/`TIME`/`CHAR`); useful when comparing a value stored as
    text numerically: `array( 'compare' => '>', 'value' => 100, 'cast' => 'SIGNED' )`.
  - `'cast' => true` — derive the target from the remote column's own type.
  - A present-but-invalid `cast` (e.g. a typo) **fails closed** (no rows), not a
    silent lexical compare.

Notes:

- Runtime relationship features (`get_related`, priming, `relation` filtering)
  are **single-column only** for now — one local key referencing one remote key.
- Relationship filters **fail closed**: a misconfigured or empty-matching filter
  returns no rows, never all rows.
- Enforced foreign-key metadata (`enforce`, `on_delete`, `on_update`,
  `constraint`) is **declarable but not yet emitted as DDL** — relationships are
  enforced at the application layer (WordPress avoids real foreign keys).

## High-Risk Gotchas

- Nullable columns use `'allow_null' => true`, not `'null' => true`.
- PHP arrays are not automatically JSON-encoded before writes.
- JSON strings are not automatically decoded after reads unless a cast or Row
  constructor handles them.
- `add_item()` returns the new database insert ID (`int`) or `false` on
  failure.
- `update_item()` returns `true` when a table-column update is written, but can
  also return `false` when nothing in table columns needs saving after diffing
  and capability reduction.
- `delete_item()` returns `true` or `false`.
- `update_item()` and `delete_item()` expect the primary-key value, not a slug
  or other business key.
- `number` can intentionally limit queries that also use `__in` or `__not_in`.
- Table `$version` values are schema/database versions for that table, not the
  BerlinDB package version. Must be a string — `strict_types=1` and
  `version_compare()` both require it.
- `no_found_rows` defaults to `true`. In that mode, `get_found_items()` returns
  only the current-page item count, not the total matching rows — not useful for
  pagination. Pass `'no_found_rows' => false` to run the separate `COUNT(*)`
  query and get the true total from `get_found_items()`.
- In this repository's database tests, call `wp_set_current_user( 1 )` before
  CRUD writes. `Query::reduce_item()` runs `current_user_can()` checks before
  saving column data, and the default test fixtures otherwise strip columns and
  make inserts/updates look broken.
- For multisite global tables (shared across all sites), set `$global = true` on
  the Table subclass. Per-site tables omit this property.

## Verification Defaults

For BerlinDB core changes, run:

```bash
composer validate --strict --no-check-publish
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
bin/run-tests.sh -p 8.1 -w 6.7 -- --group default
bin/run-tests.sh -p 8.2 -w 6.7 -- --group default
```

For plugin integrations, run the plugin's own PHPUnit/static-analysis suite and
at least one integration path that creates/upgrades the table and performs
insert, update, query, and delete operations.
