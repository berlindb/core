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

- returns the new database insert ID on success (`int`)
- returns `false` on failure

```php
$id = $query->add_item( $data );
if ( false === $id ) {
	// insert failed
}
```

`update_item( $item_id, array $data )`:

- first argument is the primary key value
- returns `true` when a table-column update is written
- returns `false` on failure, **and also** when nothing in table columns needs
  saving after diffing — `false` means "no write occurred", not necessarily an error

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

- first argument is the integer primary key
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
