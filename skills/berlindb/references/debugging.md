# Debugging Reference

## Silent Insert Or Update Failure

Symptoms:

- `add_item()` or `update_item()` returns `false`
- `$wpdb->last_error` may be empty
- no failed SQL query appears because validation stopped before SQL ran

Common causes:

- nullable data passed to a column without `'allow_null' => true`
- invalid value for a column type or cast
- array/object passed to a scalar column without serialization/encoding
- field removed by capability reduction — check schema caps and local test
  fixtures first; some setups need `wp_set_current_user( 1 )` before writes
- table/schema mismatch after an upgrade

Debugging steps:

```php
$result = $query->add_item( $data );
error_log( 'add_item result: ' . var_export( $result, true ) );
error_log( 'wpdb last_error: ' . $GLOBALS['wpdb']->last_error );
```

Inspect parsed columns:

```php
foreach ( $query->get_columns() as $column ) {
	error_log(
		$column->name . ' allow_null=' . var_export( $column->allow_null, true )
	);
}
```

## Wrong Identifier For Update/Delete

`update_item()` and `delete_item()` expect the primary key value.

Wrong:

```php
$query->update_item( $slug, $data );
```

Right:

```php
$item = $query->get_item_by( 'slug', $slug );
$query->update_item( $item->id, $data );
```

## Existing Table Did Not Change

If column/index changes are not reflected:

1. Confirm the Table `$version` changed.
2. Confirm the upgrade path calls `maybe_upgrade()`.
3. Confirm `is_upgradeable()` is not bailing for global tables.
4. Confirm the table is not locked by a stuck upgrade lock.
5. Inspect table status/columns/indexes with Table helpers before writing raw SQL.

## Parser Or Query Var Does Nothing

Check Schema flags. Query vars are schema-aware:

- missing `in` means `{column}__in` may not apply
- missing `not_in` means `{column}__not_in` may not apply
- missing `sortable` means `orderby` may fall back
- missing `date_query` means date parsing may not target that column
- missing `searchable` means search may not include that column

Search tests for the parser before changing behavior:

```bash
rg -n "__in|__not_in|date_query|orderby|search" tests src
```

## Connection Or `$wpdb` Problems

BerlinDB 3.x routes database access through `Connection` implementations:

- `BerlinDB\Database\Interfaces\Connection`
- `BerlinDB\Database\Adapters\Wpdb`
- `BerlinDB\Database\Adapters\NullConnection`

When debugging global `$wpdb` swaps, multisite switching, or tests with missing
database globals, inspect `Traits\Environment` and adapter tests first.

## Structured Log

BerlinDB 3.x records a structured in-memory log of operations on every Query
and Table instance. Check it before reaching for `error_log`:

```php
$id = $query->add_item( $data );
var_dump( $query->get_logs() );
```

Each entry contains a level, a code, a message, and a context array. The log is
cumulative — it is **not** reset between operations (nothing calls `clear_logs()`
automatically). Call `clear_logs()` before the operation you want to inspect, or
filter with `get_logs( array( 'code' => '…' ) )`, then read it right after the
call of interest.

## When To Talk Before Fixing

If tests reveal a source bug in BerlinDB itself, stop and discuss the fix before
modifying non-test code unless the user explicitly asked for implementation.
This is especially important near releases.
