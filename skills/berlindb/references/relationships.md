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

## Conditioned relationships (scoped / polymorphic)

A relationship may carry a `condition` - a fixed `column => scalar` equality map that scopes
the **remote** rows. This models a **polymorphic** child table (one table shared across parent
types via an `object_id` + `object_type` pair) as a single relationship instead of hand-coded
SQL:

```php
// On 'id': this Order has_many notes WHERE the note's object_type = 'order'.
'relationships' => array(
    array(
        'query'     => \Acme\Database\Queries\Note::class,
        'column'    => 'object_id',
        'type'      => 'has_many',
        'name'      => 'notes',
        'condition' => array( 'object_type' => 'order' ),
    ),
),
```

The condition is appended to **every** SQL form of the relationship - `get_related()`
traversal, the correlated `EXISTS` filter, and nested `EXISTS` - as
`AND {remote}.object_type = 'order'`, so only the matching rows are traversed or matched.

- **Application-layer only.** A `FOREIGN KEY` cannot encode a discriminator, so a conditioned
  relationship is never enforced (an `enforce => true` is dropped).
- **Defaults to the `join` / `EXISTS` filter strategy** and rejects an explicit
  `strategy => 'in'`. `get_related()` traversal scopes via `{column}__in`, so a condition
  column must declare `in => true` on the **remote** schema; the `join` / `EXISTS` path
  renders raw SQL and needs no flag.
- **Fails closed** on an unknown condition column (never widens). A condition on a
  `many_to_many` is **rejected** (`get_validation_errors()`).
- Equality-with-scalar values in 3.1.0; richer predicates (operators / `IN`) and
  `many_to_many` support are follow-ups (#246).

## Fetching

- `get_related( $item, $accessor )` (on the `Query`, not the `Row`) resolves a relationship
  for one shaped item. `belongs_to` returns a `Row` or `null`; `has_many` returns an array
  of `Row`s (the **full** child set - pagination is a direct `query()`, not an accessor).
  A composite key must have **all** parts present, or it resolves to no relation.
- `with => array( 'order', 'items' )` on a `query()` **primes** the named accessors' caches
  in bulk (quiet by default), so the per-item `get_related()` lookups then fire no SQL. Both
  single-column AND composite keys are batch-warmed (#229): one bulk read - a portable
  OR-of-ANDs match `( a = ? AND b = ? ) OR ( ... )` for a composite key - seeds the result
  caches the accessor reads, including empty tuples (a no-match / childless lookup is a hit
  too). A single-column `belongs_to` to a non-primary column is primed the same way. Standard
  `last_changed` invalidation applies, so a later write is reflected.

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

### Nested (multi-hop) filtering

A clause's `relation` key can itself hold **another relationship clause** (an array, not the
`AND`/`OR` boolean string), filtering two or more hops out. Each hop becomes a correlated
`EXISTS`; the chain nests arbitrarily deep (`order -> customer -> region -> country`):

```php
$orders->query( array(
    'relation' => array(
        'name'     => 'customer',
        'where'    => array( 'status' => 'active' ), // condition at this hop (optional)
        'relation' => array(
            'name'  => 'region',
            'relation' => array(
                'name'  => 'country',
                'where' => array( 'code' => 'EUR' ), // condition at the far hop
            ),
        ),
    ),
) );
// EXISTS ( SELECT 1 FROM customer ... WHERE ... AND EXISTS ( SELECT 1 FROM region ...
//   WHERE ... AND EXISTS ( SELECT 1 FROM country ... WHERE country.code = 'EUR' ) ) )
```

- A nested `relation` **forces the `join` strategy** (a correlated subquery, never a real
  JOIN - a JOIN cannot correlate inside a subquery); an explicit `strategy => 'in'` with a
  nested `relation` fails closed.
- `where` applies **at every hop**; `exists => false` negates the hop it sits on (`NOT
  EXISTS`), so you can express "orders whose customer's region is **not** EU".
- Nested chains are **`belongs_to` / `has_many` only**; a `many_to_many` hop inside a chain
  fails closed (its pivot indirection is a separate builder).
- Any unknown relationship, column, or unresolvable remote at **any** depth fails the whole
  clause closed (`1 = 0`), never widening.

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

- **`in` strategy** - single-column only; composite uses `join`.
- **Nested chains are `belongs_to` / `has_many`** - a `many_to_many` hop *inside* a nested
  `relation` chain fails closed (a top-level m2m filter is fine; only mid-chain is excluded).
- **Conditioned relationships** (#246) - equality-with-scalar values only; a `condition`
  column must be `in => true` on the remote for `get_related()` traversal; `many_to_many`
  conditions are rejected. Richer predicates (operators / `IN`) and m2m are follow-ups.
