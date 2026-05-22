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
	 * Returns true for any value except false, null, 0, and WP_Error:
	 * - false    — wpdb query error
	 * - null     — wpdb get_row() found no matching row
	 * - 0        — query ran but matched or affected zero rows
	 * - WP_Error — an explicit error object (also stashed in $last_error)
	 *
	 * If you need different semantics — for example, treating 0 as success
	 * for a DELETE on an empty table — check the result directly instead of
	 * delegating to this method.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 null added as a failure sentinel alongside false.
	 *              WP_Error is now stashed in $last_error.
	 *
	 * @param mixed $result Optional. Default false. Any value to check.
	 * @return bool
	 */
	protected function is_success( $result = false ) {

		// Default return value.
		$retval = false;

		// false (query error), null (no row found), and 0 (nothing matched) are failures.
		if ( ! in_array( $result, array( null, false, 0 ), true ) ) {

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