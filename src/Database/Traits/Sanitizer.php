<?php
/**
 * Sanitizer Helpers.
 *
 * @package     Database
 * @subpackage  Traits
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Shared sanitizer helpers for database-related classes.
 *
 * @since 3.0.0
 */
trait Sanitizer {

	/**
	 * Sanitize a value using the shared normalization pipeline.
	 *
	 * @since 3.0.0
	 *
	 * @param string $id                 Raw value.
	 * @param string $disallowed_pattern Regex pattern matching disallowed chars.
	 * @param string $replacement        Replacement for disallowed chars.
	 * @param bool   $lowercase          Whether to lowercase before sanitizing.
	 * @param bool   $normalize_hyphens  Whether to convert hyphens to underscores.
	 *
	 * @return string|false Sanitized value on success, false on error.
	 */
	private function sanitize_identifier( $id = '', $disallowed_pattern = '', $replacement = '', $lowercase = false, $normalize_hyphens = false ) {

		// Bail if empty or not a string.
		if ( empty( $id ) || ! is_string( $id ) ) {
			return false;
		}

		// Trim spaces off the ends.
		$unspace = trim( $id );

		// Only non-accented table names (avoid truncation).
		$accents = remove_accents( $unspace );

		// Convert to lowercase if required.
		$chars = ( true === $lowercase )
			? strtolower( $accents )
			: $accents;

		// Keep only allowed characters, either by removing or replacing disallowed ones.
		$replace = preg_replace( $disallowed_pattern, $replacement, $chars );

		// Ensure the replacement result is a string.
		if ( ! is_string( $replace ) ) {
			return false;
		}

		// Replace hyphens with single underscores if required.
		$under = ( true === $normalize_hyphens )
			? str_replace( '-', '_', $replace )
			: $replace;

		// Normalize ALL consecutive underscores to single underscore (not just __).
		$single = preg_replace( '/_+/', '_', $under );

		// Remove leading/trailing underscores.
		$clean = trim( $single ?? '', '_' );

		// Bail if table name was garbaged or return the cleaned table name.
		return empty( $clean )
			? false
			: $clean;
	}

	/**
	 * Sanitize a table name string.
	 *
	 * Per MySQL unquoted name rules:
	 * - Permitted unquoted chars: [0-9, a-z, A-Z, $, _]
	 * - Extended Unicode U+0080 .. U+FFFF in BMP
	 * - Keep [a-zA-Z0-9_-], convert - to _
	 * - Normalize consecutive underscores to single
	 * - Trim leading/trailing underscores
	 *
	 * @since 3.0.0
	 *
	 * @param string $name The SQL table name.
	 *
	 * @return string|false Sanitized table name on success, false on error.
	 */
	protected function sanitize_table_name( $name = '' ) {
		return $this->sanitize_identifier( $name, '/[^a-zA-Z0-9_\-]/', '', false, true );
	}

	/**
	 * Sanitize a table alias string.
	 *
	 * Per MySQL unquoted name rules:
	 * - Permitted unquoted chars: [0-9, a-z, A-Z, $, _]
	 * - Extended Unicode U+0080 .. U+FFFF in BMP
	 * - Avoid $ (deprecated in MySQL 8.0.32+)
	 *
	 * Returns ASCII-safe format: [a-zA-Z0-9_] with normalized underscores.
	 *
	 * @since 3.0.0
	 *
	 * @param string $alias The SQL table alias.
	 *
	 * @return string|false Sanitized alias on success, false on error.
	 */
	protected function sanitize_table_alias( $alias = '' ) {
		return $this->sanitize_identifier( $alias, '/[^a-zA-Z0-9_]/', '_', false, false );
	}

	/**
	 * Sanitize a column name string.
	 *
	 * Per MySQL unquoted name rules:
	 * - Permitted unquoted chars: [0-9, a-z, A-Z, $, _]
	 * - Extended Unicode U+0080 .. U+FFFF in BMP
	 * - Keep [a-zA-Z0-9_-], convert - to _
	 * - Normalize consecutive underscores to single
	 * - Trim leading/trailing underscores
	 *
	 * @since 3.0.0
	 *
	 * @param string $name The SQL column name.
	 *
	 * @return string|false Sanitized column name on success, false on error.
	 */
	protected function sanitize_column_name( $name = '' ) {
		return $this->sanitize_identifier( $name, '/[^a-zA-Z0-9_\-]/', '', false, true );
	}

	/**
	 * Sanitize an index name string.
	 *
	 * Per MySQL unquoted name rules:
	 * - Permitted unquoted chars: [0-9, a-z, A-Z, $, _]
	 * - Extended Unicode U+0080 .. U+FFFF in BMP
	 * - Lowercase, keep [a-z0-9_-], convert - to _
	 * - Normalize consecutive underscores to single
	 * - Trim leading/trailing underscores
	 *
	 * @since 3.0.0
	 *
	 * @param string $name The SQL index name.
	 *
	 * @return string|false Sanitized index name on success, false on error.
	 */
	protected function sanitize_index_name( $name = '' ) {
		return $this->sanitize_identifier( $name, '/[^a-z0-9_\-]/', '_', true, true );
	}

	/**
	 * Sanitize a comment string for use in a MySQL COMMENT clause.
	 *
	 * MySQL enforces a 1024-character limit on column comments and a 2048-character
	 * limit on table comments. Null bytes are stripped because they break SQL even
	 * when escaped with addslashes. The caller is responsible for escaping the
	 * returned value before embedding it in SQL (e.g. via addslashes).
	 *
	 * @since 3.0.0
	 *
	 * @param string $comment    Raw comment value.
	 * @param int    $max_length Maximum allowed character length. Default 1024.
	 *
	 * @return string Sanitized comment.
	 */
	protected function sanitize_comment( $comment = '', $max_length = 1024 ) {

		// Strip HTML tags and normalize whitespace.
		$clean = sanitize_textarea_field( $comment );

		// Remove null bytes which break SQL even when escaped.
		$clean = str_replace( "\0", '', $clean );

		// Enforce the MySQL COMMENT maximum length.
		return substr( $clean, 0, $max_length );
	}

	/**
	 * Sanitize a PHP class reference (a fully-qualified class name).
	 *
	 * Unlike the SQL-identifier sanitizers, a class reference may contain
	 * namespace separators. This REJECTS rather than strips: a value carrying
	 * any other character returns '', because stripping could quietly turn a bad
	 * value into a different, real class (e.g. 'Order; DROP TABLE' becomes
	 * 'OrderDROPTABLE'). Surrounding whitespace is trimmed and is not on its own
	 * a reason to reject. Callers should still verify class_exists()/is_a() at
	 * the point of use.
	 *
	 * @since 3.1.0
	 *
	 * @param string $class Raw class reference.
	 *
	 * @return string The class reference unchanged if valid, or '' if rejected.
	 */
	protected function sanitize_class_name( $class = '' ) {

		// Bail if not a string.
		if ( ! is_string( $class ) ) {
			return '';
		}

		// Trim; surrounding whitespace alone is not a reason to reject.
		$class = trim( $class );

		// Reject (do not strip) anything a class reference cannot contain.
		return ( '' !== $class ) && ( 1 === preg_match( '/^[a-zA-Z0-9_\\\\]+$/', $class ) )
			? $class
			: '';
	}

	/**
	 * Wrap a sanitized identifier in MySQL backtick quotes.
	 *
	 * Must be called after the identifier has already been passed through one of
	 * the sanitize_*_name() methods, which ensure only safe characters remain.
	 * Any literal backtick that somehow survived sanitization is doubled so the
	 * resulting SQL identifier is always valid.
	 *
	 * @since 3.0.0
	 *
	 * @param string $identifier A sanitized table name, column name, or alias.
	 *
	 * @return string Backtick-quoted identifier, e.g. `column_name`.
	 */
	protected function quote_identifier( $identifier = '' ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}
}
