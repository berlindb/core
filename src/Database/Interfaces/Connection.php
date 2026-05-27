<?php
/**
 * Database Connection interface.
 *
 * @package     BerlinDB\Database\Interfaces
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Interfaces;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Contract for the database handle BerlinDB expects.
 *
 * The WordPress-native implementation is BerlinDB\Database\Adapters\Wpdb.
 * Custom adapters must satisfy this interface to swap in a different database
 * layer. Callers retrieve an implementation via the Environment trait's
 * db() / get_db_global() helpers.
 *
 * @since 3.0.0
 */
interface Connection {

	/**
	 * Prepare a SQL query for safe execution.
	 *
	 * @since 3.0.0
	 *
	 * @phpstan-param non-empty-string $query
	 *
	 * @param string $query   SQL query with sprintf()-like placeholders.
	 * @param mixed  ...$args Values to substitute into placeholders.
	 * @return string|null Prepared statement string, or null on failure.
	 */
	public function prepare( string $query, mixed ...$args ): string|null;

	/**
	 * Execute a SQL statement.
	 *
	 * @since 3.0.0
	 *
	 * @param string $query SQL statement to execute.
	 * @return int|bool Rows affected/selected, or false on failure.
	 */
	public function query( string $query ): int|bool;

	/**
	 * Return a single value from a query.
	 *
	 * @since 3.0.0
	 *
	 * @param string|null $query         SQL query; null re-uses the last query.
	 * @param int         $column_offset Zero-based column index to return.
	 * @param int         $row_offset    Zero-based row index to return.
	 * @return string|null
	 */
	public function get_var( string|null $query = null, int $column_offset = 0, int $row_offset = 0 ): string|null;

	/**
	 * Return a single row from a query.
	 *
	 * @since 3.0.0
	 *
	 * @phpstan-param 'ARRAY_A'|'ARRAY_N'|'OBJECT' $output
	 * @phpstan-param int<0, max> $y
	 *
	 * @param string|null $query  SQL query; null re-uses the last query.
	 * @param string      $output Output type: OBJECT, ARRAY_A, or ARRAY_N.
	 * @param int         $y      Zero-based row index to return.
	 * @return array<string, mixed>|object|null
	 */
	public function get_row( string|null $query = null, string $output = 'OBJECT', int $y = 0 ): array|object|null;

	/**
	 * Return all rows from a query.
	 *
	 * @since 3.0.0
	 *
	 * @phpstan-param 'ARRAY_A'|'ARRAY_N'|'OBJECT'|'OBJECT_K' $output
	 *
	 * @param string|null $query  SQL query; null re-uses the last query.
	 * @param string      $output Output type: OBJECT, ARRAY_A, ARRAY_N, or OBJECT_K.
	 * @return array<int, mixed>|object|null
	 */
	public function get_results( string|null $query = null, string $output = 'OBJECT' ): array|object|null;

	/**
	 * Return a single column from a query as a flat array.
	 *
	 * @since 3.0.0
	 *
	 * @param string|null $query         SQL query; null re-uses the last query.
	 * @param int         $column_offset Zero-based column index to return.
	 * @return array<int, mixed>
	 */
	public function get_col( string|null $query = null, int $column_offset = 0 ): array;

	/**
	 * Insert a row into a table.
	 *
	 * @since 3.0.0
	 *
	 * @param string                     $table  Table name.
	 * @param array<string, mixed>       $data   Column => value pairs to insert.
	 * @param array<string>|string|null  $format sprintf-format specifiers for $data.
	 * @return int|false Number of rows inserted, or false on failure.
	 */
	public function insert( string $table, array $data, array|string|null $format = null ): int|false;

	/**
	 * Update one or more rows in a table.
	 *
	 * @since 3.0.0
	 *
	 * @param string                     $table        Table name.
	 * @param array<string, mixed>       $data         Column => value pairs to update.
	 * @param array<string, mixed>       $where        Column => value WHERE conditions.
	 * @param array<string>|string|null  $format       Format for $data values.
	 * @param array<string>|string|null  $where_format Format for $where values.
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function update( string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null ): int|false;

	/**
	 * Delete one or more rows from a table.
	 *
	 * @since 3.0.0
	 *
	 * @param string                     $table        Table name.
	 * @param array<string, mixed>       $where        Column => value WHERE conditions.
	 * @param array<string>|string|null  $where_format Format for $where values.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public function delete( string $table, array $where, array|string|null $where_format = null ): int|false;

	/**
	 * Escape a string for use in a LIKE comparison.
	 *
	 * @since 3.0.0
	 *
	 * @param string $text String to escape.
	 * @return string Escaped string safe for LIKE patterns.
	 */
	public function esc_like( string $text ): string;

	/**
	 * Toggle error suppression and return the previous state.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $suppress True to suppress errors; false to re-enable.
	 * @return bool Previous suppression state.
	 */
	public function suppress_errors( bool $suppress = true ): bool;

	/**
	 * Return the table-name prefix for a given site.
	 *
	 * Non-WordPress adapters that have no concept of multisite may always
	 * return a fixed prefix and ignore $blog_id.
	 *
	 * @since 3.0.0
	 *
	 * @param int|null $blog_id Site ID; null means the current site.
	 * @return string Table prefix including trailing underscore (e.g. 'wp_').
	 */
	public function get_blog_prefix( int|null $blog_id = null ): string;

	/**
	 * Return the ID generated by the most recent INSERT statement.
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	public function get_insert_id(): int;

	/**
	 * Return the connection's default character set.
	 *
	 * @since 3.0.0
	 *
	 * @return string e.g. 'utf8mb4'.
	 */
	public function get_charset(): string;

	/**
	 * Return the connection's default collation.
	 *
	 * @since 3.0.0
	 *
	 * @return string e.g. 'utf8mb4_unicode_520_ci'.
	 */
	public function get_collation(): string;

	/**
	 * Return a registered table's fully-qualified name by its unprefixed key.
	 *
	 * Returns an empty string when the key has not been registered.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Unprefixed table key (e.g. 'edd_orders').
	 * @return string Fully-qualified table name, or '' if not registered.
	 */
	public function get_table_prefix( string $key ): string;

	/**
	 * Register a table's fully-qualified name under its unprefixed key.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key   Unprefixed table key (e.g. 'edd_orders').
	 * @param string $value Fully-qualified table name (e.g. 'wp_edd_orders').
	 * @return void
	 */
	public function set_table_prefix( string $key, string $value ): void;

	/**
	 * Append a table key to a named group array.
	 *
	 * Maps to the wpdb convention of $wpdb->tables[] and
	 * $wpdb->ms_global_tables[]. The method is idempotent — duplicate entries
	 * are ignored.
	 *
	 * @since 3.0.0
	 *
	 * @param string $group Table group name ('tables', 'ms_global_tables', etc.).
	 * @param string $name  Unprefixed table key to add to the group.
	 * @return void
	 */
	public function register_table( string $group, string $name ): void;
}
