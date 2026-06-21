<?php
/**
 * Join clause unit tests.
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Database\Clauses\Join;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Clauses\Join - the sibling of Clauses\Where that flattens the
 * per-parser JOIN fragments (no boolean tree, since JOINs cannot be AND/OR-ed).
 *
 * @since 3.1.0
 */
class JoinTest extends TestCase {

	/**
	 * Test that keyed fragments are flattened to a list in order.
	 *
	 * @since 3.1.0
	 */
	public function test_flattens_keyed_fragments() {
		$join = new Join(
			array(
				'join' => array(
					'relation' => 'INNER JOIN a ON a.id = t.id',
					'meta'     => 'LEFT JOIN b ON b.id = t.id',
				),
			)
		);

		$this->assertSame(
			array( 'INNER JOIN a ON a.id = t.id', 'LEFT JOIN b ON b.id = t.id' ),
			$join->get_clauses()
		);
	}

	/**
	 * Test that no fragments yields an empty list.
	 *
	 * @since 3.1.0
	 */
	public function test_empty_is_empty() {
		$this->assertSame( array(), ( new Join() )->get_clauses() );
		$this->assertSame( array(), ( new Join( array( 'join' => array() ) ) )->get_clauses() );
	}

	/**
	 * Test that non-string keys/values are dropped.
	 *
	 * @since 3.1.0
	 */
	public function test_drops_non_strings() {
		$join = new Join(
			array(
				'join' => array(
					'relation' => 'INNER JOIN a ON a.id = t.id',
					0          => 'JOIN with int key dropped',
					'bad'      => 123,
				),
			)
		);

		$this->assertSame( array( 'INNER JOIN a ON a.id = t.id' ), $join->get_clauses() );
	}
}
