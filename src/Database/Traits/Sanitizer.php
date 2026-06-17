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
	private function sanitize_identifier( $id = '', $disallowed_pattern = '', $replacement = '', $lowercase = false, $normalize_hyphens = false ): string|false {

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

	/**
	 * Sanitize a value into a safe MySQL CAST() target type.
	 *
	 * A clean, general validator for the safe subset of CAST targets: BINARY,
	 * CHAR (optionally length), DATE, DATETIME, TIME, SIGNED, UNSIGNED, and
	 * DECIMAL (optionally precision/scale). Anything else REJECTS to '' — an
	 * empty result meaning "no cast" — rather than coercing to a default type.
	 *
	 * Unlike Parser::get_cast_for_type(), this carries none of meta_query's
	 * legacy vocabulary (no NUMERIC fold, no CHAR fallback); that mapper is now
	 * a thin Meta-flavored layer over this method.
	 *
	 * @since 3.1.0
	 *
	 * @param string $type Raw cast type.
	 *
	 * @return string The uppercased CAST target if valid, or '' if rejected.
	 */
	protected function sanitize_sql_cast_type( $type = '' ): string {

		// Bail if not a string.
		if ( ! is_string( $type ) ) {
			return '';
		}

		// Uppercase and trim; case and surrounding whitespace are not rejections.
		$upper = strtoupper( trim( $type ) );

		// Reject (do not coerce) anything outside the safe CAST target subset.
		return ( 1 === preg_match( '/^(?:BINARY|CHAR(?:\(\d+\))?|DATE|DATETIME|TIME|SIGNED|UNSIGNED|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $upper ) )
			? $upper
			: '';
	}

	/**
	 * Wrap an already-quoted SQL reference in a CAST() when a cast is requested.
	 *
	 * The $cast MUST already be normalized (e.g. via sanitize_sql_cast_type() or
	 * get_cast_for_type()); this method does not validate it. Only an empty $cast
	 * is a no-op — every other target (including CHAR, which is a valid cast for
	 * string-semantics comparison or LIKE on a numeric column) is wrapped. A
	 * caller that uses CHAR as a "no cast" sentinel must map it to '' first.
	 *
	 * @since 3.1.0
	 *
	 * @param string $reference An already-quoted column reference, e.g. `a`.`col`.
	 * @param string $cast      A normalized CAST target, or '' for no cast.
	 *
	 * @return string The reference, optionally wrapped in CAST( ... AS $cast ).
	 */
	protected function cast_reference( string $reference, string $cast = '' ): string {
		return ( '' === $cast )
			? $reference
			: "CAST({$reference} AS {$cast})";
	}

	/** Value Sanitizers ******************************************************/

	/**
	 * Coerce a value to a boolean.
	 *
	 * Mirrors WordPress's wp_validate_boolean(): the string 'false' (truthy to
	 * PHP) is treated as false.
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Value to coerce.
	 * @return bool
	 */
	protected function sanitize_boolean( $value = false ): bool {

		// Already boolean.
		if ( is_bool( $value ) ) {
			return $value;
		}

		// The string 'false' means false here, despite being truthy to PHP.
		if ( is_string( $value ) && ( 'false' === strtolower( $value ) ) ) {
			return false;
		}

		return (bool) $value;
	}

	/**
	 * Coerce a value to a non-negative integer.
	 *
	 * Mirrors WordPress's absint().
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $value Value to coerce.
	 * @return int
	 */
	protected function sanitize_absint( $value = 0 ): int {
		return abs( (int) $value );
	}

	/**
	 * Sanitize a key: lowercased, limited to a-z, 0-9, underscore and hyphen.
	 *
	 * Mirrors WordPress's sanitize_key() but WITHOUT its 'sanitize_key' filter,
	 * so BerlinDB's internal identity keys are stable and dependency-free (a
	 * global filter should not reshape them).
	 *
	 * Note that this method is not a general-purpose sanitizer: it is
	 * designed specifically for sanitizing internal identity keys, so it is
	 * not a problem if it returns an empty string for a bad input.
	 *
	 * The caller should not use this method for sanitizing anything that
	 * will be exposed to users or embedded in SQL; it is only for internal
	 * keys.
	 *
	 * For user-facing or SQL-embedded values, use a more appropriate
	 * sanitizer that rejects or escapes bad input rather than stripping it,
	 * and that does not return an empty string for bad input (because an
	 * empty string is a valid key, albeit a degenerate one, and should not
	 * be used as an error signal for user-facing or SQL-embedded values).
	 *
	 * @since 3.1.0
	 *
	 * @param mixed $key Key to sanitize.
	 * @return string
	 */
	protected function sanitize_key( $key = '' ): string {

		// Bail to empty if not a scalar.
		if ( ! is_scalar( $key ) ) {
			return '';
		}

		// Lowercase, then strip anything outside the safe key character set.
		$key = strtolower( (string) $key );

		/*
		 * Return the sanitized key, which may be empty if the input was all
		 * bad chars.
		 */
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
