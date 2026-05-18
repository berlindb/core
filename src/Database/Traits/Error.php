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
	 * Check if an operation succeeded.
	 *
	 * Note: While "0" or "''" may be the return value of a successful result,
	 *       for the purposes of database queries and this method, it isn't.
	 *       When using this method, take care that your possible results do not
	 *       pass falsy values on success.
	 *
	 * @since 1.0.0
	 * @since 3.0.0 Minor refactor to improve readability.
	 *
	 * @param mixed $result Optional. Default false. Any value to check.
	 * @return bool
	 */
	protected function is_success( $result = false ) {

		// Default return value.
		$retval = false;

		// Non-empty is success.
		if ( ! empty( $result ) ) {
			$retval = true;

			// But Error is still fail, so stash it.
			if ( is_wp_error( $result ) ) {
				$this->last_error = $result;
				$retval           = false;
			}
		}

		// Return the result.
		return (bool) $retval;
	}
}