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
  filters, `__in`/`__not_in`, `criteria` boolean trees, **expression operands**
  (column/function/list/range/tuple comparisons), **aggregates** (`aggregate`
  container, grouped + `having`), JSON, casts, and the three-cache model
  (query/by-id/secondary) with `last_changed` salt invalidation.
- `references/debugging.md`: silent save failures, table upgrade issues,
  wrong primary key usage, malformed query vars, and logging.
- `references/migration-2-to-3.md`: updating older BerlinDB 2.x patterns to
  BerlinDB 3.x.
- `references/verification.md`: local checks, CI expectations, package/archive
  checks, and pre-release workflow.
- `references/extending.md`: construction lifecycle hooks (which to override and
  which to leave alone), config args + strict mode, validating relationship
  declarations, authoring presets (the Meta recipe + `MetaStore` contract),
  query-var normalization (early all-vars vs later var-local), and the
  custom-parser API.

## Canonical Object Model

A typical integration defines:

- a `Schema` subclass with `$columns` and `$indexes`
- a `Table` subclass with `$name`, `$version`, and `$schema`
- a `Row` subclass with public properties for returned data
- a `Query` subclass with `$table_schema`, `$item_shape`, `$table_name`, names,
  cache group, and optional prefix/alias values

In BerlinDB 3.x, `Table` and `Query` instantiate Schema objects from class names
or accept Schema instances. Prefer `::class` constants for schema and row shape.

**Sanitize vs validate (when overriding):** `sanitize_*` methods (Sanitizer
trait) make a value structurally/SQL-safe — identifiers and config args — and may
reject it. `validate_*` methods (Column) conform a stored value to the column's
declared type via its `$validate` callback. Override the one matching your
concern, and follow the same split when naming your own helpers.

## Relationships (3.1.0, #193)

Declare a relationship in a column's `relationships` array in the Schema. Each
entry needs `query` (FQCN of the remote `Query` class), `column` (the column on
the *remote* side), and `type` (`belongs_to` | `has_many`); `name` (the accessor)
is optional, derived from the local column otherwise.

Which side declares it, and what `column` means, differs by `type`:

- **`belongs_to`** — declare on the local column that *holds the foreign key*
  (the owning / "many" side). `column` is the remote key it points at (usually
  `'id'`).

```php
// On 'order_id' — this row points at one Order.
'relationships' => array(
    array(
        'query'  => \EDD\Database\Queries\Order::class,
        'column' => 'id',           // remote key referenced
        'type'   => 'belongs_to',
        'name'   => 'order',        // optional accessor
    ),
),
```

- **`has_many`** — declare on the local key the children *reference back* (the
  "one" / parent side, usually `'id'`). `column` is the remote foreign-key column
  pointing here.

```php
// On 'id' — many Order_Items point back at this Order.
'relationships' => array(
    array(
        'query'  => \EDD\Database\Queries\Order_Item::class,
        'column' => 'order_id',     // remote FK pointing here
        'type'   => 'has_many',
        'name'   => 'items',        // optional accessor
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
- **Compare to another column** with an opt-in operand in place of `value`:
  `array( 'compare' => '>', 'value' => array( 'operand' => 'column', 'name' => 'min_total' ) )`
  compares two columns (`total > min_total`). Works on the scalar comparison
  operators (`=`/`!=`/`<`/`<=`/`>`/`>=`) in relationship `where` and `compare_query`
  clauses; an unknown column or an unsupported operator **fails closed**. The
  operand may also be an allow-listed SQL function wrapping recursive arguments —
  `array( 'operand' => 'func', 'name' => 'ABS', 'args' => array( array( 'operand' => 'column', 'name' => 'balance' ) ) )`
  for `... = ABS(balance)`. Only listed functions (`LOWER`/`UPPER`/`LENGTH`/`ABS`/
  `DATE`/`YEAR`/`MONTH`/`DAYOFMONTH`/`DAYOFYEAR`/`DAYOFWEEK`/`HOUR`/`MINUTE`/
  `SECOND`) with a matching arity are allowed; no raw SQL. A column argument is
  type-checked against the function (`YEAR()` wants a date column, `ABS()` a
  numeric one) and fails closed on a mismatch. The
  same operand spec works on the **left** side via the clause `key` (position
  selects the side) — `array( 'key' => array( 'operand' => 'func', 'name' =>
  'LOWER', 'args' => array( array( 'operand' => 'column', 'name' => 'name' ) ) ), 'value' => 'jane' )`
  for `LOWER(name) = 'jane'`; a bare scalar on the other side is prepared with the
  function's return type. An operand `key` also works with a bare value on `IN`/
  `BETWEEN`/`LIKE`/`IS NULL` (e.g. `'key' => array( 'operand' => 'func', 'name' =>
  'YEAR', 'args' => array( array( 'operand' => 'column', 'name' => 'created' ) ) ), 'compare' => 'IN', 'value' => array( 2023, 2024 )`).
  Comparing two operands (a *structured* value) stays limited to the scalar
  operators (`=`/`!=`/`<`/`<=`/`>`/`>=`).

Notes:

- **Validate your declarations.** `$schema->get_validation_errors()` catches the
  local side (own shape, unknown local column, duplicate accessor, unsupported
  composite, a named-but-missing remote query class); `$query->get_relationship_errors()`
  catches the remote side on demand (the class exists but isn't a `Query`, an unknown
  remote column) — call it from your tests or dev tooling. Malformed shorthand
  declarations are dropped (fail-closed) and logged with a stable code (e.g.
  `relationship_invalid_type`).
- Runtime relationship features (`get_related`, priming, `relation` filtering)
  are **single-column only** for now — one local key referencing one remote key.
- Relationship filters **fail closed**: a misconfigured or empty-matching filter
  returns no rows, never all rows.
- Enforced foreign-key metadata (`enforce`, `on_delete`, `on_update`,
  `constraint`) is **declarable but not yet emitted as DDL** — relationships are
  enforced at the application layer (WordPress avoids real foreign keys).

## Custom Meta Tables (3.1.0, #204)

Use the Meta preset when a table needs WordPress-style key/value metadata in a
custom sibling table instead of a core `{type}meta` table. The recipe is explicit:
the primary schema declares the `has_many meta` relationship, and thin query/table
stubs opt into the generated meta table shape.

```php
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use BerlinDB\Database\Presets\Meta\Query as MetaQuery;
use BerlinDB\Database\Presets\Meta\Table as MetaTable;

class Order_Schema extends Schema {
    public $columns = array(
        array(
            'name'          => 'id',
            'type'          => 'bigint',
            'length'        => '20',
            'unsigned'      => true,
            'primary'       => true,
            'extra'         => 'auto_increment',
            'relationships' => array(
                array(
                    'query'  => Order_Meta_Query::class,
                    'column' => 'order_id',
                    'type'   => 'has_many',
                    'name'   => 'meta',
                ),
            ),
        ),
    );

    public $indexes = array(
        array(
            'type'    => 'primary',
            'columns' => array( 'id' ),
        ),
    );
}

class Order_Query extends Query {
    protected $prefix       = 'acme';
    protected $table_name   = 'orders';
    protected $table_schema = Order_Schema::class;
    protected $item_name    = 'order';
    protected $cache_group  = 'orders';
}

class Order_Table extends Table {
    protected $prefix  = 'acme';
    protected $name    = 'orders';
    protected $version = '1.0.0';
    protected $schema  = Order_Schema::class;
}

class Order_Meta_Query extends MetaQuery {
    protected $primary_query_class = Order_Query::class;
}

class Order_Meta_Table extends MetaTable {
    protected $meta_query_class = Order_Meta_Query::class;
}
```

Instantiate/install the primary table and meta table alongside each other. The
meta query derives its table name (`order_meta`), foreign key (`order_id`), EAV
columns (`meta_id`, `meta_key`, `meta_value`), and `belongs_to order`
relationship from the primary.

`Presets\Meta\Query` implements `Interfaces\MetaStore`. A primary query's
protected `add_item_meta()`, `get_item_meta()`, `update_item_meta()`,
`delete_item_meta()`, and delete-item purge path route to the custom store when
the relationship named `meta` resolves to a `MetaStore`; otherwise they fall back
to the legacy WordPress metadata API. Expose those protected helpers from your
own Query subclass if your plugin needs public item-meta methods.

`delete_item_meta( $id, $key, $value, $delete_all )` mirrors `delete_metadata()`:
with `$delete_all = true` the item ID is ignored and the key is purged across
**every** object (a fleet-wide cleanup), on both the store and legacy paths. A
meta key is still required.

Bulk meta works too: extra non-column keys passed to `add_item()` /
`update_item()` save through the store (non-empty values update, empty values
delete), and `delete_item()` purges the item's meta. When a store is declared,
the WordPress `register_meta()` key gate is intentionally skipped — the `meta`
relationship IS the registration.

`meta_query` / `meta_key` / `meta_value` work too: for a store-backed object they
are translated into relationship `EXISTS` filters against the sibling table (so the
existing WordPress-shaped query surface keeps working), with `compare`, `type`
casts, and `relation` AND/OR all honored. A negative `compare_key` (e.g. `NOT LIKE`
on the key) becomes a `NOT EXISTS` over the key — "the object has no meta row whose
key matches" — except when paired with a value, which still fails closed.
WordPress-core objects keep the bespoke meta parser unchanged.

You can also order by a meta value: `orderby => 'meta_value'` (or `'meta_value_num'`
for numeric) sorts by the simple/first clause's value, and a named `meta_query`
clause key — `meta_query => array( 'price' => array( 'key' => 'price' ) )` with
`orderby => 'price'` — sorts by that clause. Store-backed objects order via a
correlated subquery (the oldest `meta_id` wins a multi-valued key). The array
`orderby` shape works for both, so several meta keys (and real columns) can be mixed
in one `orderby`. Named clauses both filter and sort, exactly like positional ones.

Current limitations:

- A negative `compare_key` *combined with a value* fails closed for store-backed
  objects (key absence is object-level, a value match is row-level — one EXISTS
  clause can't express both). A negative `compare_key` on its own translates.
- Runtime relationship features remain single-column only, so Meta preset
  primaries should use a single primary key column.
- A string/UUID primary key is supported end-to-end (3.1.0): `add_item()` with a
  supplied key returns that key, `get_item()`/`update_item()`/`delete_item()` and
  query result-shaping address rows by it, and the generated meta foreign key
  mirrors the primary key's storage *shape* so store-backed item meta keys off the
  string/UUID too. (`add_item()` therefore returns `int|string`.) The legacy
  WordPress metadata fallback — for a primary with no meta store — still requires
  an integer ID, since `{type}meta` tables are int-keyed.

## High-Risk Gotchas

- Nullable columns use `'allow_null' => true`, not `'null' => true`.
- PHP arrays are not automatically JSON-encoded before writes.
- JSON strings are not automatically decoded after reads unless a cast or Row
  constructor handles them.
- `add_item()` returns the new item ID on success — the auto-increment value
  (`int`), or the supplied string/UUID primary key — or `false` on failure.
- `update_item()` returns `true` when a table-column update is written — or
  (3.1.0) when a meta-only update saves bulk meta successfully — but returns
  `false` when nothing needs saving after diffing and capability reduction
  (including a meta-only update to an identical value).
- `delete_item()` returns `true` or `false`.
- `delete_items()` (3.1.0) deletes a *set*: a single ID, a list of IDs, or a
  query-var filter (same vocabulary as `query()`, e.g.
  `delete_items( array( 'status__in' => array( 'spam' ) ) )`). It resolves the
  matching IDs and loops `delete_item()` (so hooks/cache/meta cleanup all fire), and
  returns the number deleted or `false`. An empty input or a filter with no `WHERE`
  deletes nothing — it never means "delete everything".
- `update_items( $target, $data )` (3.1.0) is the write sibling: it writes `$data`
  to a *set* named the same three ways (single ID / list of IDs / query-var
  filter), looping `update_item()`. Returns the number updated or `false`. Empty
  `$data`, an empty input, or a filter with no `WHERE` updates nothing.
- `add_items( $rows )` (3.1.0) is the create sibling: it inserts a *list of data
  arrays*, one new item each, looping `add_item()`. It takes no set selector (the
  rows do not exist yet), and returns the new IDs in input order — each slot the new
  ID, or `false` where that insert failed — rather than a count. An empty input
  inserts nothing and returns `array()`.
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
