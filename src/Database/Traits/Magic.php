<?php
/**
 * Magic Trait Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Magic Trait provides __get() and __isset() for BerlinDB classes.
 *
 * PHP only invokes magic property methods for inaccessible (protected/private)
 * or non-existent properties. Public properties are read and tested directly
 * by PHP without going through __get() or __isset() at all.
 *
 * Both methods follow the same resolution order:
 *   1. A get_{$key}() method, if one exists and is callable - this enables
 *      virtual properties with no backing storage and lets subclasses override
 *      how a protected property appears externally.
 *   2. A real property with that name, if one exists.
 *   3. null / false if neither exists.
 *
 * @since 3.0.0
 */
trait Magic {

	/**
	 * Magic get().
	 *
	 * Returns the result of get_{$key}() if a method by that name is callable,
	 * otherwise returns the property value if the property exists, otherwise null.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Query variable key.
	 * @return mixed
	 */
	public function __get( $key = '' ) {

		// Method name to try.
		$method = "get_{$key}";

		// Prefer a getter method over direct property access.
		if ( is_callable( array( $this, $method ) ) ) {
			return call_user_func( array( $this, $method ) );
		}

		// Fall back to the property value if it exists.
		if ( property_exists( $this, $key ) ) {
			return $this->{$key};
		}

		// Return null for unknown keys.
		return null;
	}

	/**
	 * Magic isset().
	 *
	 * Returns true if get_{$key}() is callable (virtual property) or if a
	 * property with that name exists, regardless of its value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Query variable key.
	 * @return bool
	 */
	public function __isset( $key = '' ) {

		// Method name to try.
		$method = "get_{$key}";

		// A callable getter makes the property appear to exist.
		if ( is_callable( array( $this, $method ) ) ) {
			return true;
		}

		// Fall back to checking for a real property.
		return property_exists( $this, $key );
	}
}
