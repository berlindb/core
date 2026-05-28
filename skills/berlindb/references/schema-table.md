# Schema And Table Reference

## Schema

Extend `BerlinDB\Database\Kern\Schema` and define public `$columns` and
`$indexes` arrays.

```php
use BerlinDB\Database\Kern\Schema;

final class WidgetSchema extends Schema {
	public $columns = array(
		array(
			'name'      => 'id',
			'type'      => 'bigint',
			'length'    => '20',
			'unsigned'  => true,
			'extra'     => 'auto_increment',
			'default'   => false,
			'cache_key' => true,
			'sortable'  => true,
		),
		array(
			'name'       => 'name',
			'type'       => 'varchar',
			'length'     => '200',
			'default'    => '',
			'searchable' => true,
			'sortable'   => true,
		),
		array(
			'name'       => 'status',
			'type'       => 'varchar',
			'length'     => '20',
			'default'    => 'active',
			'cache_key'  => true,
			'transition' => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'date_created',
			'type'       => 'datetime',
			'default'    => '',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),
	);

	public $indexes = array(
		array(
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'status',
			'type'    => 'key',
			'columns' => array( 'status' ),
		),
	);
}
```

## Important Column Keys

Common keys include:

- `name`: database column name
- `type`: MySQL type, such as `bigint`, `varchar`, `longtext`, `datetime`
- `length`: MySQL length where applicable
- `unsigned`: numeric unsigned modifier
- `extra`: values such as `auto_increment`
- `default`: default value
- `allow_null`: whether SQL `NULL` is accepted
- `cache_key`: safe to query/cache by this column
- `sortable`: can be used for `orderby`
- `searchable`: included in search parsing
- `date_query`: enabled for date query parsing
- `created`: auto-fill on insert
- `modified`: auto-fill on insert/update
- `transition`: run transition hooks when value changes
- `in` / `not_in`: support `{column}__in` and `{column}__not_in`

Use `'allow_null' => true`; do not use `'null' => true`.

## Nullable Values

For nullable columns, set both `allow_null` and a null default:

```php
array(
	'name'       => 'external_id',
	'type'       => 'varchar',
	'length'     => '100',
	'allow_null' => true,
	'default'    => null,
)
```

Without `allow_null`, PHP-layer validation can reject `null` before the database
query runs.

## Table

Extend `BerlinDB\Database\Kern\Table`.

```php
use BerlinDB\Database\Kern\Table;

final class WidgetTable extends Table {
	protected $schema = WidgetSchema::class;
	protected $name = 'acme_widgets';
	protected $version = '202605280';
}
```

Notes:

- `$name` is the unprefixed table name.
- `$version` is the table schema/database version, not the package version.
  It must be a **string** — `Table.php` uses `strict_types=1` and passes it
  to `version_compare()`. The EDD convention is a date-based string such as
  `'202605280'` with a trailing digit for same-day releases.
- `$schema` may be a Schema class name or Schema instance.
- BerlinDB 3.x builds the table schema from the Schema object.
- Do not override private internals such as `set_schema()`.
- For multisite global tables (shared across all sites), add
  `protected $global = true;`. Per-site tables omit this property.

## Schema Upgrades Via $upgrades

To add or alter columns/indexes across versions, declare an `$upgrades` map
and implement a protected callback method for each version:

```php
final class WidgetTable extends Table {
    protected $schema  = WidgetSchema::class;
    protected $name    = 'acme_widgets';
    protected $version = '202605281';

    protected $upgrades = array(
        '202605281' => '__upgrade_202605281',
    );

    protected function __upgrade_202605281(): bool {
        if ( ! $this->column_exists( 'notes' ) ) {
            return $this->is_success(
                $this->db()->query(
                    "ALTER TABLE {$this->table_name}
                     ADD COLUMN notes longtext NOT NULL DEFAULT ''"
                )
            );
        }
        return true;
    }
}
```

BerlinDB compares the installed version against `$version` and runs any
callbacks whose key is greater than the installed version. Always return `bool`
from upgrade callbacks — `false` stops the upgrade chain.

## Creating And Upgrading Tables

Call `maybe_upgrade()` from an activation/upgrade path. In tests, BerlinDB may
call it automatically when testing mode is detected.

```php
( new WidgetTable() )->maybe_upgrade();
```

For a first install where you explicitly want creation:

```php
( new WidgetTable() )->install();
```

When changing a column or index, bump the Table `$version`. BerlinDB stores the
installed version in WordPress options and compares it with the Table version.

## Introspection

BerlinDB 3.x includes schema/table introspection helpers. If the task involves
existing tables, search local source/tests for:

- `Column::from_mysql()`
- `Schema::from_table()`
- schema factory tests
- table/schema log tests

Prefer these factories over hand-parsing MySQL output.
