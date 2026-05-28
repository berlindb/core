<?php
/**
 * Partial wpdb stub for BerlinDB.
 *
 * BerlinDB is a SQL query builder. It builds parameterized SQL templates
 * dynamically from sanitized schema-defined identifiers (table names, column
 * names, validated patterns). The template argument to prepare() is always
 * safe — no user input is ever interpolated into the template itself. All
 * user-supplied values are passed as separate prepare() arguments.
 *
 * The @phpstan-param literal-string annotation in php-stubs/wordpress-stubs
 * is appropriate for typical plugins that write SQL inline, but not for a
 * query builder that constructs templates programmatically. This stub
 * overrides the prepare(), esc_like(), and query() signatures to accept
 * non-empty-string so PHPStan can verify the rest of BerlinDB's type safety.
 */

class wpdb {
	/**
	 * @param non-empty-string $query   SQL query with sprintf()-like placeholders.
	 * @param mixed            ...$args Values to substitute into placeholders.
	 * @return string|void Sanitized query string.
	 */
	public function prepare( $query, ...$args ) {}

	/**
	 * @param string $text The raw text to be escaped.
	 * @return string Text escaped for use in a LIKE clause.
	 */
	public function esc_like( $text ) {}

	/**
	 * @param string $query Database query.
	 * @return int|bool Number of rows affected/selected, or false on error.
	 */
	public function query( $query ) {}
}
