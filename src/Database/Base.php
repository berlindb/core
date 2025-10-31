<?php
/**
 * Base Custom Database Class.
 *
 * @package     Database
 * @subpackage  Base
 * @copyright   2021-2022 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
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
 *
 * @property array<string, mixed> $args
 */
#[\AllowDynamicProperties]
class Base {

	/**
	 * The name of the PHP global that contains the primary database interface.
	 *
	 * For example, WordPress uses 'wpdb', but other applications will use
	 * something else, or you may be doing something really cool that
	 * requires a custom interface.
	 *
	 * A future version of BerlinDB will abstract this to a new class, so
	 * custom calls to the get_db() in your own code should be avoided.
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
	 * Magic isset'ter for immutability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __isset( $key = '' ) {

		// Class method to try and call
		$method = "get_{$key}";

		// Return callable method exists
		if ( is_callable( array( $this, $method ) ) ) {
			return true;
		}

		// Return property if exists
		return property_exists( $this, $key );
	}

	/**
	 * Magic getter for immutability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __get( $key = '' ) {

		// Class method to try and call
		$method = "get_{$key}";

		// Return get method results if callable
		if ( is_callable( array( $this, $method ) ) ) {
			return call_user_func( array( $this, $method ) );

		// Return property value if exists
		} elseif ( property_exists( $this, $key ) ) {
			return $this->{$key};
		}

		// Return null if not exists
		return null;
	}

	/**
	 * Converts the given object to an array.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array version of the given object.
	 */
	public function to_array() {
		return get_object_vars( $this );
	}

	/** Protected *************************************************************/

	/**
	 * Maybe append the prefix to string.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Prevents double prefixing
	 *
	 * @param string $string
	 * @param string $sep
	 * @return string
	 */
	protected function apply_prefix( $string = '', $sep = '_' ) {

		// Bail if not a string
		if ( ! is_string( $string ) ) {
			return '';
		}

		// Trim spaces off the ends
		$retval = trim( $string );

		// Bail if no prefix
		if ( empty( $this->prefix ) ) {
			return $retval;
		}

		// Setup new prefix
		$new_prefix = $this->prefix . $sep;

		// Bail if already prefixed
		if ( 0 === strpos( $string, $new_prefix ) ) {
			return $retval;
		}

		// Return prefixed string
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

		// Bail if empty or not a string
		if ( empty( $string ) || ! is_string( $string ) ) {
			return '';
		}

		// Default return value
		$retval  = '';

		// Trim spaces off the ends
		$unspace = trim( $string );

		// Only non-accented table names (avoid truncation)
		$accents = remove_accents( $unspace );

		// Convert to lowercase
		$lower   = strtolower( $accents );

		// Explode into parts
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
	 * @since 2.1.0 Allow uppercase letters
	 *
	 * @param string $name The name of the database table
	 *
	 * @return bool|string Sanitized database table name on success, False on error
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

		// Only upper & lower case letters, numbers, hyphens, and underscores
		$replace = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $accents );

		// Replace hyphens with single underscores
		$under   = str_replace( '-',  '_', $replace );

		// Replace double underscores with singles
		$single  = str_replace( '__', '_', $under );

		// Remove trailing underscores
		$clean   = trim( $single, '_' );

		// Bail if table name was garbaged or return the cleaned table name
		return empty( $clean )
			? false
			: $clean;
	}

	/**
	 * Sanitize a column name string.
	 *
	 * Used to make sure that a column name value meets MySQL expectations.
	 *
	 * Applies the following formatting to a string:
	 * - Trim whitespace
	 * - No accents
	 * - No special characters
	 * - No hyphens
	 * - No double underscores
	 * - No trailing underscores
	 *
	 * @since 2.1.0
	 *
	 * @param string $name The name of the database column
	 *
	 * @return bool|string Sanitized database column name on success, False on error
	 */
	protected function sanitize_column_name( $name = '' ) {
		return $this->sanitize_table_name( $name );
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
			$this->{$key} = $value;
		}
	}

	/**
	 * Stash arguments and class variables.
	 *
	 * This is used to stash a copy of the original constructor arguments and
	 * the object variable values, for later comparison, reuse, or resetting
	 * back to a previous state.
	 *
	 * @since 2.1.0
	 * @param array $args
	 */
	protected function stash_args( $args = array() ) {
		$this->args = array(
			'param' => $args,
			'class' => get_object_vars( $this )
		);
	}

	/**
	 * Return the global database interface.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Improved PHP8 support, remove $GLOBALS superglobal usage
	 *
	 * @return bool|\wpdb Database interface, or False if not set
	 */
	protected function get_db() {
		global ${$this->db_global};

		// Default return value
		$retval = false;

		// Look for the global database interface
		if ( ! is_null( ${$this->db_global} ) ) {
			$retval = ${$this->db_global};
		}

		/*
		 * Note: If you are here because this method is returning false for you,
		 * that means a database Table or Query are being invoked too early in
		 * the lifecycle of the application.
		 *
		 * In WordPress, that means before require_wp_db() creates the $wpdb
		 * global (inside of the wp-settings.php file) and you may want to
		 * hook your custom code into 'admin_init' or 'plugins_loaded' instead.
		 *
		 * The decision to return false here is likely to change in the future.
		 */

		// Return the database interface
		return $retval;
	}

	/**
	 * Check if an operation succeeded.
	 *
	 * Note: While "0" or "''" may be the return value of a successful result,
	 *       for the purposes of database queries and this method, it isn't.
	 *       When using this method, take care that your possible results do not
	 *       pass falsy values on success.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Minor refactor to improve readability.
	 *
	 * @param mixed $result Optional. Default false. Any value to check.
	 * @return bool
	 */
	protected function is_success( $result = false ) {

		// Default return value
		$retval = false;

		// Non-empty is success
		if ( ! empty( $result ) ) {
			$retval = true;

			// But Error is still fail, so stash it
			if ( is_wp_error( $result ) ) {
				$this->last_error = $result;
				$retval           = false;
			}
		}

		// Return the result
		return (bool) $retval;
	}
}
