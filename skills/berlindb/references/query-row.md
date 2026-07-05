# Query And Row Reference

## Row

Extend `BerlinDB\Database\Kern\Row` and define public properties for returned
data.

```php
use BerlinDB\Database\Kern\Row;

final class Widget extends Row {
	public $id = 0;
	public $name = '';
	public $status = 'active';
	public $date_created = '';
}
```

Rows receive database values. If an integration needs domain-specific casting,
do it consistently in the Row constructor or through column casts where
available.

## Query

Extend `BerlinDB\Database\Kern\Query`.

```php
use BerlinDB\Database\Kern\Query;

final class WidgetQuery extends Query {
	protected $prefix = 'acme';
	protected $table_name = 'widgets';
	protected $table_alias = 'w';
	protected $table_schema = WidgetSchema::class;
	protected $item_name = 'widget';
	protected $item_name_plural = 'widgets';
	protected $item_shape = Widget::class;
	protected $cache_group = 'acme-widgets';
}
```

Use `::class` constants for `$table_schema` and `$item_shape`. BerlinDB 3.x can
also accept Schema objects for schema configuration.

## CRUD Return Values

`add_item( array $data )`:

- returns the new item ID on success — the auto-increment value (`int`), or the
  supplied string/UUID primary key
- returns `false` on failure

```php
$id = $query->add_item( $data );
if ( false === $id ) {
	// insert failed
}
```

`update_item( $item_id, array $data )`:

- first argument is the primary key value
- returns `true` when a table-column update is written — or, for a meta-only
  update (extra non-column keys, no column changes), when the bulk meta saves
  successfully
- returns `false` on failure, **and also** when nothing needs saving after
  diffing — including a meta-only update to an identical value — so `false` means
  "no write occurred", not necessarily an error

```php
$updated = $query->update_item( $widget->id, $data );
// false here means either the update failed OR the incoming data was identical
// to what is already stored. Call get_item() before updating if you need to
// distinguish a real failure from a benign no-op.
if ( false === $updated ) {
	// no write occurred
}
```

`delete_item( $item_id )`:

- first argument is the primary key value (an `int`, or a string/UUID key)
- returns `true` or `false`

Do not pass a slug, UUID, or other business key to `update_item()` or
`delete_item()` unless that is the configured primary key.

## Query Vars

Common query vars:

```php
$items = $query->query(
	array(
		'status__in' => array( 'active', 'pending' ),
		'orderby'    => 'date_created',
		'order'      => 'DESC',
		'number'     => 20,
	)
);
```

Column-specific parser support depends on Schema flags:

- `searchable` enables search participation.
- `sortable` enables `orderby` for that column.
- `date_query` enables date query behavior.
- `in` and `not_in` enable `{column}__in` and `{column}__not_in`.

`number` limits result count even when using `__in` or `__not_in`. Do not remove
or bypass limits unless the caller explicitly needs all matching rows.

A per-column `orderby` direction may add `NULLS FIRST` / `NULLS LAST`, e.g.
`'orderby' => array( 'priority' => 'ASC NULLS LAST', 'name' => 'DESC' )`. MySQL has
no native syntax, so it is emulated with a leading `ISNULL( col )` sort key; a plain
direction (`'ASC'`/`'DESC'`) keeps MySQL's default null grouping (NULLs first under
`ASC`, last under `DESC`).

`no_found_rows` defaults to `true`, meaning no separate `COUNT(*)` query is run.
In that mode, `get_found_items()` returns only the count of items on the current
page — not the total number of matching rows. This is not useful for pagination.
Pass `'no_found_rows' => false` explicitly to get the true total:

```php
$items = $query->query(
    array(
        'number'        => 20,
        'offset'        => 0,
        'no_found_rows' => false,
    )
);
$total = $query->get_found_items();
```

`cache_results` defaults to `true`. Pass `'cache_results' => false` to bypass
both the result-list cache read and write for a single query — useful inside
write-path hooks where the cache may not yet reflect a just-committed insert.

## Index Hints

`index_hints` adds a MySQL/MariaDB index hint (`USE` / `FORCE` / `IGNORE INDEX`) to
the base table of a read query. It takes one spec, or a list of them:

```php
'index_hints' => array(
    'type'    => 'force',              // use | force | ignore
    'indexes' => array( 'idx_status' ), // declared index name(s), or 'primary'
    'for'     => 'join',               // optional: join | order by | group by
),
// -> FROM ...table a FORCE INDEX FOR JOIN (`idx_status`)
```

- Index names are validated against the schema's **declared indexes plus
  `PRIMARY`**; `primary` (any case) renders as the bare `PRIMARY` index name.
- A hint never changes which rows return, so it **fails open**: an unknown index
  name, an unknown `type`, or an illegal `USE` + `FORCE` combination is dropped and
  logged, and the query runs un-hinted. Don't rely on a hint as a filter.
- Multiple specs are **declarative, not ordered** — MySQL collects hints by type and
  scope. An omitted `for` applies to all scopes on MySQL but `FOR JOIN` on MariaDB,
  so set `for` explicitly if you need cross-engine certainty.
- Applies to the base table of the read path only — not relationship `JOIN` targets,
  and not the `delete_items()` / `update_items()` ID-resolution path.
- It is **excluded from the result-cache key** (a hint does not change which rows
  return), so a hinted and an unhinted query share one cache entry.
- **MySQL/MariaDB only.** Other engines hint differently (Postgres `pg_hint_plan`,
  SQLite `INDEXED BY`); BerlinDB renders the MySQL form.

## Negation

Negation lives in two layers. Pick by *what* you are negating — they generate
different SQL and are not interchangeable.

**Condition-level (one field / value)** — use the parser or operator the column
owns. This is almost always what "field is not X" means:

```php
'status__not_in' => array( 'publish' ),   // ... WHERE status NOT IN ('publish')
'compare_query'  => array(                 // ... WHERE status != 'publish'
    'key'     => 'status',
    'value'   => 'publish',
    'compare' => '!=',
),
```

(plus the `!=` / `NOT IN` / `NOT LIKE` / `NOT BETWEEN` / `NOT EXISTS` / `IS NOT NULL`
operators the parsers expose.)

**Group-level (a whole parser bucket, or a grouped boolean)** — use `criteria`
with `'not' => true`. The leaves of `criteria` are parser *buckets*, not
conditions, so this wraps the bucket's combined SQL in `NOT ( ... )`:

```php
'status'        => 'active',
'compare_query' => array( 'key' => 'priority', 'value' => 40, 'compare' => '>=' ),
'criteria'      => array(
    'relation' => 'OR',
    'not'      => true,
    'columns',   // the direct-column bucket (status = 'active')
    'compare',   // the compare_query bucket  (priority >= 40)
),
// -> WHERE NOT ( ( status = 'active' ) OR ( priority >= 40 ) )
```

Negate one bucket while keeping another positive by **nesting** a negated group:

```php
'criteria' => array(
    'relation' => 'AND',
    'columns',
    array( 'not' => true, 'compare' ),   // ( <columns> AND NOT ( <compare> ) )
),
```

### Three things to know about `criteria` NOT

1. **It negates the whole bucket.** The `columns` bucket holds *all* direct-column
   equality, so `NOT ( columns )` over `status='publish'` and `type='post'` is
   `NOT ( status='publish' AND type='post' )` — not `status!='publish' AND type='post'`.
   To negate just one column, route it through its own bucket (`compare_query`) or
   use `{column}__not_in`.
2. **SQL three-valued logic — NULL rows are excluded.** `NOT ( status = 'publish' )`
   does *not* return rows where `status IS NULL` (the comparison is `UNKNOWN`, and
   `NOT UNKNOWN` is still `UNKNOWN`, never `TRUE`). The same holds for `!=` and
   `NOT IN`. If you need NULL rows back, say so explicitly (e.g. an `IS NULL`
   condition combined in via its own bucket).
3. **JOIN-emitting buckets can't be negated (or OR-ed).** A bucket whose parser
   emits a `JOIN` (a relationship/meta `join` strategy) under `NOT` or `OR` fails
   closed — the query returns nothing (`1 = 0`) and logs a warning — because an
   `INNER JOIN` pre-filters rows before the boolean runs, so it cannot be inverted
   or widened at the criteria layer. Negate inside the parser instead (e.g. a
   `NOT EXISTS` relationship strategy).

`criteria` only takes `'not' => true` (a boolean), never `'not' => array( ... )`:
the tree composes parser *buckets*, and raw conditions belong in the query vars
that feed those buckets.

## Expression Operands (3.1.0, #211)

Beyond a bare `key` / `value` / `compare`, `compare_query` accepts **structured
operand specs** on either side of a comparison — a column reference, a function, a
prepared value, a list, a range, or a row-constructor tuple. Each is an array with
an `operand` marker; a bare scalar or numeric-keyed list is NOT an operand spec, so
existing queries are unaffected. All are opt-in, resolved against the query's own
schema, and **fail the clause closed** (match no rows, `1 = 0`) on anything
unresolvable — there is no raw-SQL passthrough.

**Column-to-column** — compare two columns of the same table:

```php
'compare_query' => array(
    'key'     => 'updated',
    'compare' => '>',
    'value'   => array( 'operand' => 'column', 'name' => 'created' ),
),
// -> WHERE `updated` > `created`
```

**Functions** — an allow-listed SQL function wraps an operand (they nest): `LOWER`,
`UPPER`, `LENGTH`, `ABS`, `DATE`, `YEAR`, `MONTH`, `DAYOFMONTH`, `DAYOFYEAR`,
`DAYOFWEEK`, `HOUR`, `MINUTE`, `SECOND`, `COALESCE`. Position (`key` vs `value`)
picks the side; a bare scalar on the other side is prepared with the function's
return type:

```php
'compare_query' => array(
    'key'     => array(
        'operand' => 'func',
        'name'    => 'LOWER',
        'args'    => array( array( 'operand' => 'column', 'name' => 'name' ) ),
    ),
    'compare' => '=',
    'value'   => 'acme',
),
// -> WHERE LOWER(`name`) = 'acme'
```

Most functions take a fixed number of arguments; `COALESCE` is **variadic** (two or
more) and returns the first non-`NULL` argument. It has no type of its own, so the
placeholder a bare scalar compares against is DERIVED from its arguments — the common
type when they agree (e.g. a `%d` column and an integer literal give `%d`), and a
string placeholder when they mix:

```php
'compare_query' => array(
    'key'     => array(
        'operand' => 'func',
        'name'    => 'COALESCE',
        'args'    => array(
            array( 'operand' => 'column', 'name' => 'display_name' ),
            array( 'operand' => 'column', 'name' => 'user_login' ),
            'guest',
        ),
    ),
    'compare' => '=',
    'value'   => 'acme',
),
// -> WHERE COALESCE(`display_name`, `user_login`, 'guest') = 'acme'
```

**Cast** — any *scalar* operand (column / value / function / nested cast) takes an
opt-in `'cast'` key that wraps it in `CAST( ... AS <type> )`, so an arbitrary
expression casts, not just a column:

```php
'compare_query' => array(
    'key'     => array(
        'operand' => 'func',
        'name'    => 'LOWER',
        'args'    => array( array( 'operand' => 'column', 'name' => 'name' ) ),
        'cast'    => 'CHAR',
    ),
    'compare' => '=',
    'value'   => 'acme',
),
// -> WHERE CAST(LOWER(`name`) AS CHAR) = 'acme'
```

A column also accepts `'cast' => true` (cast to its own declared type). The target
is validated against the safe subset (`BINARY`, `CHAR(n)`, `DATE`, `DATETIME`,
`TIME`, `SIGNED`, `UNSIGNED`, `DECIMAL(p,s)`); a requested-but-invalid target, a
`cast` on a non-scalar shape (list/range/tuple), or `cast => true` on a non-column
fails closed. A cast composes as a function argument and is validated by its target
category (a `DATE` cast is accepted by `YEAR()`). The compared scalar derives its
placeholder from the target: `SIGNED` → `%d`, everything else → `%s`.

**Lists (IN / NOT IN)** — members are operands, so a list can mix columns,
functions, and values, which a bare value list can't:

```php
'compare_query' => array(
    'key'     => 'status',
    'compare' => 'IN',
    'value'   => array( 'operand' => 'list', 'items' => array(
        array( 'operand' => 'column', 'name' => 'default_status' ),
        'active',
        'pending',
    ) ),
),
// -> WHERE `status` IN ( `default_status`, 'active', 'pending' )
```

(The plain `'value' => array( 'active', 'pending' )` bare list still works and is
simpler for scalars.)

**Ranges (BETWEEN)** — exactly two operand bounds (columns / functions / values):

```php
'value' => array( 'operand' => 'range', 'items' => array( 1, 100 ) ),
// -> ... BETWEEN 1 AND 100
```

**Tuples (row constructors)** — a multi-column value on either side, for row
equality or multi-column `IN`:

```php
'key'     => array( 'operand' => 'tuple', 'items' => array(
    array( 'operand' => 'column', 'name' => 'a' ),
    array( 'operand' => 'column', 'name' => 'b' ),
) ),
'compare' => 'IN',
'value'   => array( 'operand' => 'list', 'items' => array(
    array( 'operand' => 'tuple', 'items' => array( 1, 2 ) ),
    array( 'operand' => 'tuple', 'items' => array( 3, 4 ) ),
) ),
// -> WHERE ( `a`, `b` ) IN ( ( 1, 2 ), ( 3, 4 ) )
```

**Shape rules** — operands pair by shape AND *width*: a scalar to a scalar, an
`( a, b )` tuple to a same-width tuple or a list of same-width tuples. A width
mismatch (`( a, b ) = ( c, d, e )`), a value-shape operand (list/range) used as the
left subject, a tuple with `IS NULL`, an empty list, or a ragged list of tuples all
fail closed rather than emit malformed SQL. Only scalar comparison operators pair a
structured operand on both sides; `IN` pairs a list, `BETWEEN` a range. Relationship
`where` conditions accept the same operand specs (resolved against the remote table).

An `orderby` term may also be a `column` or `func` operand spec, to sort by an
expression: `'orderby' => array( 'operand' => 'func', 'name' => 'LENGTH', 'args' =>
array( array( 'operand' => 'column', 'name' => 'name' ) ) )` renders `ORDER BY
LENGTH( name )` (alone, or mixed into a numeric orderby list). An unresolvable or
non-scalar orderby spec is dropped (ordering never changes which rows match).

## Aggregates (3.1.0, #225)

The `aggregate` container computes one or more SQL aggregates over the matched (and
filtered) rows in one cached query, returning values instead of item rows:

```php
$totals = $query->query( array(
    'aggregate' => array(
        'revenue' => array( 'sum', 'amount' ),   // alias => array( function, column )
        'orders'  => array( 'count', '*' ),       // COUNT(*)
        'peak'    => array( 'max', 'created' ),
    ),
    'status'    => 'complete',                     // aggregates honor your filters
) );
// $totals = array( 'revenue' => '1234.50', 'orders' => 42, 'peak' => '2026-06-...' )
```

- Functions: `sum`, `avg`, `max`, `min`, `count`. The shorthand
  `array( 'sum' => 'amount' )` keys by the function; the named form
  `array( 'function' => 'sum', 'column' => 'amount' )` is equivalent.
- `count` folds in: `array( 'count', '*' )` = `COUNT(*)`, `array( 'count', 'col' )` =
  `COUNT(col)`, and the named form with `'distinct' => true` = `COUNT(DISTINCT col)`.
- An empty set is `null` per alias (not `0`) — except `COUNT`, which is `0`.
- A JOIN-fanning filter (meta / relationship) is aggregated over a distinct-primary
  subquery, so a one-to-many join never double-counts.
- Unknown/non-numeric column, unsupported function, or a duplicate alias is dropped
  and logged.

**Grouped** — add a `groupby` column to get one row per group (the group column(s)
plus each alias):

```php
$rows = $query->query( array(
    'aggregate' => array( 'revenue' => array( 'sum', 'amount' ) ),
    'groupby'   => 'status',
) );
// $rows = array( array( 'status' => 'complete', 'revenue' => '...' ), ... )
```

**Order + filter groups** — a grouped aggregate orders by an alias or group column
via the usual `orderby` / `order`, and filters groups by their results with
`having`:

```php
'aggregate' => array( 'revenue' => array( 'sum', 'amount' ) ),
'groupby'   => 'status',
'orderby'   => 'revenue',
'order'     => 'DESC',
'having'    => array( 'revenue' => array( '>', 1000 ) ), // or array( 'compare' => '>', 'value' => 1000 )
// -> ... GROUP BY status HAVING `revenue` > 1000 ORDER BY `revenue` DESC
```

`having` takes the scalar comparisons (`=`, `!=`, `<`, `<=`, `>`, `>=`) against a
surviving aggregate alias; it applies to **grouped** aggregates only, and multiple
entries AND together. The scalar `get_sum()` / `get_avg()` / `get_max()` /
`get_min()` methods remain the friendly path for a single ungrouped aggregate.

## JSON And Complex Values

BerlinDB does not magically know an arbitrary PHP array should be stored as
JSON. Encode arrays before writing to text columns:

```php
if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
	$data['settings'] = wp_json_encode( $data['settings'] );
}
```

Decode after reading if the Row API should expose arrays:

```php
public function __construct( $item ) {
	parent::__construct( $item );

	if ( is_string( $this->settings ) && '' !== $this->settings ) {
		$this->settings = json_decode( $this->settings, true );
	}
}
```

If the project uses `Column::$cast`, prefer the established local cast pattern
over ad hoc conversions.

## get_results() Convenience Wrapper

`get_results()` provides a `wpdb`-style signature for common list queries:

```php
$items = $query->get_results(
    array( 'id', 'name', 'status' ),  // fields
    array( 'status' => 'active' ),    // where args
    20,                               // number
    0,                                // offset
    OBJECT                            // output format
);
```

`update_item_cache` and `update_meta_cache` default to `false` in this method.
Pass them explicitly in the `$where_cols` array to override.

## Caching

BerlinDB uses three cooperating caches in the WordPress object cache, mirroring
how `WP_Query` and Doctrine cache. Objects are stored once, by primary ID;
everything else stores IDs and resolves through that one object cache.

1. **Query cache** — group `{cache_group}`, key
   `get_{plural}:{md5(query_vars)}:{last_changed}`. Value is the list of matching
   primary IDs plus the `found_items` count. Stores IDs only, never objects.
2. **By-id object cache** — group `{cache_group}`, key the primary ID (no salt;
   the ID is stable and unique). Value is the full row object — the single
   canonical object store. Written on insert/update, deleted on delete.
3. **Secondary lookup cache** — group `{cache_group}-by-{column}`, key
   `{md5(value)}:{last_changed}`. Value is the matching primary ID (a pointer),
   not the object. Used by `get_item_by()` on `cache_key` columns; the object is
   then resolved through cache #2.

**Invalidation is by salt, not deletion.** Caches #1 and #3 embed `last_changed`
in the key as a generation token. Any write bumps `last_changed`, so every key
built from the old value is abandoned at once — no per-key cleanup. Cache #2 is
keyed by the stable ID, so it is overwritten on update and deleted on delete.

**`get_item_by()` is for unique-value columns only** (see its docblock). A lookup
by a non-unique column is conceptually a query returning the first match — use
`query()` for that, not `get_item_by()`.

When debugging stale reads:

- inspect whether `cache_results` is enabled (default `true`)
- check cache group/prefix values
- confirm writes are going through Query methods (raw `$wpdb` writes do not bump
  `last_changed`, so they will not invalidate caches #1 or #3)
- prefer existing Query cache helpers over direct cache calls
