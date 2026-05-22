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
 * Composes Environment, Error, Magic, and Sanitizer. Provides the global
 * $prefix property, to_array(), set_vars(), apply_prefix(), and first_letters().
 * Magic __get() and __isset() behaviour is delegated to the Magic trait.
 *
 * @since 3.0.0
 */
trait Base {

	use Environment;
	use Error;
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
	 * Maybe append the prefix to string.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Prevents double prefixing.
	 *
	 * @param string $string
	 * @param string $sep
	 * @return string
	 */
	protected function apply_prefix( $string = '', $sep = '_' ) {

		// Bail if not a string.
		if ( ! is_string( $string ) ) {
			return '';
		}

		// Trim spaces off the ends.
		$retval = trim( $string );

		// Bail if no prefix.
		if ( empty( $this->prefix ) ) {
			return $retval;
		}

		// Setup new prefix.
		$new_prefix = $this->prefix . $sep;

		// Bail if already prefixed.
		if ( 0 === strpos( $string, $new_prefix ) ) {
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
	 * @param string $string Default empty string.
	 * @param string $sep    Default "_".
	 * @return string
	 */
	protected function first_letters( $string = '', $sep = '_' ) {

		// Bail if empty or not a string.
		if ( empty( $string ) || ! is_string( $string ) ) {
			return '';
		}

		// Default return value.
		$retval = '';

		// Trim spaces off the ends.
		$unspace = trim( $string );

		// Only non-accented table names (avoid truncation).
		$accents = remove_accents( $unspace );

		// Convert to lowercase.
		$lower = strtolower( $accents );

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
	 * @param array $args
	 */
	protected function set_vars( $args = array() ) {

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
}
