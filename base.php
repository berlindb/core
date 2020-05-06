<?php
/**
 * Base Custom Database Table Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   Copyright (c) 2019
 * @license     https://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0.0
 */
namespace BerlinDB\Database;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * The base class that all other database base classes extend.
 *
 * This class attempts to provide some universal immutability to all other
 * classes that extend it, starting with a magic getter, but likely expanding
 * into a magic call handler and others.
 *
 * @since 1.0.0
 */
class Base {

	/**
	 * The private data array used to store class attributes, so that magic
	 * methods work as intended.
	 *
	 * @since 1.1.0
	 * @var   array
	 */
	private $data = array();

	/**
	 * The name of the PHP global that contains the primary database interface.
	 *
	 * For example, WordPress traditionally uses 'wpdb', but other applications
	 * may use something else, or you may be doing something really cool that
	 * requires a custom interface.
	 *
	 * A future version of this utility may abstract this out entirely, so
	 * custom calls to the get_db() should be avoided if at all possible.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $db_global = 'wpdb';

	/** Global Properties *****************************************************/

	/**
	 * Global prefix used for tables/hooks/cache-groups/etc...
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected $prefix = '';

	/**
	 * The last database error, if any.
	 *
	 * @since 1.0.0
	 * @var   mixed
	 */
	protected $last_error = false;

	/** Public ****************************************************************/

	/**
	 * Magic issetter, for immutability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __isset( $key = '' ) {

		// No more uppercase ID properties ever
		if ( 'ID' === $key ) {
			$key = 'id';
		}

		// Bail if empty key
		if ( empty( $key ) ) {
			return false;
		}

		// Class method to try and call
		$method = "get_{$key}";

		// Return true if method exists
		if ( is_callable( $this, $method ) ) {
			return true;

		// Return true if data exists
		} elseif ( isset( $this->data[ $key ] ) ) {
			return true;

		// Return true if property exists
		} elseif ( property_exists( $this, $key ) ) {
			return true;
		}

		// Return false if not exists
		return false;
	}

	/**
	 * Magic unsetter, for immutability.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key User meta key to unset.
	 */
	public function __unset( $key = '' ) {

		// No more uppercase ID properties ever
		if ( 'ID' === $key ) {
			$key = 'id';
		}

		// Bail if empty key
		if ( empty( $key ) ) {
			return false;
		}

		// Maybe unset from data array
		if ( isset( $this->data[ $key ] ) ) {
			unset( $this->data[ $key ] );
		}

		// Maybe unset property
		if ( property_exists( $this, $key ) ) {
			unset( $this->{$key} );
		}
	}

	/**
	 * Magic setter, for immutability.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __set( $key = '', $value = '' ) {

		// No more uppercase ID properties ever
		if ( 'ID' === $key ) {
			$key = 'id';
		}

		// Bail if empty key
		if ( empty( $key ) ) {
			return false;
		}

		// Set the data value by key
		$this->data[ $key ] = $value;
	}

	/**
	 * Magic getter for immutability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function &__get( $key = '' ) {

		// No more uppercase ID properties ever
		if ( 'ID' === $key ) {
			$key = 'id';
		}

		// Bail if empty key
		if ( empty( $key ) ) {
			return null;
		}

		// Class method to try and call
		$method = "get_{$key}";

		// Return from method if exists
		if ( is_callable( $this, $method ) ) {
			return call_user_func( array( $this, $method ) );

		// Return from data array if set
		} elseif ( isset( $this->data[ $key ] ) ) {
			return $this->data[ $key ];

		// Return from property if set
		} elseif ( property_exists( $this, $key ) ) {
			return $this->{$key};
		}

		// Set to null
		$this->data[ $key ] = null;

		// Return null if not exists
		return $this->data[ $key ];
	}

	/**
	 * Converts the given object to an array.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array version of the given object.
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Get this objects default properties and set them up in the private data
	 * array. Use this in the Constructor in any class that extends this class.
	 *
	 * @since 1.1.0
	 */
	public function set_defaults() {

		// Get the hard-coded object variables
		$r = get_object_vars( $this );

		// Set those vars
		$this->set_vars( $r );

		// Data is private and protected
		unset( $r['data'] );

		// Maybe cleanup
		if ( ! empty( $r ) ) {

			// Get keys
			$keys = array_keys( $r );

			// Cleanup class properties
			foreach ( $keys as $key ) {
				unset( $this->{$key} );
			}
		}
	}

	/** Protected *************************************************************/

	/**
	 * Maybe append the prefix to string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $string
	 * @param string $sep
	 * @return string
	 */
	protected function apply_prefix( $string = '', $sep = '_' ) {
		return ! empty( $this->prefix )
			? "{$this->prefix}{$sep}{$string}"
			: $string;
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
	 * @param string $string
	 * @param string $sep
	 * @return string
	 */
	protected function first_letters( $string = '', $sep = '_' ) {

		// Set empty default return value
		$retval = '';

		// Bail if empty or not a string
		if ( empty( $string ) || ! is_string( $string ) ) {
			return $retval;
		}

		// Trim spaces off the ends
		$unspace = trim( $string );
		$accents = remove_accents( $unspace );
		$lower   = strtolower( $accents );
		$parts   = explode( $sep, $lower );

		// Loop through parts and concatenate the first letters together
		foreach ( $parts as $part ) {
			$retval .= substr( $part, 0, 1 );
		}

		// Return the result
		return $retval;
	}

	/**
	 * Sanitize a table name string.
	 *
	 * Used to make sure that a table name value meets MySQL expectations.
	 *
	 * Applies the following formatting to a string:
	 * - Trim whitespace
	 * - No accents
	 * - No special characters
	 * - No hyphens
	 * - No double underscores
	 * - No trailing underscores
	 *
	 * @since 1.0.0
	 *
	 * @param string $name The name of the database table
	 *
	 * @return string Sanitized database table name
	 */
	protected function sanitize_table_name( $name = '' ) {

		// Bail if empty or not a string
		if ( empty( $name ) || ! is_string( $name ) ) {
			return false;
		}

		// Trim spaces off the ends
		$unspace = trim( $name );

		// Only non-accented table names (avoid truncation)
		$accents = remove_accents( $unspace );

		// Only lowercase characters, hyphens, and dashes (avoid index corruption)
		$lower   = sanitize_key( $accents );

		// Replace hyphens with single underscores
		$under   = str_replace( '-',  '_', $lower );

		// Single underscores only
		$single  = str_replace( '__', '_', $under );

		// Remove trailing underscores
		$clean   = trim( $single, '_' );

		// Bail if table name was garbaged
		if ( empty( $clean ) ) {
			return false;
		}

		// Return the cleaned table name
		return $clean;
	}

	/**
	 * Set class variables from arguments.
	 *
	 * @since 1.0.0
	 * @param array $args
	 */
	protected function set_vars( $args = array() ) {

		// Bail if empty or not an array
		if ( empty( $args ) ) {
			return;
		}

		// Cast to an array
		if ( ! is_array( $args ) ) {
			$args = (array) $args;
		}

		// Set all properties
		foreach ( $args as $key => $value ) {

			// Avoid recusion
			if ( 'data' !== $key ) {
				$this->data[ $key ] = $value;
			}
		}
	}

	/**
	 * Return the global database interface.
	 *
	 * See: https://core.trac.wordpress.org/ticket/31556
	 *
	 * @since 1.0.0
	 *
	 * @return object Database interface, or False if not set
	 */
	protected function get_db() {

		// Default database return value (might change)
		$retval = false;

		// Look for a commonly used global database interface
		if ( isset( $GLOBALS[ $this->db_global ] ) ) {
			$retval = $GLOBALS[ $this->db_global ];
		}

		/*
		 * Developer note:
		 *
		 * It should be impossible for a database table to be interacted with
		 * before the primary database interface it is setup.
		 *
		 * However, because applications are complicated, it is unsafe to assume
		 * anything, so this silently returns false instead of halting everything.
		 *
		 * If you are here because this method is returning false for you, that
		 * means the database table is being invoked too early in the lifecycle
		 * of the application.
		 *
		 * In WordPress, that means before the $wpdb global is created; in other
		 * environments, you will need to adjust accordingly.
		 */

		// Return the database interface
		return $retval;
	}

	/**
	 * Check if an operation succeeded.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result
	 * @return bool
	 */
	protected function is_success( $result = false ) {

		// Bail if no row exists
		if ( empty( $result ) ) {
			$retval = false;

		// Bail if an error occurred
		} elseif ( is_wp_error( $result ) ) {
			$this->last_error = $result;
			$retval           = false;

		// No errors
		} else {
			$retval = true;
		}

		// Return the result
		return (bool) $retval;
	}
}
