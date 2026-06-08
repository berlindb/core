<?php
/**
 * Generator Helpers.
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
 * Shared value-generation helpers for database-related classes.
 *
 * @since 3.1.0
 */
trait Generator {

	/**
	 * Generate a random URN UUID (v4).
	 *
	 * Uses PHP's native random_int() — a CSPRNG (the same source modern wp_rand()
	 * wraps), guaranteed on PHP 7+ — for unpredictable values without depending on
	 * WordPress, matching generate_random_string()'s use of random_bytes(). Callers
	 * are responsible for deciding *when* to generate (e.g. on insert only); this
	 * method just produces the value.
	 *
	 * @since 3.1.0
	 *
	 * @return string A urn:uuid:-prefixed v4 UUID string.
	 */
	protected function generate_uuid(): string {

		// phpcs:disable PEAR.Functions.FunctionCallSignature.EmptyLine
		return sprintf(
			'urn:uuid:%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

			// 32 bits for "time_low".
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),

			// 16 bits for "time_mid".
			random_int( 0, 0xffff ),

			/*
			 * 16 bits for "time_hi_and_version",
			 * four most significant bits holds version number 4
			 */
			random_int( 0, 0x0fff ) | 0x4000,

			/*
			 * 16 bits, 8 bits for "clk_seq_hi_res",
			 * 8 bits for "clk_seq_low",
			 * two most significant bits holds zero and one for variant DCE1.1
			 */
			random_int( 0, 0x3fff ) | 0x8000,

			// 48 bits for "node".
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
		// phpcs:enable PEAR.Functions.FunctionCallSignature.EmptyLine
	}

	/**
	 * Generate a random hex string, prefixed with the class prefix.
	 *
	 * Prefers random_bytes() when available; falls back to uniqid() on older
	 * environments. Used as the unique sentinel value for query_var_default_value
	 * so that Query can distinguish "not set" from any real query-var value.
	 *
	 * @since 3.1.0
	 *
	 * @return string Prefixed random hex string.
	 */
	protected function generate_random_string(): string {
		$random = function_exists( 'random_bytes' )
			? bin2hex( random_bytes( 18 ) )
			: uniqid( '_', true );

		return $this->apply_prefix( $random );
	}
}
