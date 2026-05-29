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
- returns `false` on failure, and also when there is nothing in table columns
  to save after diffing the incoming data

```php
$updated = $query->update_item( $widget->id, $data );
if ( false === $updated ) {
	// either the update failed, or no table-column change was written
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
In that mode, `get_found_items()` reflects the number of retrieved rows. For
paginated responses, pass
`'no_found_rows' => false` explicitly:

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

BerlinDB queries include a two-layer cache: a result-list cache (query vars →
ID list) and an item-object cache (ID → full row). When debugging stale reads:

- inspect whether `cache_results` is enabled (default `true`)
- check cache group/prefix values
- review item update/delete cache invalidation paths
- prefer existing Query cache helpers over direct cache calls
