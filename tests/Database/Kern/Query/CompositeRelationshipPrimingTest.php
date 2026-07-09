<?php
/**
 * Composite (multi-column) relationship priming (#229).
 *
 * Phase 1: the tuple-collection helper - the composite analog of
 * get_local_relationship_key_values() - that gathers the distinct, all-parts-
 * present local key tuples across result items. Exercised directly via reflection
 * because it is not yet wired into the priming path (later phases test behavior).
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

namespace BerlinDB\Tests;

use BerlinDB\Tests\Fixtures\TestQuery;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Tests for the composite relationship-key tuple collection helper.
 *
 * @since 3.1.0
 */
class CompositeRelationshipPrimingTest extends TestCase {

	/** @var TestQuery */
	private static $query;

	/**
	 * Construct a Query once (no table needed - the helper only reads item props).
	 *
	 * @since 3.1.0
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$query = new TestQuery();
	}

	/**
	 * Collect distinct tuples for the given items and key columns.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int,object> $items   Items to read keys from.
	 * @param array<int,string> $columns Local key columns, in order.
	 * @return array<int,array<string,mixed>>
	 */
	private function tuples( array $items, array $columns ): array {
		$method = new \ReflectionMethod( TestQuery::class, 'get_local_relationship_key_tuples' );

		return $method->invoke( self::$query, $items, $columns );
	}

	/**
	 * Hash a single tuple.
	 *
	 * @since 3.1.0
	 *
	 * @param array<string,mixed> $tuple Ordered tuple.
	 * @return string
	 */
	private function hash( array $tuple ): string {
		$method = new \ReflectionMethod( TestQuery::class, 'get_relationship_tuple_hash' );

		return $method->invoke( self::$query, $tuple );
	}

	/**
	 * Test that complete tuples are collected and de-duplicated.
	 *
	 * @since 3.1.0
	 */
	public function test_collects_distinct_complete_tuples() {
		$items = array(
			(object) array(
				'region'  => 5,
				'account' => 7,
			),
			(object) array(
				'region'  => 5,
				'account' => 7,
			),
			(object) array(
				'region'  => 5,
				'account' => 9,
			),
		);

		$this->assertSame(
			array(
				array(
					'region'  => 5,
					'account' => 7,
				),
				array(
					'region'  => 5,
					'account' => 9,
				),
			),
			$this->tuples( $items, array( 'region', 'account' ) )
		);
	}

	/**
	 * Test that a missing or empty key part drops the whole tuple (no relation).
	 *
	 * @since 3.1.0
	 */
	public function test_drops_partial_and_empty_tuples() {
		$items = array(
			// Missing the 'account' part.
			(object) array( 'region' => 5 ),
			// An empty (0) part: is_empty_relationship_key() -> no relation.
			(object) array(
				'region'  => 0,
				'account' => 7,
			),
			// The one complete tuple.
			(object) array(
				'region'  => 8,
				'account' => 3,
			),
		);

		$this->assertSame(
			array(
				array(
					'region'  => 8,
					'account' => 3,
				),
			),
			$this->tuples( $items, array( 'region', 'account' ) )
		);
	}

	/**
	 * Test that non-objects are skipped.
	 *
	 * @since 3.1.0
	 */
	public function test_skips_non_objects() {
		$items = array(
			'not-an-object',
			(object) array(
				'region'  => 1,
				'account' => 2,
			),
		);

		$this->assertSame(
			array(
				array(
					'region'  => 1,
					'account' => 2,
				),
			),
			$this->tuples( $items, array( 'region', 'account' ) )
		);
	}

	/**
	 * Test that the tuple hash separates boundary values that would otherwise merge.
	 *
	 * @since 3.1.0
	 */
	public function test_hash_distinguishes_boundary_tuples() {
		$a = $this->hash(
			array(
				'x' => 1,
				'y' => 23,
			)
		);
		$b = $this->hash(
			array(
				'x' => 12,
				'y' => 3,
			)
		);

		$this->assertNotSame( $a, $b );

		// The same tuple hashes identically (the de-dup contract).
		$this->assertSame(
			$a,
			$this->hash(
				array(
					'x' => 1,
					'y' => 23,
				)
			)
		);

		// Values containing the separator still cannot collide (length-prefixed).
		$this->assertNotSame(
			$this->hash(
				array(
					'x' => '5|1',
					'y' => '7',
				)
			),
			$this->hash(
				array(
					'x' => '5',
					'y' => '1|7',
				)
			)
		);

		// String-cast de-dup: 1 (int) and '1' (string) hash alike.
		$this->assertSame( $this->hash( array( 'x' => 1 ) ), $this->hash( array( 'x' => '1' ) ) );
	}
}
