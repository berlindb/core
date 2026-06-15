# Migration Notes From 2.x To 3.x

## Namespaces

Prefer current 3.x namespaces:

```php
use BerlinDB\Database\Kern\Schema;
use BerlinDB\Database\Kern\Table;
use BerlinDB\Database\Kern\Query;
use BerlinDB\Database\Kern\Row;
```

Avoid old examples that extend:

```php
BerlinDB\Database\Schema
BerlinDB\Database\Table
BerlinDB\Database\Query
BerlinDB\Database\Row
```

If a plugin uses Mozart or another namespace-prefixer, write canonical BerlinDB
imports in source and let the build step rewrite them.

## Table Schema Setup

Older examples often override `Table::set_schema()` and assign raw SQL. In
BerlinDB 3.x, Table schema setup is internal and Schema objects are first-class.
Prefer:

```php
final class WidgetTable extends Table {
	protected $schema = WidgetSchema::class;
	protected $name = 'acme_widgets';
	protected $version = '202605280';
}
```

Do not copy old snippets that override private methods.

## Renamed Methods

Two protected helper methods were renamed. Deprecated aliases exist and will not
be removed before a future major version, but update them when touching the code:

```php
// Schema — was to_string()
$sql = $schema->get_create_table_string();

// Environment trait on Query/Table — was get_db()
$this->db()->query( $sql );
```

## New In 3.x (Additive)

- `cache_results` query var (default `true`) — pass `false` to bypass the
  result-list cache for a single query without flushing the whole cache.
- `Query::get_results()` — `wpdb`-style convenience wrapper.
- `Schema::from_table()` — build a Schema from a live table via introspection.
- Structured log on every Query and Table instance via `get_logs()`.

## Connection Layer

BerlinDB 3.x adds a `Connection` interface and adapters. Code that reached for
`$wpdb` directly may need to use `db()` or the established Query/Table helpers.

Look for:

- `Connection`
- `Wpdb`
- `NullConnection`
- `Traits\Environment`

## Dynamic Properties And Magic

BerlinDB 3.x avoids implicit dynamic property access and extracts magic helpers.
When migrating, declare properties explicitly on Row, Query, Table, and fixtures.

## Lifecycle State

Query per-run state moved toward lifecycle/current-state helpers. If older code
mutates query internals directly, replace that with public Query APIs or local
extension points.

## Parser/Operator Extraction

Query parsing is now more modular. Before changing SQL assembly, inspect:

- `src/Database/Parsers`
- `src/Database/Operators`
- `src/Database/Traits/Parser.php`
- parser/operator tests

Prefer adding a parser/operator or schema flag over hardcoding SQL in Query.

## PHP Requirement

BerlinDB 3.x requires PHP 8.1 or newer (up from 7.4 in 2.x). Do not preserve
PHP 7.x workarounds in new BerlinDB 3.x code unless an integration has its own
compatibility layer.
