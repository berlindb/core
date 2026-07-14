# BerlinDB

[![CI](https://github.com/berlindb/core/actions/workflows/ci.yml/badge.svg)](https://github.com/berlindb/core/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/berlindb/core.svg)](https://packagist.org/packages/berlindb/core)
[![PHP Version](https://img.shields.io/packagist/dependency-v/berlindb/core/php.svg)](https://packagist.org/packages/berlindb/core)
[![License](https://img.shields.io/packagist/l/berlindb/core.svg)](LICENSE)

**Reunification readiness** &mdash; how faithfully shared BerlinDB can express real plugins' database layers, measured by [berlindb/readiness](https://github.com/berlindb/readiness):

[![EDD](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/berlindb/edd-core-tables/master/.readiness/edd.json&label=EDD)](https://github.com/berlindb/edd-core-tables)
[![Sugar Calendar](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/berlindb/sc-core-tables/master/.readiness/sugar-calendar.json&label=Sugar%20Calendar)](https://github.com/berlindb/sc-core-tables)
[![WordPress](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/berlindb/wp-core-tables/master/.readiness/wordpress.json&label=WordPress)](https://github.com/berlindb/wp-core-tables)
[![WooCommerce](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/berlindb/wc-core-tables/master/.readiness/woocommerce.json&label=WooCommerce)](https://github.com/berlindb/wc-core-tables)

<sub>EDD and Sugar Calendar score their own first-generation forks (an independent yardstick); WordPress and WooCommerce are core-native parity plugins, so their 100% is by construction. Behavioral flags only, for now.</sub>

BerlinDB provides an ORM-like interface for custom database tables in
WordPress.

Use it when custom post types, taxonomies, or post meta are no longer the right
storage model for your data, but you still want a WordPress-native developer
experience: `wpdb` compatibility, schema objects, query builders, row objects,
caching hooks, and table upgrade routines.

## Requirements

- PHP 8.1 or newer
- WordPress
- Composer

## Installation

```bash
composer require berlindb/core
```

## Quick Start

A typical integration defines four small classes:

- a `Schema` that describes columns and indexes
- a `Table` that creates and upgrades the database table
- a `Row` that shapes returned records
- a `Query` that reads and writes records

### Define A Schema

```php
<?php

namespace Acme\Plugin\Database;

use BerlinDB\Database\Kern\Schema;

class WidgetSchema extends Schema {

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
			'name'      => 'status',
			'type'      => 'varchar',
			'length'    => '20',
			'default'   => 'active',
			'cache_key' => true,
			'in'        => true,
			'not_in'    => true,
		),
		array(
			'name'     => 'date_created',
			'type'     => 'datetime',
			'default'  => '',
			'created'  => true,
			'sortable' => true,
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

### Define A Table

```php
<?php

namespace Acme\Plugin\Database;

use BerlinDB\Database\Kern\Table;

class WidgetTable extends Table {

	protected $schema = WidgetSchema::class;

	protected $name = 'acme_widgets';

	protected $version = '202605280';
}
```

Create or upgrade the table during your plugin's install or upgrade routine:

```php
( new WidgetTable() )->install();
```

### Define A Row

```php
<?php

namespace Acme\Plugin\Database;

use BerlinDB\Database\Kern\Row;

class Widget extends Row {

	public $id = 0;

	public $name = '';

	public $status = 'active';

	public $date_created = '';
}
```

### Define A Query

```php
<?php

namespace Acme\Plugin\Database;

use BerlinDB\Database\Kern\Query;

class WidgetQuery extends Query {

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

### Query Data

```php
$query = new WidgetQuery();

$widget_id = $query->add_item(
	array(
		'name'   => 'Example',
		'status' => 'active',
	)
);

$widget = $query->get_item( $widget_id );

$active_widgets = $query->query(
	array(
		'status__in' => array( 'active', 'pending' ),
		'orderby'    => 'date_created',
		'order'      => 'DESC',
		'number'     => 20,
	)
);

$query->update_item(
	$widget_id,
	array(
		'status' => 'archived',
	)
);

$query->delete_item( $widget_id );
```

## Documentation

The project wiki contains deeper documentation for the current object model,
including adapters, interfaces, traits, parsers, operators, schemas, tables, and
queries.

- [Packagist](https://packagist.org/packages/berlindb/core)
- [BerlinDB Wiki](https://github.com/berlindb/core/wiki)
- [Changelog](CHANGELOG.md)
- [Open Issues](https://github.com/berlindb/core/issues)

## Development

Install dependencies:

```bash
composer install
```

Run the default test suite:

```bash
bin/run-tests.sh -- --group default
```

Run the suite against a specific PHP and WordPress version:

```bash
bin/run-tests.sh -p 8.1 -w 6.7 -- --group default
```

Run static analysis and coding standards:

```bash
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full local workflow.

## Name

BerlinDB is named for [WordCamp Europe 2019](https://europe.wordcamp.org/2019/)
in Berlin, Germany, where it was
[originally exhibited and announced](https://jjj.blog/wceu-2019/) as an unnamed
utility being used by the Sandhills Development engineering team.

[Peter Wilson](https://peterwilson.cc) recommended naming it "Berlin" to
commemorate everyone in attendance for its unveiling. Thanks, Peter.

## License

BerlinDB is open-source software licensed under the [MIT license](LICENSE).
