<?php
/**
 * Database Error Trait.
 *
 * @package     Database
 * @subpackage  Error
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */
declare( strict_types = 1 );

namespace BerlinDB\Database\Traits;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for tracking database errors and success states.
 *
 * @since 3.0.0
 */
trait Error {

	/**
	 * The last database error, if any.
	 *
	 * @since 1.0.0
	 * @var   mixed
	 */
	protected $last_error = false;

	/**
	 * Check if a database operation succeeded.
	 *
	 * Returns true for any value except false, null, and WP_Error:
	 * - false    — wpdb query error
	 * - null     — wpdb get_row() found no matching row
	 * - WP_Error — an explicit error object (also stashed in $last_error)
	 *
	 * Integer 0 is treated as success: it means the query ran cleanly but
	 * affected zero rows (e.g. DELETE on an already-empty table), which is
	 * not an error.
	 *
	 * An empty string is also treated as success: it means the query ran
	 * cleanly but returned an empty value (e.g. a SUM() on no matching rows),
	 * which is not an error.
	 *
	 * If you need to distinguish between these cases, check for them explicitly
	 * before calling this method.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Integer 0 is now treated as success.
	 *              Empty string is now treated as success.
	 *              null added as a failure sentinel alongside false.
	 *
	 * @param mixed $result Optional. Default false. Any value to check.
	 * @return bool
	 */
	protected function is_success( $result = false ) {

		// Default return value.
		$retval = false;

		// null (no row found) and false (query error) are both failures.
		if ( ! in_array( $result, array( null, false ), true ) ) {

			// WP_Error is a failure; stash it for the caller.
			if ( is_wp_error( $result ) ) {
				$this->last_error = $result;

			// Any other value is a success.
			} else {
				$retval = true;
			}
		}

		// Return the result.
		return (bool) $retval;
	}
}