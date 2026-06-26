<?php
/**
 * Error trait tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test subject for Error trait behavior.
 *
 * @since 3.0.0
 */
class ErrorTestSubject {

	use \BerlinDB\Database\Traits\Error;

	/**
	 * Public wrapper around protected is_success().
	 *
	 * @since 3.0.0
	 *
	 * @param mixed $result Result to inspect.
	 * @return bool
	 */
	public function check_success( $result = false ): bool {
		return $this->is_success( $result );
	}

	/**
	 * Public wrapper around protected $last_error.
	 *
	 * @since 3.0.0
	 *
	 * @return mixed
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}

/**
 * Tests for the Error trait.
 *
 * @since 3.0.0
 */
class ErrorTest extends TestCase {

	/**
	 * False, null, and zero are failure sentinels.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider failure_sentinel_provider
	 *
	 * @param mixed $value Failure value.
	 */
	public function test_failure_sentinels_are_not_successful( $value ) {
		$subject = new ErrorTestSubject();

		$this->assertFalse( $subject->check_success( $value ) );
		$this->assertFalse( $subject->get_last_error() );
	}

	/**
	 * Values that are not failure sentinels are treated as successful.
	 *
	 * @since 3.0.0
	 *
	 * @dataProvider success_value_provider
	 *
	 * @param mixed $value Successful value.
	 */
	public function test_non_sentinel_values_are_successful( $value ) {
		$subject = new ErrorTestSubject();

		$this->assertTrue( $subject->check_success( $value ) );
		$this->assertFalse( $subject->get_last_error() );
	}

	/**
	 * WP_Error is a failure and is stored as the last error.
	 *
	 * @since 3.0.0
	 */
	public function test_wp_error_is_failure_and_is_stashed() {
		$subject = new ErrorTestSubject();
		$error   = new \WP_Error( 'berlindb_test_error', 'Testing error handling.' );

		$this->assertFalse( $subject->check_success( $error ) );
		$this->assertSame( $error, $subject->get_last_error() );
	}

	/**
	 * Failure sentinel provider.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{mixed}>
	 */
	public function failure_sentinel_provider(): array {
		return array(
			'false' => array( false ),
			'null'  => array( null ),
			'zero'  => array( 0 ),
		);
	}

	/**
	 * Success value provider.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array{mixed}>
	 */
	public function success_value_provider(): array {
		return array(
			'one'          => array( 1 ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
			'object'       => array( (object) array( 'id' => 1 ) ),
		);
	}
}
