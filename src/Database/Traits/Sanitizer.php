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

// Exit if accessed directly
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
	 * @return bool|string Sanitized value on success, false on error.
	 */
	private function sanitize_identifier( $id = '', $disallowed_pattern = '', $replacement = '', $lowercase = false, $normalize_hyphens = false ) {

		// Bail if empty or not a string.
		if ( empty( $id ) || ! is_string( $id ) ) {
			return false;
		}

		// Trim spaces off the ends.
		$unspace = trim( $id );

		// Only non-accented table names (avoid truncation)
		$accents = remove_accents( $unspace );

		// Convert to lowercase if required.
		$chars   = ( true === $lowercase )
			? strtolower( $accents )
			: $accents;

		// Keep only allowed characters, either by removing or replacing disallowed ones.
		$replace = preg_replace( $disallowed_pattern, $replacement, $chars );

		// Replace hyphens with single underscores if required.
		$under   = ( true === $normalize_hyphens )
			? str_replace( '-', '_', $replace )
			: $replace;

		// Normalize ALL consecutive underscores to single underscore (not just __)
		$single  = preg_replace( '/_+/', '_', $under );

		// Remove leading/trailing underscores.
		$clean   = trim( $single, '_' );

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
	 * @return bool|string Sanitized table name on success, false on error.
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
	 * @return bool|string Sanitized alias on success, false on error.
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
	 * @return bool|string Sanitized column name on success, false on error.
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
	 * @return bool|string Sanitized index name on success, false on error.
	 */
	protected function sanitize_index_name( $name = '' ) {
		return $this->sanitize_identifier( $name, '/[^a-z0-9_\-]/', '_', true, true );
	}
}