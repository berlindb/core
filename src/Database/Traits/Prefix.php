<?php
/**
 * Prefix Helpers.
 *
 * @package     Database
 * @subpackage  Traits
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for BerlinDB's plugin-level prefix strings.
 *
 * @since 3.1.0
 */
trait Prefix {

	/**
	 * Global prefix used for tables/hooks/cache-groups/etc...
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefix = '';

	/**
	 * Prepend the plugin prefix ($this->prefix) to a string.
	 *
	 * Applies the plugin-level prefix only (e.g. 'edd_orders'). The
	 * WordPress table prefix ($wpdb->prefix) is a separate concern and is
	 * NOT added here. Already-prefixed strings are returned as-is to
	 * prevent double-prefixing.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Prevents double prefixing.
	 *
	 * @param string $value The string to prefix.
	 * @param string $sep   Separator placed between prefix and string. Default '_'.
	 * @return string The prefixed string, or the original string if $prefix is empty.
	 */
	protected function apply_prefix( $value = '', $sep = '_' ) {

		// Bail if not a string.
		if ( ! is_string( $value ) ) {
			return '';
		}

		// Trim spaces off the ends.
		$retval = trim( $value );

		// Bail if no prefix.
		if ( empty( $this->prefix ) ) {
			return $retval;
		}

		// Setup new prefix.
		$new_prefix = $this->prefix . $sep;

		// Bail if already prefixed.
		if ( 0 === strpos( $value, $new_prefix ) ) {
			return $retval;
		}

		// Return prefixed string.
		return $new_prefix . $retval;
	}

	/**
	 * Return the first letters of a string of words with a separator.
	 *
	 * Used primarily to guess at table aliases when none is manually set.
	 *
	 * Applies the following formatting to a string:
	 * - Trim whitespace
	 * - No accents
	 * - No trailing underscores
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The string to abbreviate.
	 * @param string $sep   Default "_".
	 * @return string
	 */
	protected function first_letters( $value = '', $sep = '_' ) {

		// Bail if empty or not a string.
		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		// Default return value.
		$retval = '';

		// Trim spaces off the ends.
		$unspace = trim( $value );

		// Only non-accented table names (avoid truncation).
		$accents = remove_accents( $unspace );

		// Convert to lowercase.
		$lower = strtolower( $accents );

		// Ensure separator is a non-empty string.
		if ( ! is_string( $sep ) || '' === $sep ) {
			$sep = '_';
		}

		// Explode into parts.
		$parts = explode( $sep, $lower );

		// Loop through parts and concatenate the first letters together.
		foreach ( $parts as $part ) {
			$retval .= substr( $part, 0, 1 );
		}

		// Return the result.
		return $retval;
	}
}
