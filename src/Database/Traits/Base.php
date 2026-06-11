<?php
/**
 * Base Custom Database Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       1.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Base Trait provides shared utilities to all BerlinDB classes.
 *
 * Composes Environment, Generator, Log, Error, Magic, and Sanitizer. Provides
 * the global $prefix property, to_array(), set_vars(), apply_prefix(), and
 * first_letters(). Magic __get() and __isset() behaviour is delegated to the
 * Magic trait.
 *
 * @since 3.0.0
 */
trait Base {

	/**
	 * Use these traits.
	 *
	 * @since 3.0.0
	 */
	use Environment;
	use Error;
	use Generator;
	use Log;
	use Magic;
	use Sanitizer;

	/** Global Properties *****************************************************/

	/**
	 * Global prefix used for tables/hooks/cache-groups/etc...
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefix = '';

	/** Public ****************************************************************/

	/**
	 * Converts the object's public properties to an array.
	 *
	 * Only public properties are included. Protected and private properties
	 * are not visible to get_object_vars() when called from a public method.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return get_object_vars( $this );
	}

	/** Protected *************************************************************/

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
	 * Return this object's plugin prefix.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function get_prefix(): string {
		return (string) $this->prefix;
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

	/**
	 * Set class variables from arguments.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $args Array of arguments.
	 */
	protected function set_vars( $args = array() ): void {

		// Bail if empty or not an array.
		if ( empty( $args ) ) {
			return;
		}

		// Cast to an array.
		if ( ! is_array( $args ) ) {
			$args = (array) $args;
		}

		// Set all properties.
		foreach ( $args as $key => $value ) {
			$this->{$key} = $value;
		}
	}

	/**
	 * Merge an arguments value over a set of defaults.
	 *
	 * A dependency-free reimplementation of WordPress's wp_parse_args(): accepts
	 * an array, an object (read via get_object_vars()), or a URL-style query
	 * string (parsed with parse_str()), then merges the result over $defaults.
	 * The WordPress 'wp_parse_str' filter is intentionally not applied.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string, mixed>|object|string $args     Value to parse.
	 * @param array<string, mixed>               $defaults Defaults to merge under $args.
	 * @return array<string, mixed>
	 */
	protected function parse_args( $args = array(), $defaults = array() ): array {

		// Normalize $args to an array.
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed = $args;
		} else {
			$parsed = array();
			parse_str( (string) $args, $parsed );
		}

		// Merge over the defaults when any are provided.
		return ! empty( $defaults )
			? array_merge( $defaults, $parsed )
			: $parsed;
	}
}
