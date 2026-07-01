<?php
/**
 * Result value-object tests.
 *
 * Result is the three-way outcome (applied / deferred / failed) of an apply() or
 * reconcile() attempt. Pure value-object behavior, no database.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Diff\Result;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Diff Result value object.
 *
 * @since 3.1.0
 */
class ResultTest extends TestCase {

	/**
	 * applied() is successful, carries a change count, and has no error.
	 *
	 * @since 3.1.0
	 */
	public function test_applied_is_successful() {
		$result = Result::applied( 3 );

		$this->assertTrue( $result->is_successful() );
		$this->assertFalse( $result->is_deferred() );
		$this->assertFalse( $result->is_failed() );
		$this->assertSame( 3, $result->changes() );
		$this->assertSame( '', $result->error() );
	}

	/**
	 * deferred() is only deferred.
	 *
	 * @since 3.1.0
	 */
	public function test_deferred_is_deferred() {
		$result = Result::deferred();

		$this->assertTrue( $result->is_deferred() );
		$this->assertFalse( $result->is_successful() );
		$this->assertFalse( $result->is_failed() );
		$this->assertSame( 0, $result->changes() );
	}

	/**
	 * failed() is only failed, and carries the error and the applied-before count.
	 *
	 * @since 3.1.0
	 */
	public function test_failed_carries_error_and_count() {
		$result = Result::failed( 'ALTER failed: boom', 2 );

		$this->assertTrue( $result->is_failed() );
		$this->assertFalse( $result->is_successful() );
		$this->assertFalse( $result->is_deferred() );
		$this->assertSame( 'ALTER failed: boom', $result->error() );
		$this->assertSame( 2, $result->changes() );
	}

	/**
	 * The three states are mutually exclusive (exactly one is true).
	 *
	 * @since 3.1.0
	 */
	public function test_states_are_mutually_exclusive() {
		foreach ( array( Result::applied(), Result::deferred(), Result::failed( 'x' ) ) as $result ) {
			$true = (int) $result->is_successful() + (int) $result->is_deferred() + (int) $result->is_failed();

			$this->assertSame( 1, $true );
		}
	}
}
