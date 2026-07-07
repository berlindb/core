# Relationships And Foreign Keys Reference

How BerlinDB models relationships between tables, resolves and filters by them, and
(optionally) emits real `FOREIGN KEY` DDL. Relationships are **unenforced by default** -
integrity lives at the application layer, matching WordPress's avoidance of real foreign
keys - but the shape is FOREIGN KEY-compatible so it can emit DDL and be introspected back.

## Declaring

A relationship has a `type`, a remote `query` class, and a pair of key columns (`local`
side on this table, `remote`/`references` side on the other).

- **`belongs_to`** - this row *holds the foreign key*, pointing at one remote row (the
  owning / "many" side).
- **`has_many`** - this row *is pointed at* by many remote rows (the parent / "one" side).

**Single-column** relationships use the per-column `relationships` shorthand (the local
column is the declaring column; `column` is the remote key):

```php
// On 'order_id': this row belongs_to one Order.
'relationships' => array(
    array(
        'query'  => \Acme\Database\Queries\Order::class,
        'column' => 'id',
        'type'   => 'belongs_to',
        'name'   => 'order',   // optional accessor
    ),
),
```

**Composite (multi-column)** relationships key on more than one column. The per-column
shorthand is single-column only, so declare them on the **Schema** via `get_relationships()`
with equal-length `columns` (local) and `references` (remote) arrays:

```php
public function get_relationships() {
    return array(
        new \BerlinDB\Database\Kern\Relationship( array(
            'name'       => 'tenant',
            'query'      => \Acme\Database\Queries\Tenant::class,
            'type'       => 'belongs_to',
            'columns'    => array( 'region_id', 'account_id' ),  // local
            'references' => array( 'region_id', 'account_id' ),  // remote
        ) ),
    );
}
```

`columns` and `references` must be the same length (validated). Order is semantic - the
`i`th local column pairs with the `i`th remote column.

## Fetching

- `get_related( $item, $accessor )` (on the `Query`, not the `Row`) resolves a relationship
  for one shaped item. `belongs_to` returns a `Row` or `null`; `has_many` returns an array
  of `Row`s (the **full** child set - pagination is a direct `query()`, not an accessor).
  A composite key must have **all** parts present, or it resolves to no relation.
- `with => array( 'order', 'items' )` on a `query()` **primes** the named accessors' caches
  in bulk (quiet by default). Single-column keys are batch-warmed in one query; **composite
  keys are not batch-primed** - `get_related()` still resolves them per item (each cached,
  just not bulk-warmed). Composite-key priming is tracked in #229.

## Filtering

Filter this query's rows by a relationship with the `relation` query var, in two strategies:

- **`'in'`** (default for single-column) - materializes the remote subquery into a
  `{fk}__in` filter. Single-column belongs_to only, and the local FK column must declare
  `'in' => true`.
- **`'join'`** - a real INNER JOIN (default) or correlated `EXISTS`. Supports `'join' =>
  'left'`, `'exists' => false` (anti-join / `NOT EXISTS`), operator conditions
  (`array( 'compare' => '>', 'value' => 100 )`), and nested AND/OR `where` groups.

```php
'relation' => array(
    'name'     => 'order',
    'where'    => array( 'status' => 'complete' ),
    'strategy' => 'join',
),
```

**Composite keys default to `join`** (the `in` materialize strategy cannot express a
multi-column key). The JOIN `ON` clause and the `EXISTS` correlation match on **every** key
column, AND-ed together: `ON ( a.fk1 = b.ref1 AND a.fk2 = b.ref2 )`. A malformed or
unresolvable `relation` clause **fails closed** (matches no rows, never all).

## Enforced foreign keys (opt-in DDL)

By default a relationship emits **no** DDL. Set `enforce => true` on a `belongs_to` to emit
a real `FOREIGN KEY` constraint, with optional `on_delete` / `on_update` / `constraint`
name. Composite relationships emit a multi-column key:
`FOREIGN KEY ( a, b ) REFERENCES remote ( x, y )`.

Emission is **deferred** by default - a `FOREIGN KEY` inside `CREATE TABLE` would reference
a table that may not exist yet:

- **Deferred** (default): install all tables, then call `$table->add_foreign_keys()` once
  every referenced table exists. Each key is added independently; an unresolved remote table
  is reported and fails that key only.
- **Inline**: set the referencing table's `$foreign_keys = 'inline'` to emit the FK inside
  `CREATE TABLE` when you control install order.

Only enable enforcement if you control the storage engine - real foreign keys need InnoDB
(MyISAM silently ignores them).

## Limitations / follow-ups

- **Composite-key priming** - not yet batch-primed; `with` on a composite relationship
  resolves per item (#229).
- **`in` strategy** - single-column only; composite uses `join`.
- **Two-hop / m2m** - a relationship `where` names remote *columns*, not nested
  *relationships*; many-to-many via a pivot table is not modeled (tracked under #211 Lever D).
